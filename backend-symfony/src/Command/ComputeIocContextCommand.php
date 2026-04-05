<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Communication\IocContextService;
use App\Application\LLM\ContextualEnricher;
use App\Application\LLM\ContextualEnrichmentRequest;
use App\Application\LLM\CostEstimator;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ioc:compute-context',
    description: 'Compute structural context for IOCs without context (backfill + retry)',
)]
final class ComputeIocContextCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly IocContextService $contextService,
        private readonly LoggerInterface $logger,
        private readonly ?ContextualEnricher $contextualEnricher = null,
        private readonly ?CostEstimator $costEstimator = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max messages to process', '500')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Recompute existing context')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview only, no writes')
            ->addOption('with-llm', null, InputOption::VALUE_NONE, 'Also run LLM contextual enrichment for structural IOCs')
            ->addOption('budget-usd', null, InputOption::VALUE_REQUIRED, 'Max LLM budget in USD (stops when exceeded)', '1.00');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limitRaw = $input->getOption('limit');
        $limit = \is_numeric($limitRaw) ? (int) $limitRaw : 500;
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('IOC Context Computation');

        if ($dryRun) {
            $io->note('Dry-run mode: no data will be written.');
        }

        if ($force) {
            $io->note('Force mode: existing context will be recomputed.');
        }

        // Find messages with IOCs that don't have context yet
        $sql = 'SELECT oi.msg_id, COUNT(oi.obs_id) AS ioc_count'
            . ' FROM observed_ioc oi'
            . ' JOIN indicator i ON oi.indicator_id = i.indicator_id';

        if (!$force) {
            $sql .= ' LEFT JOIN ioc_context ic ON oi.obs_id = ic.obs_id'
                . ' WHERE ic.id IS NULL';
        }

        $sql .= ' GROUP BY oi.msg_id'
            . ' ORDER BY MAX(oi.ts_observed) DESC'
            . ' LIMIT ' . $limit;

        $messages = $this->connection->fetchAllAssociative($sql);

        if (empty($messages)) {
            $io->success('No IOCs to process.');

            return Command::SUCCESS;
        }

        $io->info(\sprintf('Found %d messages with IOCs to process.', \count($messages)));

        $processedMessages = 0;
        $processedIocs = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($messages as $msg) {
            $msgId = \is_string($msg['msg_id'] ?? null) ? $msg['msg_id'] : '';

            if ($msgId === '') {
                continue;
            }

            // Get IOCs for this message
            $iocRows = $this->connection->fetchAllAssociative(
                'SELECT oi.obs_id, oi.indicator_id, i.type AS ioc_type'
                . ' FROM observed_ioc oi'
                . ' JOIN indicator i ON oi.indicator_id = i.indicator_id'
                . ' WHERE oi.msg_id = :msgId',
                ['msgId' => $msgId]
            );

            if (empty($iocRows)) {
                continue;
            }

            $obsIocData = [];

            foreach ($iocRows as $row) {
                $obsIocData[] = [
                    'obs_id' => \is_string($row['obs_id'] ?? null) ? $row['obs_id'] : '',
                    'indicator_id' => \is_string($row['indicator_id'] ?? null) ? $row['indicator_id'] : '',
                    'ioc_type' => \is_string($row['ioc_type'] ?? null) ? $row['ioc_type'] : '',
                ];
            }

            if ($dryRun) {
                $io->text(\sprintf(
                    '  [dry-run] Would process message %s (%d IOCs)',
                    substr($msgId, 0, 8),
                    \count($obsIocData)
                ));
                $processedMessages++;
                $processedIocs += \count($obsIocData);

                continue;
            }

            if ($force) {
                // Delete existing context for these IOCs
                foreach ($obsIocData as $ioc) {
                    $this->connection->executeStatement(
                        'DELETE FROM ioc_context WHERE obs_id = :obsId',
                        ['obsId' => $ioc['obs_id']]
                    );
                }
            }

            try {
                $this->contextService->computeAndPersistForMessage($msgId, $obsIocData);
                $processedMessages++;
                $processedIocs += \count($obsIocData);
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('[ComputeIocContext] Failed to process message', [
                    'msg_id' => $msgId,
                    'error' => $e->getMessage(),
                ]);
                $io->warning(\sprintf('Error processing message %s: %s', substr($msgId, 0, 8), $e->getMessage()));
            }
        }

        $io->success(\sprintf(
            'Structural: processed %d messages, %d IOCs%s. Errors: %d.',
            $processedMessages,
            $processedIocs,
            $dryRun ? ' (dry-run)' : '',
            $errors
        ));

        // LLM enrichment phase
        $withLlm = (bool) $input->getOption('with-llm');

        if ($withLlm) {
            $llmErrors = $this->runLlmEnrichment($input, $io, $limit, $dryRun);
            $errors += $llmErrors;
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Run LLM contextual enrichment for IOCs with status='structural'.
     */
    private function runLlmEnrichment(InputInterface $input, SymfonyStyle $io, int $limit, bool $dryRun): int
    {
        if ($this->contextualEnricher === null) {
            $io->warning('ContextualEnricher not available. Skipping LLM enrichment.');

            return 0;
        }

        $budgetRaw = $input->getOption('budget-usd');
        $budgetUsd = \is_numeric($budgetRaw) ? (float) $budgetRaw : 1.00;

        $io->section('LLM Contextual Enrichment');
        $io->info(\sprintf('Budget: $%.2f USD', $budgetUsd));

        // Find messages with structural IOC context
        $msgRows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT oi.msg_id'
            . ' FROM ioc_context ic'
            . ' JOIN observed_ioc oi ON ic.obs_id = oi.obs_id'
            . ' WHERE ic.enrichment_status = \'structural\''
            . ' ORDER BY oi.msg_id'
            . ' LIMIT ' . $limit
        );

        if (empty($msgRows)) {
            $io->success('No IOCs with structural status to enrich.');

            return 0;
        }

        $io->info(\sprintf('Found %d messages with structural IOCs.', \count($msgRows)));

        $enriched = 0;
        $llmErrors = 0;
        $cumulativeCost = 0.0;

        foreach ($msgRows as $msgRow) {
            $msgId = \is_string($msgRow['msg_id'] ?? null) ? $msgRow['msg_id'] : '';

            if ($msgId === '') {
                continue;
            }

            // Check budget
            if ($cumulativeCost >= $budgetUsd) {
                $io->warning(\sprintf('Budget exceeded ($%.4f / $%.2f). Stopping.', $cumulativeCost, $budgetUsd));

                break;
            }

            // Load context rows for this message
            $contextRows = $this->connection->fetchAllAssociative(
                'SELECT ic.id, ic.obs_id, ic.stimulus_msg_id, ic.revelation_turn, ic.total_turns,'
                . ' ic.scam_type_code, ic.persona_code,'
                . ' i.type AS ioc_type'
                . ' FROM ioc_context ic'
                . ' JOIN observed_ioc oi ON ic.obs_id = oi.obs_id'
                . ' JOIN indicator i ON ic.indicator_id = i.indicator_id'
                . ' WHERE oi.msg_id = :msgId'
                . ' AND ic.enrichment_status = \'structural\'',
                ['msgId' => $msgId]
            );

            if (empty($contextRows)) {
                continue;
            }

            $iocTypes = array_values(array_unique(array_filter(array_map(
                fn (array $row) => \is_string($row['ioc_type'] ?? null) ? $row['ioc_type'] : '',
                $contextRows
            ), fn (string $t) => $t !== '')));

            $firstRow = $contextRows[0];
            $scamType = \is_string($firstRow['scam_type_code'] ?? null) ? $firstRow['scam_type_code'] : 'UNKNOWN';
            $personaCode = \is_string($firstRow['persona_code'] ?? null) ? $firstRow['persona_code'] : 'generic_user';
            $revelationTurn = \is_numeric($firstRow['revelation_turn'] ?? null) ? (int) $firstRow['revelation_turn'] : 1;
            $totalTurns = \is_numeric($firstRow['total_turns'] ?? null) ? (int) $firstRow['total_turns'] : 1;

            // Load message text
            $revelationText = $this->connection->fetchOne(
                'SELECT body_text FROM message WHERE msg_id = :msgId AND deleted_at IS NULL',
                ['msgId' => $msgId]
            );
            $revelationText = \is_string($revelationText) ? $revelationText : '';

            // Load stimulus message text
            $stimulusMsgId = \is_string($firstRow['stimulus_msg_id'] ?? null) ? $firstRow['stimulus_msg_id'] : null;
            $stimulusText = null;

            if ($stimulusMsgId !== null) {
                $stimulusText = $this->connection->fetchOne(
                    'SELECT body_text FROM message WHERE msg_id = :msgId AND deleted_at IS NULL',
                    ['msgId' => $stimulusMsgId]
                );
                $stimulusText = \is_string($stimulusText) ? $stimulusText : null;
            }

            if ($dryRun) {
                $io->text(\sprintf(
                    '  [dry-run] Would enrich message %s (%d IOCs, types: %s)',
                    substr($msgId, 0, 8),
                    \count($contextRows),
                    implode(', ', $iocTypes)
                ));
                $enriched++;

                continue;
            }

            $request = new ContextualEnrichmentRequest(
                iocTypes: $iocTypes,
                scamType: $scamType,
                personaCode: $personaCode,
                revelationTurn: $revelationTurn,
                totalTurns: $totalTurns,
                revelationMessageText: $revelationText,
                stimulusMessageText: $stimulusText,
                previousInboundText: null,
            );

            $result = $this->contextualEnricher->enrich($request);

            if ($result === null) {
                $llmErrors++;
                $io->warning(\sprintf('LLM enrichment failed for message %s', substr($msgId, 0, 8)));

                continue;
            }

            // Update rows
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            foreach ($contextRows as $row) {
                $iocType = \is_string($row['ioc_type'] ?? null) ? $row['ioc_type'] : '';
                $semanticRole = $result->iocRoles[$iocType] ?? 'UNKNOWN';

                $this->connection->executeStatement(
                    'UPDATE ioc_context SET'
                    . ' semantic_role = :semanticRole,'
                    . ' stimulus_type = :stimulusType,'
                    . ' urgency_score = :urgencyScore,'
                    . ' language_switch = :languageSwitch,'
                    . ' hesitation_detected = :hesitationDetected,'
                    . ' context_excerpt = :contextExcerpt,'
                    . ' enrichment_confidence = :enrichmentConfidence,'
                    . ' enrichment_status = \'enriched\','
                    . ' computed_at = :computedAt'
                    . ' WHERE id = :id',
                    [
                        'semanticRole' => $semanticRole,
                        'stimulusType' => $result->stimulusType,
                        'urgencyScore' => $result->urgencyScore,
                        'languageSwitch' => $result->languageSwitch ? 'true' : 'false',
                        'hesitationDetected' => $result->hesitationDetected ? 'true' : 'false',
                        'contextExcerpt' => $result->contextExcerpt,
                        'enrichmentConfidence' => $result->enrichmentConfidence,
                        'computedAt' => $now,
                        'id' => $row['id'],
                    ]
                );
            }

            $enriched++;

            // Estimate cost
            if ($this->costEstimator !== null) {
                $approxPromptTokens = (int) ceil(\strlen($revelationText) / 4) + 200;
                $approxCompletionTokens = 125; // ~500 chars JSON output
                $cost = $this->costEstimator->estimate('openai', 'gpt-4o-mini', $approxPromptTokens, $approxCompletionTokens);
                $cumulativeCost += $cost;
            }

            $io->text(\sprintf(
                '  Enriched message %s (%d IOCs, stimulus=%s, cost=$%.4f)',
                substr($msgId, 0, 8),
                \count($contextRows),
                $result->stimulusType,
                $cumulativeCost
            ));
        }

        $io->success(\sprintf(
            'LLM enrichment: %d messages%s. Errors: %d. Cost: $%.4f.',
            $enriched,
            $dryRun ? ' (dry-run)' : '',
            $llmErrors,
            $cumulativeCost
        ));

        return $llmErrors;
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Audit\ConversationQualityAuditor;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Audit conversation data quality using independent LLM analysis.
 *
 * Samples N conversations (proportional across scam types), runs each
 * through ConversationQualityAuditor (gpt-4o, contradictory prompt),
 * and generates a markdown report with agreement rates per dimension.
 */
#[AsCommand(
    name: 'app:audit:conversation-quality',
    description: 'Audit conversation data quality using independent LLM analysis',
)]
final class AuditConversationQualityCommand extends Command
{
    public function __construct(
        private readonly ConversationQualityAuditor $auditor,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sample', null, InputOption::VALUE_REQUIRED, 'Number of conversations to audit', '50')
            ->addOption('scam-type', null, InputOption::VALUE_REQUIRED, 'Filter by scam type (e.g. PHISHING)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count matching conversations only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $sampleRaw */
        $sampleRaw = $input->getOption('sample');
        $sampleSize = max(1, (int) $sampleRaw);

        /** @var string|null $scamTypeFilter */
        $scamTypeFilter = $input->getOption('scam-type');
        $scamTypeFilter = \is_string($scamTypeFilter) && $scamTypeFilter !== '' ? strtoupper($scamTypeFilter) : null;

        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('LLM Conversation Quality Audit');

        $conversationIds = $this->sampleConversations($sampleSize, $scamTypeFilter);

        if ($conversationIds === []) {
            $io->warning('No conversations found matching criteria.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->success(sprintf('Dry run: %d conversations available for audit.', \count($conversationIds)));

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Auditing %d conversations...', \count($conversationIds)));

        /** @var array<string, array{agree: int, disagree: int}> $dimensions */
        $dimensions = [
            'classification' => ['agree' => 0, 'disagree' => 0],
            'ioc_completeness' => ['agree' => 0, 'disagree' => 0],
            'urgency' => ['agree' => 0, 'disagree' => 0],
            'semantic_roles' => ['agree' => 0, 'disagree' => 0],
            'risk_score' => ['agree' => 0, 'disagree' => 0],
        ];

        /** @var array<string, list<array<string, mixed>>> $disagreements */
        $disagreements = [
            'classification' => [],
            'ioc_completeness' => [],
            'urgency' => [],
            'semantic_roles' => [],
            'risk_score' => [],
        ];

        $audited = 0;
        $failed = 0;

        $io->progressStart(\count($conversationIds));

        foreach ($conversationIds as $convId) {
            $result = $this->auditor->audit($convId);

            if ($result === null) {
                ++$failed;
                $io->progressAdvance();

                continue;
            }

            ++$audited;

            foreach (array_keys($dimensions) as $dim) {
                $dimData = $result[$dim] ?? [];
                $dimArray = \is_array($dimData) ? $dimData : [];
                $verdict = \is_string($dimArray['verdict'] ?? null) ? strtoupper($dimArray['verdict']) : 'UNKNOWN';

                if (\in_array($verdict, ['AGREE', 'COMPLETE'], true)) {
                    ++$dimensions[$dim]['agree'];
                } else {
                    ++$dimensions[$dim]['disagree'];
                    $disagreements[$dim][] = array_merge(['conv_id' => $convId], $dimArray);
                }
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        // Console summary
        $io->section('Agreement Summary');

        $tableRows = [];

        foreach ($dimensions as $dim => $counts) {
            $total = $counts['agree'] + $counts['disagree'];
            $rate = $total > 0 ? round($counts['agree'] / $total * 100, 1) : 0.0;
            $tableRows[] = [ucfirst(str_replace('_', ' ', $dim)), $counts['agree'], $counts['disagree'], $rate . '%'];
        }

        $io->table(['Dimension', 'AGREE', 'DISAGREE', 'Agreement Rate'], $tableRows);

        if ($failed > 0) {
            $io->note(sprintf('%d conversations could not be audited (no messages, LLM error, etc.)', $failed));
        }

        // Write markdown report
        $reportPath = $this->writeReport($dimensions, $disagreements, $audited, $failed, $scamTypeFilter);
        $io->success(sprintf('Report written to %s', $reportPath));

        return Command::SUCCESS;
    }

    /**
     * Sample conversation IDs proportionally across scam types.
     *
     * @return list<string>
     */
    private function sampleConversations(int $sampleSize, ?string $scamTypeFilter): array
    {
        if ($scamTypeFilter !== null) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT c.conv_id
                 FROM conversation c
                 LEFT JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
                 WHERE c.deleted_at IS NULL AND st.code = :scamType
                 ORDER BY RANDOM()
                 LIMIT :limit',
                ['scamType' => $scamTypeFilter, 'limit' => $sampleSize],
                ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
            );

            return array_map(static function (array $row): string {
                $v = $row['conv_id'] ?? '';

                return \is_string($v) ? $v : (\is_scalar($v) ? (string) $v : '');
            }, $rows);
        }

        // Proportional sampling: count per scam type, allocate proportionally
        $typeCounts = $this->connection->fetchAllAssociative(
            'SELECT st.code AS scam_type, COUNT(*) AS cnt
             FROM conversation c
             LEFT JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
             WHERE c.deleted_at IS NULL
             GROUP BY st.code
             ORDER BY cnt DESC',
        );

        if ($typeCounts === []) {
            return [];
        }

        $totalConversations = array_sum(array_map(fn (array $r): int => \is_numeric($r['cnt']) ? (int) $r['cnt'] : 0, $typeCounts));

        if ($totalConversations === 0) {
            return [];
        }

        $convIds = [];

        foreach ($typeCounts as $tc) {
            $scamType = \is_string($tc['scam_type'] ?? null) ? $tc['scam_type'] : null;
            $count = \is_numeric($tc['cnt'] ?? null) ? (int) $tc['cnt'] : 0;
            $allocation = max(1, (int) round($sampleSize * $count / $totalConversations));

            if ($scamType !== null) {
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT c.conv_id
                     FROM conversation c
                     LEFT JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
                     WHERE c.deleted_at IS NULL AND st.code = :scamType
                     ORDER BY RANDOM()
                     LIMIT :limit',
                    ['scamType' => $scamType, 'limit' => $allocation],
                    ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
                );
            } else {
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT c.conv_id
                     FROM conversation c
                     WHERE c.deleted_at IS NULL AND c.scam_type_id IS NULL
                     ORDER BY RANDOM()
                     LIMIT :limit',
                    ['limit' => $allocation],
                    ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
                );
            }

            foreach ($rows as $row) {
                $v = $row['conv_id'] ?? '';
                $convIds[] = \is_string($v) ? $v : (\is_scalar($v) ? (string) $v : '');
            }
        }

        // Trim to exact sample size
        return \array_slice($convIds, 0, $sampleSize);
    }

    /**
     * @param array<string, array{agree: int, disagree: int}> $dimensions
     * @param array<string, list<array<string, mixed>>>       $disagreements
     */
    private function writeReport(array $dimensions, array $disagreements, int $audited, int $failed, ?string $scamTypeFilter): string
    {
        $date = date('Y-m-d');
        $totalSample = $audited + $failed;
        $filterNote = $scamTypeFilter !== null ? " (filtered: {$scamTypeFilter})" : '';

        $md = "# LLM Quality Audit Report\n";
        $md .= "**Date**: {$date}\n";
        $md .= "**Sample**: {$totalSample} conversations ({$audited} audited, {$failed} skipped){$filterNote}\n";
        $md .= "**Model**: gpt-4o (auditor, independent from enrichment pipeline)\n\n";

        $md .= "## Agreement Summary\n";
        $md .= "| Dimension | AGREE | DISAGREE | Agreement Rate |\n";
        $md .= "|-----------|-------|----------|----------------|\n";

        foreach ($dimensions as $dim => $counts) {
            $total = $counts['agree'] + $counts['disagree'];
            $rate = $total > 0 ? round($counts['agree'] / $total * 100, 1) : 0.0;
            $label = ucfirst(str_replace('_', ' ', $dim));
            $md .= "| {$label} | {$counts['agree']} | {$counts['disagree']} | {$rate}% |\n";
        }

        $md .= "\n## Top Disagreements\n";

        // Classification disagreements
        if ($disagreements['classification'] !== []) {
            $md .= "\n### Classification disagreements\n";

            foreach ($disagreements['classification'] as $d) {
                $convId = \is_string($d['conv_id'] ?? null) ? $d['conv_id'] : 'unknown';
                $assigned = \is_string($d['assigned'] ?? null) ? $d['assigned'] : 'N/A';
                $suggested = \is_string($d['suggested'] ?? null) ? $d['suggested'] : 'N/A';
                $reasoning = \is_string($d['reasoning'] ?? null) ? $d['reasoning'] : 'N/A';
                $md .= "- **{$convId}**: assigned={$assigned}, suggested={$suggested} -- {$reasoning}\n";
            }
        }

        // Missed IOCs
        if ($disagreements['ioc_completeness'] !== []) {
            $md .= "\n### Missed IOCs\n";

            foreach ($disagreements['ioc_completeness'] as $d) {
                $convId = \is_string($d['conv_id'] ?? null) ? $d['conv_id'] : 'unknown';
                $missed = \is_array($d['missed_iocs'] ?? null) ? implode(', ', array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '', $d['missed_iocs'])) : 'N/A';
                $reasoning = \is_string($d['reasoning'] ?? null) ? $d['reasoning'] : 'N/A';
                $md .= "- **{$convId}**: missed=[{$missed}] -- {$reasoning}\n";
            }
        }

        // Urgency disagreements
        if ($disagreements['urgency'] !== []) {
            $md .= "\n### Urgency disagreements\n";

            foreach ($disagreements['urgency'] as $d) {
                $convId = \is_string($d['conv_id'] ?? null) ? $d['conv_id'] : 'unknown';
                $assigned = \is_scalar($d['assigned_score'] ?? null) ? (string) $d['assigned_score'] : 'N/A';
                $suggested = \is_scalar($d['suggested_score'] ?? null) ? (string) $d['suggested_score'] : 'N/A';
                $reasoning = \is_string($d['reasoning'] ?? null) ? $d['reasoning'] : 'N/A';
                $md .= "- **{$convId}**: assigned={$assigned}, suggested={$suggested} -- {$reasoning}\n";
            }
        }

        // Semantic role disagreements
        if ($disagreements['semantic_roles'] !== []) {
            $md .= "\n### Semantic role disagreements\n";

            foreach ($disagreements['semantic_roles'] as $d) {
                $convId = \is_string($d['conv_id'] ?? null) ? $d['conv_id'] : 'unknown';
                $issues = \is_array($d['issues'] ?? null) ? implode('; ', array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '', $d['issues'])) : 'N/A';
                $reasoning = \is_string($d['reasoning'] ?? null) ? $d['reasoning'] : 'N/A';
                $md .= "- **{$convId}**: issues=[{$issues}] -- {$reasoning}\n";
            }
        }

        // Risk score disagreements
        if ($disagreements['risk_score'] !== []) {
            $md .= "\n### Risk score disagreements\n";

            foreach ($disagreements['risk_score'] as $d) {
                $convId = \is_string($d['conv_id'] ?? null) ? $d['conv_id'] : 'unknown';
                $assigned = \is_scalar($d['assigned'] ?? null) ? (string) $d['assigned'] : 'N/A';
                $suggested = \is_scalar($d['suggested'] ?? null) ? (string) $d['suggested'] : 'N/A';
                $reasoning = \is_string($d['reasoning'] ?? null) ? $d['reasoning'] : 'N/A';
                $md .= "- **{$convId}**: assigned={$assigned}, suggested={$suggested} -- {$reasoning}\n";
            }
        }

        $dir = \dirname(__DIR__, 3) . '/var/audit-results';

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $path = $dir . '/llm-quality-audit.md';
        file_put_contents($path, $md);

        return 'var/audit-results/llm-quality-audit.md';
    }
}

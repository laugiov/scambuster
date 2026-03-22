<?php

declare(strict_types=1);

namespace App\UI\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'scambuster:demo:load',
    description: 'Load demo dataset from scambuster-dataset-sample.json'
)]
class LoadDemoDataCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('purge', null, InputOption::VALUE_NONE, 'Delete existing conversations before loading');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('ScamBuster Demo Data Loader');

        $file = $this->projectDir . '/scambuster-dataset-sample.json';
        if (!file_exists($file)) {
            $io->error("Dataset not found: {$file}");

            return Command::FAILURE;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            $io->error('Could not read dataset file.');

            return Command::FAILURE;
        }

        /** @var array{conversations: list<array<string, mixed>>} $dataset */
        $dataset = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $conversations = $dataset['conversations'] ?? [];

        if (empty($conversations)) {
            $io->warning('No conversations found in dataset.');

            return Command::SUCCESS;
        }

        // Load reference data lookups
        $scamTypes = $this->loadLookup('lkp_scam_type', 'code', 'scam_type_id');
        $personas = $this->loadLookup('persona', 'persona_code', 'persona_id');
        $directions = $this->loadLookup('lkp_direction', 'code', 'dir_id');
        $channelId = (int) $this->connection->fetchOne('SELECT channel_id FROM lkp_channel WHERE code = ?', ['email']);
        $accountId = $this->connection->fetchOne('SELECT account_id FROM mail_account LIMIT 1');

        if (!$channelId || !$accountId) {
            $io->error('Reference data missing. Run "make fixtures-dev" first.');

            return Command::FAILURE;
        }

        if ($input->getOption('purge')) {
            $io->warning('Purging existing demo conversations...');
            $this->connection->executeStatement("DELETE FROM conversation WHERE stix_id LIKE 'demo-%'");
        }

        $convCount = 0;
        $msgCount = 0;
        $iocCount = 0;

        $this->connection->beginTransaction();

        try {
            foreach ($conversations as $conv) {
                $convId = $conv['conversation_id'] ?? bin2hex(random_bytes(16));
                $scamCode = $conv['scam_type'] ?? 'unknown';
                $personaCode = $conv['persona'] ?? 'generic_user';

                $scamTypeId = $scamTypes[strtoupper($scamCode)] ?? $scamTypes[$scamCode] ?? $scamTypes['unknown'] ?? $scamTypes['UNKNOWN'] ?? 1;
                $personaId = $personas[$personaCode] ?? null;
                $dirIn = $directions['in'] ?? 1;
                $dirOut = $directions['out'] ?? 2;

                // Check if conversation already exists
                $exists = $this->connection->fetchOne(
                    'SELECT 1 FROM conversation WHERE conv_id = ?',
                    [$convId]
                );
                if ($exists) {
                    continue;
                }

                $stixId = 'demo-' . substr($convId, 0, 32);
                $tsFirst = $conv['messages'][0]['timestamp'] ?? '2026-01-01 00:00:00';
                $tsLast = end($conv['messages'])['timestamp'] ?? $tsFirst;

                $this->connection->insert('conversation', [
                    'conv_id' => $convId,
                    'primary_channel_id' => $channelId,
                    'scam_type_id' => $scamTypeId,
                    'account_id' => $accountId,
                    'persona_id' => $personaId,
                    'status' => 'closed',
                    'score_risk' => $conv['risk_score'] ?? 50,
                    'ts_first' => $tsFirst,
                    'ts_last' => $tsLast,
                    'stix_id' => $stixId,
                    'engagement_duration_sec' => $conv['engagement_duration_sec'] ?? 0,
                    'turns_count' => $conv['turns'] ?? 0,
                    'reward_value' => null,
                    'delivery' => 'api',
                    'tlp' => 'AMBER',
                    'created_at' => $tsFirst,
                    'updated_at' => $tsLast,
                ]);
                ++$convCount;

                // Insert messages
                foreach ($conv['messages'] ?? [] as $i => $msg) {
                    $msgId = sprintf(
                        '%s-%04d-0000-0000-%012d',
                        substr($convId, 0, 8),
                        $i,
                        $convCount
                    );
                    $direction = ($msg['direction'] ?? $msg['role'] ?? 'inbound') === 'inbound'
                        || ($msg['role'] ?? '') === 'scammer'
                        ? $dirIn : $dirOut;

                    $this->connection->insert('message', [
                        'msg_id' => $msgId,
                        'conv_id' => $convId,
                        'channel_id' => $channelId,
                        'direction' => $direction,
                        'lang_detect' => 'en',
                        'subject' => $msg['subject'] ?? null,
                        'body_text' => $msg['body'] ?? '',
                        'headers' => '{}',
                        'composite_hash' => hash('sha256', $convId . $i),
                        'ts_msg' => $msg['timestamp'] ?? $tsFirst,
                        'ts_ingest' => $msg['timestamp'] ?? $tsFirst,
                    ]);
                    ++$msgCount;

                    // Insert IOCs
                    foreach ($msg['iocs_extracted'] ?? [] as $j => $ioc) {
                        $obsId = sprintf(
                            '%s-%04d-%04d-0000-%012d',
                            substr($convId, 0, 8),
                            $i,
                            $j,
                            $iocCount
                        );
                        $indicatorId = md5(($ioc['type'] ?? '') . ':' . ($ioc['value'] ?? ''));
                        $indicatorId = substr($indicatorId, 0, 8) . '-'
                            . substr($indicatorId, 8, 4) . '-'
                            . substr($indicatorId, 12, 4) . '-'
                            . substr($indicatorId, 16, 4) . '-'
                            . substr($indicatorId, 20, 12);

                        $context = json_encode([
                            'type' => $ioc['type'] ?? 'unknown',
                            'value' => $ioc['value'] ?? '',
                            'value_norm' => strtolower($ioc['value'] ?? ''),
                            'category' => $scamCode,
                            'source' => 'demo-dataset',
                        ], JSON_THROW_ON_ERROR);

                        $this->connection->insert('observed_ioc', [
                            'obs_id' => $obsId,
                            'msg_id' => $msgId,
                            'indicator_id' => $indicatorId,
                            'context_observation' => $context,
                            'ts_observed' => $msg['timestamp'] ?? $tsFirst,
                        ]);
                        ++$iocCount;
                    }
                }
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $io->error('Failed to load demo data: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Loaded %d conversations, %d messages, %d IOCs.',
            $convCount,
            $msgCount,
            $iocCount
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function loadLookup(string $table, string $codeCol, string $idCol): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT {$codeCol}, {$idCol} FROM {$table}"
        );

        $lookup = [];
        foreach ($rows as $row) {
            $lookup[(string) $row[$codeCol]] = (int) $row[$idCol];
        }

        return $lookup;
    }
}

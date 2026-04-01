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

/**
 * Load production-quality demo dataset from scambuster-dataset-sample.json.
 *
 * Populates ALL tables needed for every ScamBuster screen:
 * conversations, messages (with pipeline traces + injection analysis),
 * IOCs, LLM usage, persona performance stats, convergence logs, campaigns.
 */
#[AsCommand(
    name: 'scambuster:demo:load',
    description: 'Load demo dataset (150 conversations, all screens populated)'
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
        $this->addOption('purge', null, InputOption::VALUE_NONE, 'Delete existing demo data before loading');
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

        /** @var array<string, mixed> $dataset */
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
            $io->warning('Purging existing demo data...');
            $this->connection->executeStatement("DELETE FROM message_campaign WHERE detected_by = 'demo-dataset'");
            $this->connection->executeStatement("DELETE FROM campaign_rule WHERE campaign_id IN (SELECT campaign_id FROM campaign WHERE created_by = 'demo-dataset')");
            $this->connection->executeStatement("DELETE FROM campaign WHERE created_by = 'demo-dataset'");
            $this->connection->executeStatement("DELETE FROM llm_usage WHERE conversation_id IN (SELECT conv_id::text FROM conversation WHERE stix_id LIKE 'demo-%')");
            $this->connection->executeStatement("DELETE FROM bandit_convergence_log WHERE scam_type_code LIKE '%'");
            $this->connection->executeStatement("DELETE FROM conversation WHERE stix_id LIKE 'demo-%'");
        }

        $counts = ['conv' => 0, 'msg' => 0, 'ioc' => 0, 'llm' => 0, 'perf' => 0, 'convergence' => 0, 'campaign' => 0, 'campaign_msg' => 0];

        $this->connection->beginTransaction();

        try {
            // ─── 1. Conversations + Messages + IOCs ───
            $msgIdMap = []; // convId:msgIndex => msgId (for campaign linking)

            foreach ($conversations as $conv) {
                $convId = $conv['conversation_id'] ?? bin2hex(random_bytes(16));
                $scamCode = $conv['scam_type'] ?? 'unknown';
                $personaCode = $conv['persona'] ?? 'generic_user';
                $status = $conv['status'] ?? 'closed';

                $scamTypeId = $scamTypes[strtoupper($scamCode)] ?? $scamTypes[$scamCode] ?? $scamTypes['unknown'] ?? $scamTypes['UNKNOWN'] ?? 1;
                $personaId = $personas[$personaCode] ?? null;
                $dirIn = $directions['in'] ?? 1;
                $dirOut = $directions['out'] ?? 2;

                // Skip if already exists
                if ($this->connection->fetchOne('SELECT 1 FROM conversation WHERE conv_id = ?', [$convId])) {
                    continue;
                }

                $stixId = 'demo-' . substr((string) $convId, 0, 32);
                $convMessages = $conv['messages'] ?? [];
                $tsFirst = $convMessages[0]['timestamp'] ?? '2026-01-01 00:00:00';
                $lastMsg = end($convMessages);
                $tsLast = $lastMsg !== false ? ($lastMsg['timestamp'] ?? $tsFirst) : $tsFirst;

                $this->connection->insert('conversation', [
                    'conv_id' => $convId,
                    'primary_channel_id' => $channelId,
                    'scam_type_id' => $scamTypeId,
                    'account_id' => $accountId,
                    'persona_id' => $personaId,
                    'status' => $status,
                    'score_risk' => $conv['risk_score'] ?? 50,
                    'ts_first' => $tsFirst,
                    'ts_last' => $tsLast,
                    'stix_id' => $stixId,
                    'engagement_duration_sec' => $conv['engagement_duration_sec'] ?? 0,
                    'turns_count' => $conv['turns'] ?? 0,
                    'reward_value' => $conv['reward_value'] ?? null,
                    'delivery' => 'api',
                    'tlp' => 'AMBER',
                    'created_at' => $tsFirst,
                    'updated_at' => $tsLast,
                ]);
                $counts['conv']++;

                // Messages
                foreach ($convMessages as $i => $msg) {
                    $msgId = $this->generateUuid();
                    $msgIdMap[$convId . ':' . $i] = $msgId;

                    $isInbound = ($msg['direction'] ?? 'inbound') === 'inbound';
                    $direction = $isInbound ? $dirIn : $dirOut;

                    // Build headers JSON (with pipeline_trace for outbound)
                    $headers = [];
                    if (!$isInbound && isset($msg['pipeline_trace'])) {
                        $headers['pipeline_trace'] = $msg['pipeline_trace'];
                        $headers['send_status'] = 'sent';
                        $headers['ts_sent'] = $msg['timestamp'];
                    }

                    // Injection analysis for inbound
                    $injectionAnalysis = null;
                    if ($isInbound && isset($msg['injection_analysis'])) {
                        $injectionAnalysis = json_encode($msg['injection_analysis'], JSON_THROW_ON_ERROR);
                    }

                    $insertData = [
                        'msg_id' => $msgId,
                        'conv_id' => $convId,
                        'channel_id' => $channelId,
                        'direction' => $direction,
                        'lang_detect' => 'en',
                        'subject' => $msg['subject'] ?? null,
                        'body_text' => $msg['body'] ?? '',
                        'headers' => json_encode($headers, JSON_THROW_ON_ERROR),
                        'composite_hash' => hash('sha256', $convId . $i),
                        'ts_msg' => $msg['timestamp'] ?? $tsFirst,
                        'ts_ingest' => $msg['timestamp'] ?? $tsFirst,
                    ];

                    if ($injectionAnalysis !== null) {
                        $insertData['injection_analysis'] = $injectionAnalysis;
                    }

                    $this->connection->insert('message', $insertData);
                    $counts['msg']++;

                    // IOCs (inbound only)
                    foreach ($msg['iocs_extracted'] ?? [] as $j => $ioc) {
                        $obsId = $this->generateUuid();
                        $iocType = $ioc['type'] ?? 'unknown';
                        $iocValue = $ioc['value'] ?? '';
                        $indicatorId = $this->deterministicUuid($iocType . ':' . $iocValue);

                        $this->connection->insert('observed_ioc', [
                            'obs_id' => $obsId,
                            'msg_id' => $msgId,
                            'indicator_id' => $indicatorId,
                            'context_observation' => json_encode([
                                'type' => $iocType,
                                'value' => $iocValue,
                                'value_norm' => strtolower($iocValue),
                                'category' => $scamCode,
                                'source' => 'demo-dataset',
                            ], JSON_THROW_ON_ERROR),
                            'confidence_score' => round(random_int(70, 100) / 100, 3),
                            'ts_observed' => $msg['timestamp'] ?? $tsFirst,
                        ]);
                        $counts['ioc']++;
                    }
                }
            }

            // ─── 2. LLM Usage ───
            foreach ($dataset['llm_usage'] ?? [] as $usage) {
                $this->connection->insert('llm_usage', [
                    'conversation_id' => $usage['conversation_id'],
                    'provider' => $usage['provider'],
                    'model' => $usage['model'],
                    'purpose' => $usage['purpose'],
                    'prompt_tokens' => $usage['prompt_tokens'],
                    'completion_tokens' => $usage['completion_tokens'],
                    'estimated_cost_usd' => $usage['estimated_cost_usd'],
                    'created_at' => $usage['created_at'],
                ]);
                $counts['llm']++;
            }

            // ─── 3. Persona Performance Stats (UPSERT) ───
            foreach ($dataset['persona_performance_stats'] ?? [] as $stat) {
                $personaId = $personas[$stat['persona_code']] ?? null;
                $scamTypeId = $scamTypes[$stat['scam_type_code']] ?? null;
                if (!$personaId || !$scamTypeId) continue;

                // Delete existing then insert (simple upsert)
                $this->connection->executeStatement(
                    'DELETE FROM persona_performance_stats WHERE persona_id = ? AND scam_type_id = ?',
                    [$personaId, $scamTypeId]
                );
                $this->connection->insert('persona_performance_stats', [
                    'persona_id' => $personaId,
                    'scam_type_id' => $scamTypeId,
                    'sessions_count' => $stat['sessions_count'],
                    'reward_sum' => $stat['reward_sum'],
                    'reward_avg' => $stat['reward_avg'],
                    'last_updated' => date('Y-m-d H:i:s'),
                ]);
                $counts['perf']++;
            }

            // ─── 4. Convergence Logs ───
            foreach ($dataset['convergence_logs'] ?? [] as $log) {
                $this->connection->insert('bandit_convergence_log', [
                    'scam_type_code' => $log['scam_type_code'],
                    'dominant_persona_code' => $log['dominant_persona_code'],
                    'dominant_pct' => $log['dominant_pct'],
                    'sessions_count' => $log['sessions_count'],
                    'converged' => $log['converged'] ? 'true' : 'false',
                    'logged_at' => $log['logged_at'],
                ]);
                $counts['convergence']++;
            }

            // ─── 5. Campaigns ───
            foreach ($dataset['campaigns'] ?? [] as $campaign) {
                $this->connection->insert('campaign', [
                    'campaign_id' => $campaign['campaign_id'],
                    'first_seen' => $campaign['matched_messages'][0]['timestamp'] ?? date('Y-m-d H:i:s'),
                    'status' => $campaign['status'],
                    'actor_guess' => $campaign['actor_guess'] ?? null,
                    'tlp' => $campaign['tlp'] ?? 'AMBER',
                    'severity' => $campaign['severity'],
                    'dsl_hash' => $campaign['dsl_hash'],
                    'created_by' => 'demo-dataset',
                    'notes' => $campaign['name'],
                    'profile_yaml' => $campaign['profile_yaml'] ?? null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $counts['campaign']++;

                // Campaign rules
                foreach ($campaign['rules'] ?? [] as $rule) {
                    $this->connection->insert('campaign_rule', [
                        'rule_id' => $rule['rule_id'],
                        'campaign_id' => $campaign['campaign_id'],
                        'version' => 1,
                        'dsl' => $rule['dsl'],
                        'compiled_sql' => json_encode(['sql' => $rule['compiled_sql'] ?? ''], JSON_THROW_ON_ERROR),
                        'ppv' => $rule['ppv'],
                        'hits_total' => $rule['hits_total'],
                        'hits_true_pos' => $rule['hits_true_pos'],
                        'hits_false_pos' => $rule['hits_false_pos'],
                        'lead_time_sec' => $rule['lead_time_sec'] ?? 0,
                        'promoted_at' => $rule['promoted_at'],
                        'enabled' => $rule['enabled'] ? 'true' : 'false',
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }

                // Message-campaign links
                foreach ($campaign['matched_messages'] ?? [] as $match) {
                    $msgKey = $match['conv_id'] . ':' . $match['msg_index'];
                    $msgId = $msgIdMap[$msgKey] ?? null;
                    if (!$msgId) continue;

                    $this->connection->insert('message_campaign', [
                        'msg_id' => $msgId,
                        'campaign_id' => $campaign['campaign_id'],
                        'confidence' => round(random_int(75, 98) / 100, 4),
                        'detected_at' => $match['timestamp'],
                        'detected_by' => 'demo-dataset',
                        'features' => json_encode(['domain_match' => true, 'ioc_overlap' => random_int(2, 5)], JSON_THROW_ON_ERROR),
                        'is_true_positive' => true,
                    ]);
                    $counts['campaign_msg']++;
                }
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $io->error('Failed to load demo data: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            "Loaded: %d conversations, %d messages, %d IOCs, %d LLM records, %d perf stats, %d convergence logs, %d campaigns (%d message links).",
            $counts['conv'], $counts['msg'], $counts['ioc'], $counts['llm'],
            $counts['perf'], $counts['convergence'], $counts['campaign'], $counts['campaign_msg']
        ));

        return Command::SUCCESS;
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            random_int(0, 0xFFFFFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0x0FFF) | 0x4000,
            random_int(0, 0x3FFF) | 0x8000,
            random_int(0, 0xFFFFFFFFFFFF)
        );
    }

    /** Generate a deterministic UUID from a string (for IOC indicator_id dedup) */
    private function deterministicUuid(string $input): string
    {
        $hash = md5($input);
        // Set version 4 bits and variant bits
        $hash[12] = '4';
        $hash[16] = dechex(hexdec($hash[16]) & 0x3 | 0x8);
        return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-'
            . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
    }

    /** @return array<string, int> */
    private function loadLookup(string $table, string $codeCol, string $idCol): array
    {
        $rows = $this->connection->fetchAllAssociative("SELECT {$codeCol}, {$idCol} FROM {$table}");
        $lookup = [];
        foreach ($rows as $row) {
            $lookup[(string) ($row[$codeCol] ?? '')] = (int) ($row[$idCol] ?? 0);
        }
        return $lookup;
    }
}

<?php

/** @phpstan-ignore-file — Data loader with JSON-decoded mixed arrays; strict typing impractical here. */

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
 *
 * @phpstan-type ConvData array{conversation_id: string, scam_type: string, persona: string, status: string, risk_score: int, turns: int, engagement_duration_sec: int, reward_value: ?float, messages: list<array<string, mixed>>}
 * @phpstan-type LlmData array{conversation_id: string, provider: string, model: string, purpose: string, prompt_tokens: int, completion_tokens: int, estimated_cost_usd: float, created_at: string}
 * @phpstan-type PerfData array{persona_code: string, scam_type_code: string, sessions_count: int, reward_sum: float, reward_avg: float}
 * @phpstan-type LogData array{scam_type_code: string, dominant_persona_code: string, dominant_pct: float, sessions_count: int, converged: bool, logged_at: string}
 * @phpstan-type CampaignData array{campaign_id: string, name: string, status: string, severity: int, actor_guess: string, tlp: string, dsl_hash: string, profile_yaml: string, rules: list<array<string, mixed>>, matched_messages: list<array<string, mixed>>}
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

        /** @var array{conversations: list<array<string, mixed>>, llm_usage?: list<array<string, mixed>>, persona_performance_stats?: list<array<string, mixed>>, convergence_logs?: list<array<string, mixed>>, campaigns?: list<array<string, mixed>>} $dataset */
        $dataset = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        /** @var list<array<string, mixed>> $conversations */
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
                /** @var array<string, mixed> $conv */
                $convId = (string) ($conv['conversation_id'] ?? bin2hex(random_bytes(16)));
                $scamCode = (string) ($conv['scam_type'] ?? 'unknown');
                $personaCode = (string) ($conv['persona'] ?? 'generic_user');
                $status = (string) ($conv['status'] ?? 'closed');

                $scamTypeId = $scamTypes[strtoupper($scamCode)] ?? $scamTypes[$scamCode] ?? $scamTypes['unknown'] ?? $scamTypes['UNKNOWN'] ?? 1;
                $personaId = $personas[$personaCode] ?? null;
                $dirIn = $directions['in'] ?? 1;
                $dirOut = $directions['out'] ?? 2;

                // Skip if already exists
                if ($this->connection->fetchOne('SELECT 1 FROM conversation WHERE conv_id = ?', [$convId])) {
                    continue;
                }

                $stixId = 'demo-' . substr($convId, 0, 32);
                /** @var list<array<string, mixed>> $convMessages */
                $convMessages = (array) ($conv['messages'] ?? []);
                $tsFirst = (string) ($convMessages[0]['timestamp'] ?? '2026-01-01 00:00:00');
                $lastMsg = end($convMessages);
                $tsLast = $lastMsg !== false ? (string) ($lastMsg['timestamp'] ?? $tsFirst) : $tsFirst;

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
                    /** @var array<string, mixed> $msg */
                    $msgId = $this->generateUuid();
                    $msgIdMap[$convId . ':' . $i] = $msgId;

                    $isInbound = ((string) ($msg['direction'] ?? 'inbound')) === 'inbound';
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

                    $msgTimestamp = (string) ($msg['timestamp'] ?? $tsFirst);
                    $insertData = [
                        'msg_id' => $msgId,
                        'conv_id' => $convId,
                        'channel_id' => $channelId,
                        'direction' => $direction,
                        'lang_detect' => 'en',
                        'subject' => isset($msg['subject']) ? (string) $msg['subject'] : null,
                        'body_text' => (string) ($msg['body'] ?? ''),
                        'headers' => json_encode($headers, JSON_THROW_ON_ERROR),
                        'composite_hash' => hash('sha256', $convId . $i),
                        'ts_msg' => $msgTimestamp,
                        'ts_ingest' => $msgTimestamp,
                    ];

                    if ($injectionAnalysis !== null) {
                        $insertData['injection_analysis'] = $injectionAnalysis;
                    }

                    $this->connection->insert('message', $insertData);
                    $counts['msg']++;

                    // IOCs (inbound only)
                    /** @var list<array<string, mixed>> $iocsExtracted */
                    $iocsExtracted = (array) ($msg['iocs_extracted'] ?? []);
                    foreach ($iocsExtracted as $j => $ioc) {
                        /** @var array<string, mixed> $ioc */
                        $obsId = $this->generateUuid();
                        $iocType = (string) ($ioc['type'] ?? 'unknown');
                        $iocValue = (string) ($ioc['value'] ?? '');
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
                            'ts_observed' => $msgTimestamp,
                        ]);
                        $counts['ioc']++;
                    }
                }
            }

            // ─── 2. LLM Usage ───
            /** @var list<array<string, mixed>> $llmRecords */
            $llmRecords = (array) ($dataset['llm_usage'] ?? []);
            foreach ($llmRecords as $usage) {
                /** @var array<string, mixed> $usage */
                $this->connection->insert('llm_usage', [
                    'conversation_id' => (string) ($usage['conversation_id'] ?? ''),
                    'provider' => (string) ($usage['provider'] ?? 'openai'),
                    'model' => (string) ($usage['model'] ?? 'gpt-4o-mini'),
                    'purpose' => (string) ($usage['purpose'] ?? 'reply_generation'),
                    'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                    'estimated_cost_usd' => (float) ($usage['estimated_cost_usd'] ?? 0),
                    'created_at' => (string) ($usage['created_at'] ?? date('Y-m-d H:i:s')),
                ]);
                $counts['llm']++;
            }

            // ─── 3. Persona Performance Stats (UPSERT) ───
            /** @var list<array<string, mixed>> $perfRecords */
            $perfRecords = (array) ($dataset['persona_performance_stats'] ?? []);
            foreach ($perfRecords as $stat) {
                /** @var array<string, mixed> $stat */
                $personaId = $personas[(string) ($stat['persona_code'] ?? '')] ?? null;
                $scamTypeId = $scamTypes[(string) ($stat['scam_type_code'] ?? '')] ?? null;

                if (!$personaId || !$scamTypeId) {
                    continue;
                }

                $this->connection->executeStatement(
                    'DELETE FROM persona_performance_stats WHERE persona_id = ? AND scam_type_id = ?',
                    [$personaId, $scamTypeId]
                );
                $this->connection->insert('persona_performance_stats', [
                    'persona_id' => $personaId,
                    'scam_type_id' => $scamTypeId,
                    'sessions_count' => (int) ($stat['sessions_count'] ?? 0),
                    'reward_sum' => (float) ($stat['reward_sum'] ?? 0),
                    'reward_avg' => (float) ($stat['reward_avg'] ?? 0),
                    'last_updated' => date('Y-m-d H:i:s'),
                ]);
                $counts['perf']++;
            }

            // ─── 4. Convergence Logs ───
            /** @var list<array<string, mixed>> $convLogs */
            $convLogs = (array) ($dataset['convergence_logs'] ?? []);
            foreach ($convLogs as $log) {
                /** @var array<string, mixed> $log */
                $this->connection->insert('bandit_convergence_log', [
                    'scam_type_code' => (string) ($log['scam_type_code'] ?? ''),
                    'dominant_persona_code' => (string) ($log['dominant_persona_code'] ?? ''),
                    'dominant_pct' => (float) ($log['dominant_pct'] ?? 0),
                    'sessions_count' => (int) ($log['sessions_count'] ?? 0),
                    'converged' => !empty($log['converged']) ? 'true' : 'false',
                    'logged_at' => (string) ($log['logged_at'] ?? date('Y-m-d H:i:s')),
                ]);
                $counts['convergence']++;
            }

            // ─── 5. Campaigns ───
            /** @var list<array<string, mixed>> $campaigns */
            $campaigns = (array) ($dataset['campaigns'] ?? []);
            foreach ($campaigns as $campaign) {
                /** @var array<string, mixed> $campaign */
                /** @var list<array<string, mixed>> $matchedMsgs */
                $matchedMsgs = (array) ($campaign['matched_messages'] ?? []);
                $firstMatch = $matchedMsgs[0] ?? [];

                $this->connection->insert('campaign', [
                    'campaign_id' => (string) ($campaign['campaign_id'] ?? $this->generateUuid()),
                    'first_seen' => (string) ($firstMatch['timestamp'] ?? date('Y-m-d H:i:s')),
                    'status' => (string) ($campaign['status'] ?? 'shadow'),
                    'actor_guess' => isset($campaign['actor_guess']) ? (string) $campaign['actor_guess'] : null,
                    'tlp' => (string) ($campaign['tlp'] ?? 'AMBER'),
                    'severity' => (int) ($campaign['severity'] ?? 3),
                    'dsl_hash' => (string) ($campaign['dsl_hash'] ?? ''),
                    'created_by' => 'demo-dataset',
                    'notes' => (string) ($campaign['name'] ?? ''),
                    'profile_yaml' => isset($campaign['profile_yaml']) ? (string) $campaign['profile_yaml'] : null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $counts['campaign']++;

                $campaignId = (string) ($campaign['campaign_id'] ?? '');
                /** @var list<array<string, mixed>> $rules */
                $rules = (array) ($campaign['rules'] ?? []);
                foreach ($rules as $rule) {
                    /** @var array<string, mixed> $rule */
                    $this->connection->insert('campaign_rule', [
                        'rule_id' => (string) ($rule['rule_id'] ?? $this->generateUuid()),
                        'campaign_id' => $campaignId,
                        'version' => 1,
                        'dsl' => (string) ($rule['dsl'] ?? ''),
                        'compiled_sql' => json_encode(['sql' => (string) ($rule['compiled_sql'] ?? '')], JSON_THROW_ON_ERROR),
                        'ppv' => (float) ($rule['ppv'] ?? 0),
                        'hits_total' => (int) ($rule['hits_total'] ?? 0),
                        'hits_true_pos' => (int) ($rule['hits_true_pos'] ?? 0),
                        'hits_false_pos' => (int) ($rule['hits_false_pos'] ?? 0),
                        'lead_time_sec' => (int) ($rule['lead_time_sec'] ?? 0),
                        'promoted_at' => isset($rule['promoted_at']) ? (string) $rule['promoted_at'] : null,
                        'enabled' => !empty($rule['enabled']) ? 'true' : 'false',
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }

                foreach ($matchedMsgs as $match) {
                    /** @var array<string, mixed> $match */
                    $msgKey = (string) ($match['conv_id'] ?? '') . ':' . (string) ($match['msg_index'] ?? '0');
                    $msgId = $msgIdMap[$msgKey] ?? null;

                    if (!$msgId) {
                        continue;
                    }

                    $this->connection->insert('message_campaign', [
                        'msg_id' => $msgId,
                        'campaign_id' => $campaignId,
                        'confidence' => round(random_int(75, 98) / 100, 4),
                        'detected_at' => (string) ($match['timestamp'] ?? date('Y-m-d H:i:s')),
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
            'Loaded: %d conversations, %d messages, %d IOCs, %d LLM records, %d perf stats, %d convergence logs, %d campaigns (%d message links).',
            $counts['conv'],
            $counts['msg'],
            $counts['ioc'],
            $counts['llm'],
            $counts['perf'],
            $counts['convergence'],
            $counts['campaign'],
            $counts['campaign_msg']
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

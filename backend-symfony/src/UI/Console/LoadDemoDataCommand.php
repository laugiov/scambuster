<?php

/** @phpstan-ignore-file — Data loader with JSON-decoded mixed arrays; strict typing impractical here. */

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Communication\IocContextService;
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
        private readonly string $projectDir,
        private readonly ?IocContextService $contextService = null,
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
            $this->connection->executeStatement('DELETE FROM message_campaign');
            $this->connection->executeStatement('DELETE FROM campaign_rule');
            $this->connection->executeStatement('DELETE FROM campaign');
            $this->connection->executeStatement("DELETE FROM ioc_context WHERE obs_id IN (SELECT obs_id FROM observed_ioc WHERE msg_id IN (SELECT msg_id FROM message WHERE conv_id IN (SELECT conv_id FROM conversation WHERE stix_id LIKE 'demo-%')))");
            $this->connection->executeStatement("DELETE FROM observed_ioc WHERE msg_id IN (SELECT msg_id FROM message WHERE conv_id IN (SELECT conv_id FROM conversation WHERE stix_id LIKE 'demo-%'))");
            $this->connection->executeStatement('DELETE FROM indicator WHERE indicator_id NOT IN (SELECT DISTINCT indicator_id FROM observed_ioc)');
            $this->connection->executeStatement("DELETE FROM llm_usage WHERE conversation_id IN (SELECT conv_id::text FROM conversation WHERE stix_id LIKE 'demo-%')");
            $this->connection->executeStatement("DELETE FROM bandit_convergence_log WHERE scam_type_code LIKE '%'");
            $this->connection->executeStatement("DELETE FROM conversation WHERE stix_id LIKE 'demo-%'");
        }

        $counts = ['conv' => 0, 'msg' => 0, 'ioc' => 0, 'llm' => 0, 'perf' => 0, 'convergence' => 0, 'campaign' => 0, 'campaign_msg' => 0];
        $allMsgIocs = []; // msgId => obsIocData, for post-commit context computation

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
                    $obsIocData = [];

                    foreach ($iocsExtracted as $j => $ioc) {
                        /** @var array<string, mixed> $ioc */
                        $obsId = $this->generateUuid();
                        $iocType = (string) ($ioc['type'] ?? 'unknown');
                        $iocValue = (string) ($ioc['value'] ?? '');
                        $indicatorId = $this->deterministicUuid($iocType . ':' . $iocValue);
                        $valueNorm = strtolower($iocValue);

                        // Generate realistic enrichment & score for demo
                        $enrichmentData = $this->generateDemoEnrichment($iocType, $scamCode);
                        $extractionMethod = $this->pickExtractionMethod($iocType);
                        $confidence = round(random_int(75, 100) / 100, 3);

                        $context = [
                            'type' => $iocType,
                            'value' => $iocValue,
                            'value_norm' => $valueNorm,
                            'category' => $scamCode,
                            'source' => 'extraction',
                            'extraction_method' => $extractionMethod,
                            'first_seen' => $msgTimestamp,
                            'enrichment' => $enrichmentData['enrichment'],
                            'score' => $enrichmentData['score'],
                            'tags' => [$scamCode],
                            'tlp' => 'AMBER',
                        ];

                        $this->connection->insert('observed_ioc', [
                            'obs_id' => $obsId,
                            'msg_id' => $msgId,
                            'indicator_id' => $indicatorId,
                            'context_observation' => json_encode($context, JSON_THROW_ON_ERROR),
                            'confidence_score' => $confidence,
                            'ts_observed' => $msgTimestamp,
                        ]);

                        // Upsert indicator table (global IOC dedup)
                        $existingIndicator = $this->connection->fetchAssociative(
                            'SELECT indicator_id, occurrences FROM indicator WHERE indicator_id = ?',
                            [$indicatorId]
                        );

                        if ($existingIndicator) {
                            $this->connection->executeStatement(
                                'UPDATE indicator SET last_seen = :lastSeen, occurrences = occurrences + 1,
                                 enrichment = :enrichment, score = :score, updated_at = :updatedAt
                                 WHERE indicator_id = :id',
                                [
                                    'lastSeen' => $msgTimestamp,
                                    'enrichment' => json_encode($enrichmentData['enrichment']),
                                    'score' => json_encode($enrichmentData['score']),
                                    'updatedAt' => $msgTimestamp,
                                    'id' => $indicatorId,
                                ]
                            );
                        } else {
                            $this->connection->insert('indicator', [
                                'indicator_id' => $indicatorId,
                                'type' => $iocType,
                                'value' => $iocValue,
                                'value_norm' => $valueNorm,
                                'first_seen' => $msgTimestamp,
                                'last_seen' => $msgTimestamp,
                                'occurrences' => 1,
                                'enrichment' => json_encode($enrichmentData['enrichment']),
                                'score' => json_encode($enrichmentData['score']),
                                'tlp' => 'AMBER',
                                'created_at' => $msgTimestamp,
                                'updated_at' => $msgTimestamp,
                            ]);
                        }

                        $obsIocData[] = [
                            'obs_id' => $obsId,
                            'indicator_id' => $indicatorId,
                            'ioc_type' => $iocType,
                        ];

                        $counts['ioc']++;
                    }

                    // Collect IOCs for post-commit context computation
                    if (!empty($obsIocData)) {
                        $allMsgIocs[$msgId] = $obsIocData;
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

        // ─── 6. Compute structural IOC context (post-commit, non-blocking) ───
        if ($this->contextService !== null && !empty($allMsgIocs)) {
            $io->info(sprintf('Computing structural context for %d messages...', count($allMsgIocs)));
            $ctxCount = 0;

            foreach ($allMsgIocs as $msgId => $obsIocData) {
                try {
                    $this->contextService->computeAndPersistForMessage($msgId, $obsIocData);
                    ++$ctxCount;
                } catch (\Throwable) {
                    // Non-blocking
                }
            }
            $io->info(sprintf('Structural context: %d messages processed.', $ctxCount));
        }

        // ─── 7. Hardcoded LLM semantic enrichment for demo IOCs ───
        $enrichedCount = $this->applyDemoSemanticEnrichment($io);

        $io->success(sprintf(
            'Loaded: %d conversations, %d messages, %d IOCs (%d enriched), %d LLM records, %d perf stats, %d convergence logs, %d campaigns (%d message links).',
            $counts['conv'],
            $counts['msg'],
            $counts['ioc'],
            $enrichedCount,
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

    /**
     * Generate realistic enrichment data for demo IOCs.
     *
     * High-risk scam types (CEO_FRAUD, PHISH_MALWARE) get VT detections.
     * URLs and domains get URLScan verdicts. Finance IOCs get no VT/URLScan (not applicable).
     *
     * @return array{enrichment: array<string, mixed>, score: array<string, mixed>}
     */
    private function generateDemoEnrichment(string $iocType, string $scamType): array
    {
        $enrichment = [];
        $vtScore = 0;
        $urlscanScore = 0;
        $explanations = [];

        // Determine threat level based on scam type
        $highRisk = \in_array($scamType, ['CEO_FRAUD', 'PHISH_MALWARE', 'PHISH_CREDENTIALS'], true);
        $mediumRisk = \in_array($scamType, ['PHISHING', 'INVOICE_FRAUD', 'TECH_SUPPORT'], true);

        // VT enrichment for URLs, domains, IPs, hashes
        if (\in_array($iocType, ['url', 'domain', 'ipv4', 'sha256'], true)) {
            if ($highRisk) {
                $malicious = random_int(3, 12);
                $suspicious = random_int(0, 3);
            } elseif ($mediumRisk) {
                $malicious = random_int(0, 5);
                $suspicious = random_int(1, 4);
            } else {
                $malicious = 0;
                $suspicious = random_int(0, 2);
            }

            $enrichment['virustotal'] = [
                'malicious' => $malicious,
                'suspicious' => $suspicious,
                'harmless' => random_int(50, 70),
                'undetected' => random_int(5, 15),
            ];

            if ($malicious > 0) {
                $vtScore = 70;
                $explanations[] = "VT: {$malicious} engines flagged malicious";
            } elseif ($suspicious > 0) {
                $vtScore = 40;
                $explanations[] = "VT: {$suspicious} engines flagged suspicious";
            }
        }

        // URLScan enrichment for URLs and domains
        if (\in_array($iocType, ['url', 'domain'], true)) {
            if ($highRisk) {
                $verdict = 'malicious';
                $urlscanScore = 60;
                $explanations[] = 'URLScan: verdict malicious';
            } elseif ($mediumRisk && random_int(0, 1) === 1) {
                $verdict = 'suspicious';
                $urlscanScore = 25;
                $explanations[] = 'URLScan: verdict suspicious';
            } else {
                $verdict = random_int(0, 2) === 0 ? 'suspicious' : 'clean';
                $urlscanScore = $verdict === 'suspicious' ? 25 : 0;

                if ($verdict === 'suspicious') {
                    $explanations[] = 'URLScan: verdict suspicious';
                }
            }

            $enrichment['urlscan'] = [
                'status' => 'completed',
                'verdict' => $verdict,
                'positives' => $verdict === 'malicious' ? random_int(1, 3) : 0,
            ];
        }

        $aggScore = min($vtScore + $urlscanScore, 100);
        $explain = empty($explanations) ? 'No threats detected' : implode('; ', $explanations);

        return [
            'enrichment' => $enrichment,
            'score' => [
                'vt' => $vtScore,
                'urlscan' => $urlscanScore,
                'agg' => $aggScore,
                'explain' => $explain,
            ],
        ];
    }

    /** Pick a realistic extraction method based on IOC type */
    private function pickExtractionMethod(string $iocType): string
    {
        $methods = [
            'url' => ['llm', 'regex', 'llm'],
            'domain' => ['derived_from_url', 'llm', 'derived_from_email'],
            'email' => ['regex', 'llm', 'regex'],
            'ipv4' => ['regex', 'derived_from_url', 'llm'],
            'phone' => ['llm', 'regex'],
            'iban' => ['llm', 'regex'],
            'wallet_btc' => ['llm', 'regex'],
            'wallet_eth' => ['llm'],
            'sha256' => ['regex', 'llm'],
        ];
        $pool = $methods[$iocType] ?? ['llm'];

        return $pool[array_rand($pool)];
    }

    /**
     * Apply hardcoded LLM semantic enrichment to demo ioc_context rows.
     *
     * Updates structural rows to enriched with realistic roles, stimulus, urgency.
     * No LLM call — all values are deterministic based on IOC type and scam type.
     */
    private function applyDemoSemanticEnrichment(SymfonyStyle $io): int
    {
        // Role mapping by IOC type (deterministic, matches prompt constraints)
        $roleByType = [
            'url' => 'PHISHING_CREDENTIAL_URL',
            'domain' => 'INFRASTRUCTURE_DOMAIN',
            'email' => 'CONTACT_CHANNEL',
            'phone' => 'CONTACT_CHANNEL',
            'iban' => 'PAYMENT_DESTINATION',
            'bic' => 'PAYMENT_DESTINATION',
            'wallet_btc' => 'PAYMENT_DESTINATION',
            'wallet_eth' => 'PAYMENT_DESTINATION',
            'wallet_xmr' => 'PAYMENT_DESTINATION',
            'sha256' => 'MALWARE_DOWNLOAD_URL',
            'ipv4' => 'INFRASTRUCTURE_DOMAIN',
            'telegram_username' => 'CONTACT_CHANNEL',
            'discord_username' => 'CONTACT_CHANNEL',
        ];

        // Stimulus by scam type
        $stimulusByScam = [
            'PHISHING' => 'PASSIVE',
            'PHISH_CREDENTIALS' => 'PASSIVE',
            'PHISH_MALWARE' => 'PASSIVE',
            'ROMANCE' => 'TRUST_BUILDING',
            'INVOICE_FRAUD' => 'PAYMENT_INITIATION',
            'CEO_FRAUD' => 'URGENCY_PRESSURE',
            'TECH_SUPPORT' => 'URGENCY_PRESSURE',
            'INVESTMENT' => 'PASSIVE',
            'LOTTERY' => 'PASSIVE',
            'ADVANCE_FEE_419' => 'DIRECT_REQUEST',
            'JOB_OFFER' => 'PASSIVE',
            'CHARITY' => 'TRUST_BUILDING',
        ];

        // Urgency by scam type
        $urgencyByScam = [
            'PHISHING' => [0.65, 0.85],
            'PHISH_CREDENTIALS' => [0.70, 0.90],
            'PHISH_MALWARE' => [0.50, 0.70],
            'ROMANCE' => [0.20, 0.50],
            'INVOICE_FRAUD' => [0.60, 0.85],
            'CEO_FRAUD' => [0.80, 0.95],
            'TECH_SUPPORT' => [0.70, 0.90],
            'INVESTMENT' => [0.30, 0.60],
            'LOTTERY' => [0.40, 0.65],
            'ADVANCE_FEE_419' => [0.50, 0.75],
            'JOB_OFFER' => [0.25, 0.50],
            'CHARITY' => [0.40, 0.65],
        ];

        // Context excerpts by scam type (specific, not generic boilerplate)
        $excerptsByScam = [
            'PHISHING' => [
                'First-contact phishing impersonating bank security with credential harvesting link and support phone',
                'Scammer created urgency around account suspension to push victim toward fake verification portal',
                'Account security impersonation with fake login page designed to harvest credentials',
            ],
            'PHISH_CREDENTIALS' => [
                'Credential harvesting email impersonating IT security requiring password reset via fake portal',
                'MFA reset phishing with fake compliance deadline to capture authentication credentials',
                'SSO re-authentication phish targeting corporate email credentials via fake login page',
            ],
            'PHISH_MALWARE' => [
                'Scammer distributed malware disguised as shared document with executable payload',
                'Fake file-sharing notification leading to malicious download with social engineering pretext',
            ],
            'ROMANCE' => [
                'Scammer built emotional connection before requesting money transfer for fabricated emergency',
                'Romance scammer escalated financial requests after establishing trust over multiple exchanges',
                'Fake military persona used emotional manipulation to solicit wire transfer for travel fees',
            ],
            'INVOICE_FRAUD' => [
                'Scammer impersonated vendor with changed banking details for invoice payment redirect',
                'Fake payment update notice with new IBAN to intercept legitimate business payment',
                'Invoice fraud with fake overdue notice and legal threats to pressure immediate wire transfer',
            ],
            'CEO_FRAUD' => [
                'CEO impersonation pressuring urgent wire transfer with fake approval and time constraints',
                'Business email compromise with spoofed executive requesting confidential payment processing',
            ],
            'TECH_SUPPORT' => [
                'Fake Microsoft security alert with fabricated threats to sell fraudulent protection service',
                'Tech support scam using fake virus detection to gain remote access and charge removal fees',
            ],
            'INVESTMENT' => [
                'Fraudulent investment scheme promising unrealistic returns with crypto and wire payment options',
                'Fake hedge fund solicitation with fabricated performance data and urgency around fund closing',
            ],
            'LOTTERY' => [
                'Fake lottery win notification requiring advance fee payment to claim non-existent prize',
                'Scammer impersonated lottery board requesting processing fee via wire transfer for fake prize',
            ],
            'ADVANCE_FEE_419' => [
                'Advance fee fraud claiming inheritance requiring processing fee to release fictional funds',
                'Classic 419 scam with fabricated legal scenario requesting upfront payment for fund release',
            ],
            'JOB_OFFER' => [
                'Fake job offer requesting personal information and bank details for identity theft',
                'Employment scam with unrealistic salary offering to harvest personal and financial data',
            ],
            'CHARITY' => [
                'Fake charity appeal exploiting disaster scenario to solicit donations via wire transfer',
                'Fraudulent humanitarian organization soliciting donations with emotional manipulation',
            ],
        ];

        // Override roles for specific scam contexts
        $roleOverrides = [
            'ROMANCE' => ['iban' => 'MONEY_MULE_ACCOUNT', 'wallet_btc' => 'PAYMENT_DESTINATION'],
            'INVOICE_FRAUD' => ['iban' => 'MONEY_MULE_ACCOUNT'],
            'CEO_FRAUD' => ['iban' => 'MONEY_MULE_ACCOUNT'],
            'ADVANCE_FEE_419' => ['iban' => 'MONEY_MULE_ACCOUNT'],
            'PHISH_MALWARE' => ['url' => 'MALWARE_DOWNLOAD_URL', 'sha256' => 'MALWARE_DOWNLOAD_URL'],
            'TECH_SUPPORT' => ['url' => 'PAYMENT_REDIRECT_URL', 'phone' => 'CONTACT_CHANNEL'],
            'INVESTMENT' => ['url' => 'PAYMENT_REDIRECT_URL'],
        ];

        $rows = $this->connection->fetchAllAssociative(
            "SELECT ic.id, ic.obs_id, ic.scam_type_code, ic.revelation_turn, ic.total_turns,
                    i.type AS ioc_type
             FROM ioc_context ic
             JOIN indicator i ON ic.indicator_id = i.indicator_id
             WHERE ic.enrichment_status = 'structural'"
        );

        $enriched = 0;
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $iocType = (string) ($row['ioc_type'] ?? 'unknown');
            $scamType = (string) ($row['scam_type_code'] ?? 'UNKNOWN');
            $turn = \is_numeric($row['revelation_turn'] ?? null) ? (int) $row['revelation_turn'] : 1;
            $totalTurns = \is_numeric($row['total_turns'] ?? null) ? (int) $row['total_turns'] : 0;

            // Determine role (with scam-specific overrides)
            $overrides = $roleOverrides[$scamType] ?? [];
            $role = $overrides[$iocType] ?? $roleByType[$iocType] ?? 'UNKNOWN';

            // Stimulus
            $stimulus = $stimulusByScam[$scamType] ?? 'UNKNOWN';

            // Urgency (varies by turn position)
            $range = $urgencyByScam[$scamType] ?? [0.40, 0.70];
            $turnBoost = $totalTurns > 0 ? min($turn / $totalTurns * 0.2, 0.2) : 0.0;
            $urgency = round($range[0] + (($range[1] - $range[0]) * (random_int(30, 80) / 100)) + $turnBoost, 2);
            $urgency = min(1.0, $urgency);

            // Confidence based on available context
            $confidence = $totalTurns > 0
                ? round(0.55 + min($turn / $totalTurns * 0.3, 0.3) + (random_int(0, 10) / 100), 2)
                : round(0.35 + (random_int(0, 20) / 100), 2);
            $confidence = min(0.95, $confidence);

            // Excerpt
            $pool = $excerptsByScam[$scamType] ?? ['Scammer revealed IOCs in a first-contact message'];
            $excerpt = $pool[array_rand($pool)];

            $this->connection->executeStatement(
                'UPDATE ioc_context SET
                    semantic_role = :role,
                    stimulus_type = :stimulus,
                    urgency_score = :urgency,
                    language_switch = :langSwitch,
                    hesitation_detected = :hesitation,
                    context_excerpt = :excerpt,
                    enrichment_confidence = :confidence,
                    enrichment_status = \'enriched\',
                    computed_at = :computedAt
                 WHERE id = :id',
                [
                    'role' => $role,
                    'stimulus' => $stimulus,
                    'urgency' => $urgency,
                    'langSwitch' => 'false',
                    'hesitation' => random_int(0, 100) <= 15 ? 'true' : 'false',
                    'excerpt' => $excerpt,
                    'confidence' => $confidence,
                    'computedAt' => $now,
                    'id' => $row['id'],
                ],
            );
            ++$enriched;
        }

        $io->info(sprintf('Semantic enrichment: %d IOCs enriched with hardcoded roles/excerpts.', $enriched));

        return $enriched;
    }
}

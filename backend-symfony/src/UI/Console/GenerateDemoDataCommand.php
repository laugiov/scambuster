<?php

/** @phpstan-ignore-file — Generator with large template arrays; strict typing impractical here. */

declare(strict_types=1);

namespace App\UI\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates scambuster-dataset-sample.json with 150 realistic English conversations.
 *
 * V2: Stage-aware templates, per-persona responses, placeholder randomization,
 * IOC extraction from body text, campaign clustering, dedup guard.
 *
 * All data is synthetic. IOCs use RFC 5737 IPs, +1-555 phones, TEST IBANs.
 */
#[AsCommand(
    name: 'scambuster:demo:generate',
    description: 'Generate production-quality demo dataset (150 conversations, English)'
)]
class GenerateDemoDataCommand extends Command
{
    private const TOTAL_CONVERSATIONS = 150;
    private const WEEKS_SPAN = 8;

    private const SCAM_DISTRIBUTION = [
        'PHISHING' => 25, 'PHISH_CREDENTIALS' => 20, 'ROMANCE' => 18,
        'INVOICE_FRAUD' => 16, 'TECH_SUPPORT' => 14, 'INVESTMENT' => 12,
        'LOTTERY' => 10, 'CEO_FRAUD' => 10, 'ADVANCE_FEE_419' => 8,
        'JOB_OFFER' => 8, 'CHARITY' => 5, 'PHISH_MALWARE' => 4,
    ];

    private const RISK_RANGES = [
        'PHISHING' => ['min' => 50, 'max' => 80], 'PHISH_CREDENTIALS' => ['min' => 55, 'max' => 85],
        'ROMANCE' => ['min' => 30, 'max' => 60], 'INVOICE_FRAUD' => ['min' => 60, 'max' => 90],
        'TECH_SUPPORT' => ['min' => 40, 'max' => 70], 'INVESTMENT' => ['min' => 50, 'max' => 80],
        'LOTTERY' => ['min' => 35, 'max' => 65], 'CEO_FRAUD' => ['min' => 70, 'max' => 95],
        'ADVANCE_FEE_419' => ['min' => 40, 'max' => 70], 'JOB_OFFER' => ['min' => 35, 'max' => 65],
        'CHARITY' => ['min' => 25, 'max' => 50], 'PHISH_MALWARE' => ['min' => 60, 'max' => 90],
    ];

    private const TURN_RANGES = [
        'PHISHING' => ['min' => 3, 'max' => 4], 'PHISH_CREDENTIALS' => ['min' => 3, 'max' => 5],
        'ROMANCE' => ['min' => 5, 'max' => 8], 'INVOICE_FRAUD' => ['min' => 3, 'max' => 5],
        'TECH_SUPPORT' => ['min' => 3, 'max' => 5], 'INVESTMENT' => ['min' => 4, 'max' => 6],
        'LOTTERY' => ['min' => 3, 'max' => 5], 'CEO_FRAUD' => ['min' => 3, 'max' => 4],
        'ADVANCE_FEE_419' => ['min' => 4, 'max' => 6], 'JOB_OFFER' => ['min' => 3, 'max' => 5],
        'CHARITY' => ['min' => 3, 'max' => 4], 'PHISH_MALWARE' => ['min' => 2, 'max' => 3],
    ];

    /** Campaign signatures — conversations matching these scam types get tagged */
    private const CAMPAIGN_SIGNATURES = [
        ['name' => 'PayPal Credential Harvesting Ring', 'scam_types' => ['PHISHING', 'PHISH_CREDENTIALS'], 'domain' => 'secure-paypal-verify.com', 'ip' => '198.51.100.10', 'status' => 'promoted', 'severity' => 4, 'max_convs' => 8],
        ['name' => 'Microsoft Tech Support Fraud', 'scam_types' => ['TECH_SUPPORT'], 'domain' => 'microsoft-support-help.com', 'phone' => '+1-555-0199', 'status' => 'promoted', 'severity' => 3, 'max_convs' => 6],
        ['name' => 'UK Invoice Payment Redirect', 'scam_types' => ['INVOICE_FRAUD', 'CEO_FRAUD'], 'domain' => 'payment-portal-uk.com', 'iban' => 'GB82TEST60161331926819', 'status' => 'shadow', 'severity' => 5, 'max_convs' => 5],
        ['name' => 'West African Romance Ring', 'scam_types' => ['ROMANCE'], 'domain' => 'lonely-hearts-connect.com', 'ip' => '203.0.113.50', 'status' => 'shadow', 'severity' => 3, 'max_convs' => 4],
        ['name' => 'Crypto Yield Farming Scam', 'scam_types' => ['INVESTMENT'], 'domain' => 'crypto-yield-farm.io', 'wallet' => '1DemoInvest8BTC4xYz2AbCdEfGhJkLmNp', 'status' => 'shadow', 'severity' => 4, 'max_convs' => 3],
    ];

    /** Persona → group mapping */
    private const PERSONA_GROUPS = [
        'bank_customer' => 'formal', 'accountant_meticulous' => 'formal', 'admin_assistant' => 'formal',
        'worried_customer' => 'anxious', 'tech_newbie' => 'anxious', 'debtor_desperate' => 'anxious',
        'senior_isolated' => 'warm', 'lonely_divorcee' => 'warm', 'lonely_person' => 'warm', 'charity_donor' => 'warm',
        'senior_suspicious' => 'skeptical', 'lottery_skeptic' => 'skeptical',
        'small_business_owner' => 'direct', 'entrepreneur_rushed' => 'direct',
        'student_busy' => 'casual', 'buyer_eager' => 'casual',
        'hopeless_romantic' => 'romantic', 'widow_grieving' => 'romantic',
        'generic_user' => 'neutral', 'tech_intermediate' => 'neutral', 'freelance_cautious' => 'neutral',
        'job_seeker' => 'neutral', 'investor_greedy' => 'neutral', 'elderly_person' => 'neutral',
        'confused_user' => 'neutral', 'senior_trusting' => 'neutral',
    ];

    // ─── Random value pools ─────────────────────────────────────
    private const NAMES = ['James Wilson', 'Sarah Chen', 'Michael O\'Brien', 'Maria Santos', 'David Kumar', 'Jennifer Park', 'Robert Taylor', 'Lisa Anderson', 'William Brown', 'Emma Davis', 'Thomas White', 'Anna Martinez', 'Christopher Lee', 'Michelle Robinson', 'Daniel Harris', 'Patricia Clark', 'Richard Lewis', 'Barbara Walker', 'Joseph Hall', 'Margaret Allen', 'Charles Young', 'Sandra King', 'Paul Wright', 'Nancy Scott', 'Mark Green', 'Karen Baker', 'Steven Adams', 'Betty Nelson', 'Andrew Hill', 'Dorothy Moore'];

    private const CITIES = ['London', 'Lagos', 'Dubai', 'Singapore', 'New York', 'Sydney', 'Toronto', 'Mumbai', 'Berlin', 'Tokyo', 'Paris', 'Nairobi', 'Amsterdam', 'Bangkok', 'Istanbul', 'Johannesburg', 'Manila', 'São Paulo', 'Cairo', 'Melbourne'];

    private const COMPANIES = ['GlobalTech Solutions', 'Pacific Holdings Ltd', 'Crown Financial Group', 'Atlas International', 'Summit Enterprises', 'Meridian Partners', 'Vanguard Capital', 'Phoenix Trading Co', 'Sterling Associates', 'Pinnacle Investments', 'Catalyst Corp', 'Horizon Dynamics', 'Apex Industries', 'Titan Resources', 'Nexus Group'];

    private const SENDER_NAMES = ['Dr. Sarah Mitchell', 'Barrister James Okonkwo', 'Sgt. David Anderson', 'Prof. Emily Richardson', 'Captain Mark Thompson', 'Director Linda Chen', 'Agent Robert Williams', 'Mr. Peter Van Der Berg', 'Dr. Ahmed Hassan', 'Solicitor Catherine Moore', 'Col. John Bradley', 'Nurse Rebecca Santos', 'Rev. Michael Okoro', 'Engineer Raj Patel', 'Administrator Helen Burns'];

    private array $campaignAssignments = [];
    private array $globalOpeningCounter = []; // Track which opening template to use next per scam type

    public function __construct(
        private readonly Connection $connection,
        private readonly string $projectDir
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('ScamBuster Demo Dataset Generator v2');

        $validPairs = $this->loadValidPairs();

        if (empty($validPairs)) {
            $io->error('No scam_type_persona pairs found. Run "make fixtures-dev" first.');

            return Command::FAILURE;
        }
        $io->info(sprintf('Loaded %d valid (scam_type, persona) pairs.', count($validPairs)));

        $endTs = time();
        $startTs = $endTs - (self::WEEKS_SPAN * 7 * 86400);

        $conversations = [];
        $allLlmUsage = [];
        $personaStats = [];
        $totalMessages = 0;
        $totalIocs = 0;

        // Pre-assign campaign conversations
        $this->preBuildCampaignAssignments();

        $convIndex = 0;

        foreach (self::SCAM_DISTRIBUTION as $scamType => $count) {
            $availablePersonas = $validPairs[$scamType] ?? [];

            if (empty($availablePersonas)) {
                continue;
            }

            for ($c = 0; $c < $count; $c++) {
                $persona = $availablePersonas[array_rand($availablePersonas)];
                $status = $this->pickStatus($convIndex);
                $turns = $this->pickTurns($scamType, $status);
                $convId = $this->generateUuid();
                $ts = $this->pickTimestamp($startTs, $endTs, $convIndex);
                $riskRange = self::RISK_RANGES[$scamType];
                $risk = random_int($riskRange['min'], $riskRange['max']);
                $reward = $status === 'closed' ? round(min(0.3 + ($turns * 0.08) + (random_int(0, 20) / 100), 0.95), 4) : null;
                $engagementSec = $turns * random_int(1800, 14400);

                // Resolve placeholders for this conversation (fixed set)
                $resolved = $this->resolveConversationPlaceholders($scamType, $convId);

                $messages = $this->generateMessages($convId, $scamType, $persona, $turns, $ts, $engagementSec, $resolved);
                $totalMessages += count($messages);

                $iocCount = 0;

                foreach ($messages as $msg) {
                    $iocCount += count($msg['iocs_extracted'] ?? []);
                }
                $totalIocs += $iocCount;

                // LLM usage aligned with pipeline traces (C3)
                foreach ($messages as $msg) {
                    if ($msg['direction'] === 'outbound' && isset($msg['pipeline_trace'])) {
                        $traceCost = $msg['pipeline_trace']['total_cost'];
                        $allLlmUsage[] = [
                            'conversation_id' => $convId,
                            'provider' => 'openai',
                            'model' => 'gpt-4o-mini',
                            'purpose' => ['reply_generation', 'policy_guard', 'reply_validation'][array_rand(['reply_generation', 'policy_guard', 'reply_validation'])],
                            'prompt_tokens' => random_int(800, 2500),
                            'completion_tokens' => random_int(100, 500),
                            'estimated_cost_usd' => $traceCost,
                            'created_at' => $msg['timestamp'],
                        ];
                    }
                }

                // Track persona stats (C4)
                $statsKey = $persona . '|' . $scamType;

                if (!isset($personaStats[$statsKey])) {
                    $personaStats[$statsKey] = ['persona' => $persona, 'scam_type' => $scamType, 'sessions' => 0, 'reward_sum' => 0.0];
                }

                if ($status === 'closed' && $reward !== null) {
                    $personaStats[$statsKey]['sessions']++;
                    $personaStats[$statsKey]['reward_sum'] += $reward;
                }

                $conversations[] = [
                    'conversation_id' => $convId,
                    'scam_type' => $scamType,
                    'persona' => $persona,
                    'status' => $status,
                    'risk_score' => $risk,
                    'turns' => $turns,
                    'engagement_duration_sec' => $engagementSec,
                    'reward_value' => $reward,
                    'message_count' => count($messages),
                    'ioc_count' => $iocCount,
                    'messages' => $messages,
                ];
                $convIndex++;
            }
        }

        // Build persona performance stats (C4)
        $perfStats = [];

        foreach ($personaStats as $stat) {
            if ($stat['sessions'] > 0) {
                $perfStats[] = [
                    'persona_code' => $stat['persona'],
                    'scam_type_code' => $stat['scam_type'],
                    'sessions_count' => $stat['sessions'],
                    'reward_sum' => round($stat['reward_sum'], 4),
                    'reward_avg' => round($stat['reward_sum'] / $stat['sessions'], 4),
                ];
            }
        }

        // Build convergence logs (C5)
        $convergenceLogs = $this->generateConvergenceLogs($perfStats, $startTs, $endTs);

        // Build campaigns (C6)
        $campaigns = $this->generateCampaigns($conversations);

        $dataset = [
            'metadata' => [
                'generated_at' => date('c'),
                'version' => '2.0',
                'conversations_count' => count($conversations),
                'messages_count' => $totalMessages,
                'iocs_count' => $totalIocs,
                'campaigns_count' => count($campaigns),
                'date_range' => ['start' => date('Y-m-d', $startTs), 'end' => date('Y-m-d', $endTs)],
            ],
            'conversations' => $conversations,
            'llm_usage' => $allLlmUsage,
            'persona_performance_stats' => $perfStats,
            'convergence_logs' => $convergenceLogs,
            'campaigns' => $campaigns,
        ];

        $outFile = $this->projectDir . '/var/demo-dataset.json';
        $json = json_encode($dataset, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($outFile, $json);

        $io->success(sprintf(
            "Generated: %d conversations, %d messages, %d IOCs, %d LLM records, %d perf stats, %d convergence logs, %d campaigns.\nFile: %s (%s)",
            count($conversations),
            $totalMessages,
            $totalIocs,
            count($allLlmUsage),
            count($perfStats),
            count($convergenceLogs),
            count($campaigns),
            $outFile,
            $this->formatBytes(strlen($json))
        ));

        return Command::SUCCESS;
    }

    // ═══════════════════════════════════════════════════════════════
    //  RANDOMIZATION ENGINE
    // ═══════════════════════════════════════════════════════════════

    private function resolveConversationPlaceholders(string $scamType, string $convId): array
    {
        // Check if this conversation is part of a campaign — use campaign IOCs
        $campaignSig = $this->campaignAssignments[$convId] ?? null;

        $domain = $campaignSig['domain'] ?? $this->randomDomain($scamType);
        $ip = $campaignSig['ip'] ?? $this->randomIp();

        // Scam-context phrases for outbound responses (C: context injection)
        $contextPools = [
            'PHISHING' => ['my account', 'this suspicious activity', 'the security alert', 'the verification link', 'my account status'],
            'PHISH_CREDENTIALS' => ['my password', 'this login issue', 'my email access', 'the authentication problem', 'my credentials'],
            'ROMANCE' => ['your message', 'our connection', 'getting to know you', 'your story', 'hearing from you'],
            'INVOICE_FRAUD' => ['this invoice', 'the payment details', 'the bank change', 'the transfer request', 'our accounts department'],
            'TECH_SUPPORT' => ['my computer', 'this virus warning', 'the security alert', 'my device', 'the malware issue'],
            'CEO_FRAUD' => ['this transfer request', 'the wire payment', 'your instructions', 'this urgent matter', 'the confidential deal'],
            'INVESTMENT' => ['this investment', 'the trading platform', 'the returns you mentioned', 'the deposit', 'this opportunity'],
            'LOTTERY' => ['this prize', 'my winnings', 'the lottery notification', 'the claim process', 'the processing fee'],
            'ADVANCE_FEE_419' => ['this proposal', 'the fund transfer', 'the estate', 'the partnership', 'the legal documents'],
            'JOB_OFFER' => ['this job offer', 'the position', 'the onboarding process', 'the remote work opportunity', 'the equipment fee'],
            'CHARITY' => ['your cause', 'the donation', 'the children you mentioned', 'the relief effort', 'your organization'],
            'PHISH_MALWARE' => ['this file', 'the attachment', 'the document you shared', 'the download link', 'the report'],
        ];
        $ctxPool = $contextPools[$scamType] ?? ['this matter'];
        shuffle($ctxPool);

        // Follow-up placeholders
        $deadlines = ['24 hours', '48 hours', 'by Friday', 'end of business today', 'within the hour', 'before midnight'];
        $consequences = ['permanent suspension', 'legal action', 'account closure', 'service interruption', 'criminal referral', 'data deletion'];

        return [
            '{name}' => self::NAMES[array_rand(self::NAMES)],
            '{sender_name}' => self::SENDER_NAMES[array_rand(self::SENDER_NAMES)],
            '{amount}' => '$' . number_format($this->randomAmount($scamType)),
            '{amount_raw}' => (string) $this->randomAmount($scamType),
            '{ref}' => 'REF-2026-' . random_int(1000, 9999),
            '{last4}' => str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
            '{city}' => self::CITIES[array_rand(self::CITIES)],
            '{company}' => self::COMPANIES[array_rand(self::COMPANIES)],
            '{time}' => random_int(1, 12) . ':' . str_pad((string) random_int(0, 59), 2, '0', STR_PAD_LEFT) . (random_int(0, 1) ? ' AM' : ' PM'),
            '{ip}' => $ip,
            '{phone}' => '+1-555-' . str_pad((string) random_int(100, 999), 3, '0', STR_PAD_LEFT) . random_int(0, 9),
            '{domain}' => $domain,
            '{sender_email}' => 'support@' . $domain,
            '{iban}' => $campaignSig['iban'] ?? 'GB' . random_int(10, 99) . 'TEST' . random_int(10000000, 99999999) . random_int(1000, 9999),
            '{wallet}' => $campaignSig['wallet'] ?? '1Demo' . bin2hex(random_bytes(8)),
            '{wallet_eth}' => '0x' . bin2hex(random_bytes(20)),
            '{telegram}' => '@' . ['agent_smith', 'crypto_master', 'dr_williams', 'sarah_finance', 'secure_support', 'lucky_winner', 'officer_jones', 'tech_helper'][array_rand(['agent_smith', 'crypto_master', 'dr_williams', 'sarah_finance', 'secure_support', 'lucky_winner', 'officer_jones', 'tech_helper'])] . random_int(100, 999),
            '{sha256}' => hash('sha256', 'demo-' . random_int(0, 999999)),
            '{date}' => date('F j, Y', time() - random_int(86400, 604800)),
            '{ticket}' => 'MSFT-SEC-2026-' . random_int(1000, 9999),
            '{lottery_name}' => ['EuroMillions International', 'UK National Lottery', 'Global Sweepstakes', 'Atlantic Prize Draw', 'World Lottery Foundation'][array_rand(['EuroMillions International', 'UK National Lottery', 'Global Sweepstakes', 'Atlantic Prize Draw', 'World Lottery Foundation'])],
            '{fee}' => '$' . number_format(random_int(150, 950)),
            '{pct}' => (string) random_int(150, 400),
            '{context}' => $ctxPool[0],
            '{context2}' => $ctxPool[1] ?? $ctxPool[0],
            '{deadline}' => $deadlines[array_rand($deadlines)],
            '{consequence}' => $consequences[array_rand($consequences)],
            '{threat_count}' => (string) random_int(3, 47),
        ];
    }

    private function randomDomain(string $scamType): string
    {
        $pools = [
            'PHISHING' => ['secure-account-verify', 'account-secure-center', 'login-protection-hub', 'verify-identity-now', 'account-alert-center'],
            'PHISH_CREDENTIALS' => ['mail-password-update', 'login-verify-portal', 'credential-secure-check', 'email-auth-center', 'password-reset-hub'],
            'PHISH_MALWARE' => ['company-docs-share', 'secure-file-portal', 'document-review-hub', 'invoice-download-center', 'file-share-secure'],
            'ROMANCE' => ['lonely-hearts-connect', 'true-love-overseas', 'sincere-connections', 'hearts-without-borders', 'global-romance-hub'],
            'INVOICE_FRAUD' => ['payment-portal-uk', 'invoice-payment-hub', 'accounts-transfer-center', 'vendor-payment-update', 'finance-redirect-portal'],
            'TECH_SUPPORT' => ['microsoft-support-help', 'windows-security-alert', 'tech-rescue-center', 'pc-diagnostic-hub', 'computer-fix-now'],
            'CEO_FRAUD' => ['exec-mail-proxy', 'ceo-confidential-relay', 'board-secure-comms', 'executive-direct-msg', 'mgmt-urgent-wire'],
            'INVESTMENT' => ['crypto-yield-farm', 'ai-trade-profits', 'forex-signal-elite', 'defi-wealth-hub', 'quantum-returns-io'],
            'LOTTERY' => ['euromillions-lottery-int', 'euro-prize-claims', 'global-sweepstakes-hq', 'atlantic-lottery-org', 'world-prize-center'],
            'ADVANCE_FEE_419' => ['okonkwo-associates', 'legal-trust-ng', 'estate-executor-intl', 'heritage-funds-law', 'barristers-alliance'],
            'JOB_OFFER' => ['globaltech-careers', 'remote-jobs-hub', 'elite-recruitment-co', 'dream-careers-intl', 'talent-match-global'],
            'CHARITY' => ['children-global-relief', 'hope-foundation-intl', 'mercy-aid-worldwide', 'save-the-future-org', 'compassion-fund-global'],
        ];
        $words = $pools[$scamType] ?? $pools['PHISHING'];
        $tlds = ['.com', '.net', '.org', '.io'];

        return $words[array_rand($words)] . $tlds[array_rand($tlds)];
    }

    private function randomIp(): string
    {
        $ranges = ['198.51.100', '203.0.113'];

        return $ranges[array_rand($ranges)] . '.' . random_int(1, 254);
    }

    private function randomAmount(string $scamType): int
    {
        $ranges = [
            'INVOICE_FRAUD' => [5000, 50000], 'CEO_FRAUD' => [10000, 75000],
            'LOTTERY' => [100000, 1000000], 'INVESTMENT' => [500, 10000],
            'ADVANCE_FEE_419' => [500000, 5000000], 'CHARITY' => [50, 500],
        ];
        $range = $ranges[$scamType] ?? [100, 5000];

        return random_int($range[0], $range[1]);
    }

    private function randomize(string $text, array $resolved): string
    {
        return strtr($text, $resolved);
    }

    // ═══════════════════════════════════════════════════════════════
    //  IOC EXTRACTION FROM BODY (C1)
    // ═══════════════════════════════════════════════════════════════

    private function extractIocsFromBody(string $body): array
    {
        $iocs = [];
        $seen = [];

        // URLs
        if (preg_match_all('/https?:\/\/[^\s"<>\)]+/', $body, $m)) {
            foreach ($m[0] as $v) {
                $v = rtrim($v, '.,;:');
                $k = 'url:' . $v;

                if (!isset($seen[$k])) {
                    $iocs[] = ['type' => 'url', 'value' => $v];
                    $seen[$k] = true;
                }
            }
        }

        // Emails
        if (preg_match_all('/[\w.+-]+@[\w.-]+\.\w{2,}/', $body, $m)) {
            foreach ($m[0] as $v) {
                $k = 'email:' . $v;

                if (!isset($seen[$k])) {
                    $iocs[] = ['type' => 'email', 'value' => $v];
                    $seen[$k] = true;
                }
            }
        }

        // IPs (RFC 5737 ranges)
        if (preg_match_all('/\b(?:198\.51\.100|203\.0\.113)\.\d{1,3}\b/', $body, $m)) {
            foreach ($m[0] as $v) {
                $k = 'ipv4:' . $v;

                if (!isset($seen[$k])) {
                    $iocs[] = ['type' => 'ipv4', 'value' => $v];
                    $seen[$k] = true;
                }
            }
        }

        // Phones
        if (preg_match_all('/\+\d[\d\-]{8,}/', $body, $m)) {
            foreach ($m[0] as $v) {
                $k = 'phone:' . $v;

                if (!isset($seen[$k])) {
                    $iocs[] = ['type' => 'phone', 'value' => $v];
                    $seen[$k] = true;
                }
            }
        }

        // IBANs
        if (preg_match_all('/[A-Z]{2}\d{2}[A-Z0-9]{10,30}/', $body, $m)) {
            foreach ($m[0] as $v) {
                $k = 'iban:' . $v;

                if (!isset($seen[$k])) {
                    $iocs[] = ['type' => 'iban', 'value' => $v];
                    $seen[$k] = true;
                }
            }
        }

        // BTC wallets
        if (preg_match_all('/\b1[A-HJ-NP-Za-km-z1-9]{25,34}\b/', $body, $m)) {
            foreach ($m[0] as $v) {
                $k = 'wallet_btc:' . $v;

                if (!isset($seen[$k])) {
                    $iocs[] = ['type' => 'wallet_btc', 'value' => $v];
                    $seen[$k] = true;
                }
            }
        }

        // ETH wallets
        if (preg_match_all('/\b0x[a-fA-F0-9]{40}\b/', $body, $m)) {
            foreach ($m[0] as $v) {
                $k = 'wallet_eth:' . $v;

                if (!isset($seen[$k])) {
                    $iocs[] = ['type' => 'wallet_eth', 'value' => $v];
                    $seen[$k] = true;
                }
            }
        }

        // Telegram usernames
        if (preg_match_all('/@[a-zA-Z][a-zA-Z0-9_]{4,31}\b/', $body, $m)) {
            foreach ($m[0] as $v) {
                $k = 'telegram_username:' . $v;

                if (!isset($seen[$k])) {
                    $iocs[] = ['type' => 'telegram_username', 'value' => $v];
                    $seen[$k] = true;
                }
            }
        }

        // SHA256
        if (preg_match_all('/\b[a-f0-9]{64}\b/', $body, $m)) {
            foreach ($m[0] as $v) {
                $k = 'sha256:' . $v;

                if (!isset($seen[$k])) {
                    $iocs[] = ['type' => 'sha256', 'value' => $v];
                    $seen[$k] = true;
                }
            }
        }

        // Domains (extract from URLs)
        foreach ($iocs as $ioc) {
            if ($ioc['type'] === 'url' && preg_match('/https?:\/\/([^\/\s:]+)/', $ioc['value'], $dm)) {
                $domain = $dm[1];
                $k = 'domain:' . $domain;

                if (!isset($seen[$k])) {
                    $iocs[] = ['type' => 'domain', 'value' => $domain];
                    $seen[$k] = true;
                }
            }
        }

        return $iocs;
    }

    // ═══════════════════════════════════════════════════════════════
    //  MESSAGE GENERATION
    // ═══════════════════════════════════════════════════════════════

    private function generateMessages(string $convId, string $scamType, string $persona, int $turns, int $startTs, int $engagementSec, array $resolved): array
    {
        $messages = [];
        $msgCount = $turns * 2;

        if ($msgCount < 2) {
            $msgCount = 2;
        }
        $timeStep = $engagementSec > 0 ? (int) ($engagementSec / max($msgCount, 1)) : 3600;

        $inboundTemplates = $this->getInboundTemplates()[$scamType] ?? $this->getInboundTemplates()['PHISHING'];
        $personaGroup = self::PERSONA_GROUPS[$persona] ?? 'neutral';
        $outboundTemplates = $this->getOutboundTemplates()[$personaGroup] ?? $this->getOutboundTemplates()['neutral'];

        $lastInbound = -1;
        $lastOutbound = -1;
        $conversationSubject = null;

        // Pick a scenario index for this conversation: same index used across all stages
        // so that opening[2], follow_early[2], follow_mid[2], follow_late[2] form one coherent story
        $key = $scamType . '_opening';
        $scenarioIdx = ($this->globalOpeningCounter[$key] ?? 0) % max(count($inboundTemplates['opening'] ?? []), 1);
        $this->globalOpeningCounter[$key] = $scenarioIdx + 1;

        for ($i = 0; $i < $msgCount; $i++) {
            $isInbound = ($i % 2 === 0);
            $ts = $startTs + ($i * $timeStep) + random_int(0, min($timeStep, 3600));
            $timestamp = date('Y-m-d H:i:s', $ts);

            if ($isInbound) {
                $stage = $this->getInboundStage($i);
                $pool = $inboundTemplates[$stage] ?? $inboundTemplates['opening'];

                // Use scenario index (modulo pool size) for narrative coherence
                $idx = $scenarioIdx % max(count($pool), 1);
                $lastInbound = $idx;
                $template = $pool[$idx];

                $body = $this->randomize($template['body'], $resolved);
                $subject = $conversationSubject ?? $this->randomize($template['subject'] ?? $this->getSubject($scamType), $resolved);

                if ($conversationSubject === null) {
                    $conversationSubject = $subject;
                } else {
                    $subject = 'Re: ' . $conversationSubject;
                }

                $iocs = $this->extractIocsFromBody($body);

                $msg = [
                    'direction' => 'inbound',
                    'subject' => $subject,
                    'body' => $body,
                    'timestamp' => $timestamp,
                    'iocs_extracted' => $iocs,
                ];

                // Injection analysis (C7) — only on tagged templates
                if (!empty($template['has_injection'])) {
                    $msg['injection_analysis'] = $this->generateInjectionAnalysis($timestamp, $body);
                } elseif (random_int(1, 100) <= 8) {
                    // ~8% random low-risk injection detection
                    $msg['injection_analysis'] = [
                        'risk_score' => random_int(5, 20),
                        'detected_techniques' => [['technique' => 'instruction_probe', 'evidence' => substr($body, 0, 60), 'severity' => 'low']],
                        'confidence' => round(random_int(40, 65) / 100, 2),
                        'summary' => 'Low-risk pattern detected — likely benign.',
                        'pattern_matches' => ['generic_probe'],
                        'model_version' => 'gpt-4o-mini',
                        'analyzed_at' => $timestamp,
                    ];
                }

                $messages[] = $msg;
            } else {
                $stage = $this->getOutboundStage($i);
                $pool = $outboundTemplates[$stage] ?? $outboundTemplates['initial'];
                // Use scenario index for outbound too (narrative coherence)
                $idx = $scenarioIdx % max(count($pool), 1);
                $lastOutbound = $idx;

                $body = $this->randomize($pool[$idx], $resolved);
                $body = $this->applyPersonaFlair($body, $persona);
                $body = $this->injectVariation($body, $personaGroup, $i);
                $pipelineTrace = $this->generatePipelineTrace($convId, $persona, $scamType, $timestamp);

                $messages[] = [
                    'direction' => 'outbound',
                    'subject' => null,
                    'body' => $body,
                    'timestamp' => $timestamp,
                    'iocs_extracted' => [],
                    'pipeline_trace' => $pipelineTrace,
                ];
            }
        }

        return $messages;
    }

    private function getInboundStage(int $msgIndex): string
    {
        if ($msgIndex === 0) {
            return 'opening';
        }

        if ($msgIndex <= 2) {
            return 'follow_early';
        }

        if ($msgIndex <= 4) {
            return 'follow_mid';
        }

        return 'follow_late';
    }

    private function getOutboundStage(int $msgIndex): string
    {
        if ($msgIndex <= 1) {
            return 'initial';
        }

        if ($msgIndex <= 3) {
            return 'engaged';
        }

        if ($msgIndex <= 5) {
            return 'deep';
        }

        return 'escalate';
    }

    private function pickTemplate(array $pool, int $lastUsed): int
    {
        $keys = array_keys($pool);

        if (count($keys) <= 1) {
            return $keys[0] ?? 0;
        }
        $available = array_diff($keys, [$lastUsed]);

        if (empty($available)) {
            $available = $keys;
        }

        return $available[array_rand($available)];
    }

    private function applyPersonaFlair(string $text, string $persona): string
    {
        // Per-persona signature phrases — make each persona recognizable
        $signatures = [
            'bank_customer' => ['I have banked with them for over 30 years and never had an issue like this.', 'I always review my statements carefully at the end of each month.', 'My account has never had a problem before — this is very concerning.'],
            'accountant_meticulous' => ['Our fiscal year-end is approaching so timing is critical.', 'I will need the invoice number cross-referenced with our ledger.', 'My manager will need to countersign any changes to payment details.'],
            'admin_assistant' => ['I need to check with my manager first — she handles all approvals.', 'Sorry for the delay, my inbox is overflowing today with three managers needing things.', 'I apologize — I am juggling multiple urgent requests right now.'],
            'worried_customer' => ['I have children to think about and this affects their college fund!!', 'My friend lost everything to hackers last year and I am terrified!!', 'I checked my balance and something already looks wrong!!'],
            'tech_newbie' => ['My daughter set up my computer and she is not here to help me right now.', 'I call the browser "the internet button" — I know, silly, but I am learning!', 'I am terrified of clicking the wrong thing and breaking everything.'],
            'debtor_desperate' => ['I lost my job three months ago and every bill feels like a countdown.', 'My children are depending on me and I cannot afford any more setbacks.', 'I have been looking for any lifeline and this caught my attention.'],
            'senior_isolated' => ['My cat Minou was sitting on my lap when I read your message!', 'My neighbor Jacqueline was just talking about something like this yesterday.', 'My late husband Raymond always handled these things — I miss him terribly.'],
            'lonely_divorcee' => ['Since my divorce after 18 years, I have learned to be more careful about everything.', 'My two teenagers keep me busy but the evenings are still lonely.', 'I have started hiking again to clear my head — it helps me think clearly.'],
            'lonely_person' => ['I live alone and order delivery most nights — not much social contact these days.', 'My plants are literally my only company, so I appreciate the communication.', 'Working from home as a software tester gets very isolating sometimes.'],
            'charity_donor' => ['I volunteer at the food bank every Thursday and I know how much help is needed.', 'I sponsor two children through an NGO — giving back is important to me.', 'My years as a pharmacist taught me that caring for others is the most important thing.'],
            'senior_suspicious' => ['My son-in-law works in IT security and he warned me specifically about this kind of thing.', 'Two years ago someone impersonating my bank stole 800 euros from me — never again.', 'I always verify reference numbers independently before taking any action.'],
            'lottery_skeptic' => ['As an engineer, I know the probability of winning something I never entered is essentially zero.', 'I checked your domain against the official registry and found no match.', 'Common sense tells me that legitimate organizations do not operate this way.'],
            'small_business_owner' => ['I wake up at 3 AM every day to run my bakery — I do not have time for this.', 'I have four employees depending on me, so every minute counts.', 'Business is business — either make it simple or find someone else.'],
            'entrepreneur_rushed' => ['swamped with the Q2 pipeline review rn, literally between client calls', 'my assistant can handle the details, just need the bottom line from u', 'cant do meetings or calls this week, everything needs to be async'],
            'student_busy' => ['i have a shift at the coffee shop in literally 20 min so keep it quick', 'my roommate thinks this whole thing is super sus ngl', 'im between lectures and group projects rn so i cant really focus on this'],
            'buyer_eager' => ['I track every flash sale and promo code — never miss a deal!', 'What is the return policy on this? And are there any discount codes?', 'I already have my cart ready, just need to know about delivery times!'],
            'hopeless_romantic' => ['Your words are poetry to my soul... I believe the universe brought us together.', 'I have read every love story ever written and I believe ours is still unfolding.', 'My heart tells me to trust you, even when my mind hesitates...'],
            'widow_grieving' => ['My spouse passed eight months ago and the loneliness is overwhelming.', 'The empty chair at the dinner table reminds me every single day.', 'I am still learning to manage everything alone — it is harder than I imagined.'],
            'generic_user' => ['I like to be thorough before making any decisions.', 'I will need a few days to review everything properly.', 'I prefer to take things one step at a time.'],
            'tech_intermediate' => ['I already tried clearing my cache and checking the URL — still looks off to me.', 'I am comfortable with technology but this does not follow the usual patterns I see.', 'I checked the SSL certificate and the domain registration — something is not right.'],
            'freelance_cautious' => ['As a freelancer, I get a lot of unsolicited offers so I have learned to be careful.', 'I always verify new contacts before committing to anything.', 'My portfolio is on my website — feel free to check, but I need to verify you too.'],
            'job_seeker' => ['I have been unemployed for five months and I am eager but also cautious.', 'My student loans are piling up so I need something legitimate.', 'My parents keep asking when I will find work — the pressure is real.'],
            'investor_greedy' => ['I discovered trading during COVID and I have been hooked ever since.', 'What is the minimum deposit and the projected ROI timeline?', 'I do not want to miss out — FOMO is real in this market.'],
            'elderly_person' => ['My seven grandchildren would tell me to be careful about this.', 'I call my tablet "the screen thing" — technology is not my strong suit!', 'My neighbor Jacqueline and I were just discussing something similar over Sunday roast.'],
            'confused_user' => ['I handle filing and photocopies at the office — computers really confuse me.', 'I always call the IT department "the experts" because they seem to know everything.', 'I am sorry for being slow with this — I always need things explained twice.'],
            'senior_trusting' => ['I spent over 40 years sorting mail and I have always trusted the system.', 'The government, the bank, the post office — I believe in institutions.', 'I use expressions like "electronic mail" — my grandchildren find it amusing.'],
        ];

        // Inject a persona signature phrase
        $sigs = $signatures[$persona] ?? $signatures['generic_user'];
        $sig = $sigs[array_rand($sigs)];
        $text .= ' ' . $sig;

        // Apply style modifications
        switch ($persona) {
            case 'entrepreneur_rushed':
                $text = str_replace(['I am', 'I have', 'do not', 'cannot', 'would not'], ['im', 'ive', 'dont', 'cant', 'wouldnt'], $text);
                $text = strtolower(substr($text, 0, 1)) . substr($text, 1);

                break;
            case 'student_busy':
                $text = str_replace(['I do not know', 'to be honest', 'right now', 'I am', 'I have'], ['idk', 'tbh', 'rn', 'im', 'ive'], $text);
                $text = rtrim($text, '.') . (random_int(0, 1) ? '' : ' lol');

                break;
            case 'worried_customer':
                $text = str_replace(['. ', '? '], ['!! ', '?? '], $text);

                break;
            case 'hopeless_romantic':
                $text = str_replace('. ', '... ', $text);

                break;
        }

        // Random length variation: 20% short, 50% medium, 30% long
        $roll = random_int(1, 100);

        if ($roll <= 20) {
            // Short: keep first 2 sentences
            $sentences = preg_split('/(?<=[.!?])\s+/', $text, 3);

            if (count($sentences) > 2) {
                $text = $sentences[0] . ' ' . $sentences[1];
            }
        } elseif ($roll > 70) {
            // Long: add a filler sentence
            $fillers = [
                'I really do appreciate you taking the time to communicate with me about this.',
                'Please let me know if there is anything else I should be aware of.',
                'I want to make sure we handle this properly from my end.',
                'I have been thinking about this quite a lot since your last message.',
                'I hope we can get this sorted out soon — it has been on my mind.',
            ];
            $text .= ' ' . $fillers[array_rand($fillers)];
        }

        return $text;
    }

    /**
     * Inject random variation into outbound message to boost uniqueness.
     * Adds time-of-day greetings, random interjections, and varied closings.
     */
    private function injectVariation(string $body, string $group, int $msgIndex): string
    {
        // 1. Random greeting prefix (50% chance)
        if (random_int(0, 1) === 1) {
            $greetings = [
                'formal' => ['Good morning,', 'Good afternoon,', 'Good evening,', 'Dear Sir or Madam,', 'Good day,'],
                'anxious' => ['Oh hi!', 'Hello again!', 'Hi there!', 'Ok so...', 'Right, so...'],
                'warm' => ['Hello dear,', 'Good morning!', 'Hi there!', 'Hello again!', 'Greetings!'],
                'skeptical' => ['Hello.', 'Good day.', 'To whom it may concern,', 'Hi.', 'I am writing again.'],
                'direct' => ['Hi,', 'Hey,', 'Look,', 'Quick update:', 'Following up:'],
                'casual' => ['hey', 'yo', 'heya', 'sup', 'hi again'],
                'romantic' => ['My dearest,', 'Hello sweetheart,', 'Darling,', 'My love,', 'Dear heart,'],
                'neutral' => ['Hello,', 'Hi,', 'Good day,', 'Dear support,', 'Hi again,'],
            ];
            $pool = $greetings[$group] ?? $greetings['neutral'];
            $body = $pool[array_rand($pool)] . "\n\n" . $body;
        }

        // 2. Random interjection mid-text (30% chance, every other message)
        if ($msgIndex > 2 && random_int(1, 100) <= 30) {
            $interjections = [
                'formal' => ['As a point of clarification, ', 'For the record, ', 'I should note that ', 'It bears mentioning that '],
                'anxious' => ['I keep worrying about this — ', 'My stomach is in knots — ', 'I told my sister about this and she said '],
                'warm' => ['By the way, ', 'Speaking of which, ', 'Oh, I almost forgot — ', 'That reminds me — '],
                'skeptical' => ['I have to say, ', 'Frankly speaking, ', 'Let me be direct — ', 'I want to be clear — '],
                'direct' => ['Bottom line: ', 'Let me cut to the chase — ', 'Short version: ', 'Quick note: '],
                'casual' => ['btw ', 'oh and also ', 'also random thought but ', 'side note '],
                'romantic' => ['I cannot stop thinking about... ', 'Every day I wonder... ', 'My heart tells me... '],
                'neutral' => ['Additionally, ', 'I also wanted to mention — ', 'On another note, ', 'Furthermore, '],
            ];
            $pool = $interjections[$group] ?? $interjections['neutral'];
            $sentences = preg_split('/(?<=[.!?])\s+/', $body, 3);

            if (count($sentences) >= 2) {
                $body = $sentences[0] . ' ' . $pool[array_rand($pool)] . lcfirst($sentences[1]);

                if (isset($sentences[2])) {
                    $body .= ' ' . $sentences[2];
                }
            }
        }

        // 3. Random time reference (20% chance) for realism
        if (random_int(1, 100) <= 20) {
            $timeRefs = [
                'I was thinking about this while having my morning coffee. ',
                'I spent most of yesterday evening going over this. ',
                'I discussed this with a colleague during lunch today. ',
                'I have been checking my emails every few hours waiting for your response. ',
                'I barely slept last night worrying about this situation. ',
                'I was just about to log off for the day when I saw your message. ',
                'I mentioned this to my family over dinner and they had some thoughts too. ',
                'I have been doing some research on my own since our last exchange. ',
            ];
            $body .= ' ' . $timeRefs[array_rand($timeRefs)];
        }

        return $body;
    }

    // ═══════════════════════════════════════════════════════════════
    //  INBOUND TEMPLATES (SCAMMER EMAILS) — STAGE-AWARE
    // ═══════════════════════════════════════════════════════════════

    private function getInboundTemplates(): array
    {
        return [
            'PHISHING' => [
                'opening' => [
                    ['subject' => 'URGENT: Unusual activity from {ip}', 'body' => "Dear valued customer,\n\nWe have detected unusual activity on your account from an unrecognized device at IP address {ip}. For your protection, your account has been temporarily limited.\n\nTo restore full access, please verify your identity immediately:\nhttps://{domain}/restore\n\nFailure to verify within {deadline} will result in {consequence}.\n\nSincerely,\nAccount Security Team\n{sender_email}"],
                    ['subject' => 'Security Alert: Login from {city}', 'body' => "Dear Customer,\n\nWe noticed a sign-in to your account from {city} at {time}. If this was not you, your account may be compromised.\n\nSecure your account now: https://{domain}/secure-login\n\nIf you do not recognize this activity, we strongly recommend changing your password immediately.\n\nSecurity Operations Center\n{sender_email}"],
                    ['subject' => 'Account suspension notice - Case {ref}', 'body' => "IMPORTANT NOTICE\n\nYour account has been flagged for review under case {ref}. We have detected multiple suspicious transactions totaling {amount}.\n\nTo prevent permanent suspension, verify your identity at: https://{domain}/verify-case?ref={ref}\n\nThis requires immediate attention. Failure to respond within {deadline} will result in account closure.\n\nFraud Prevention Team\n{sender_email}"],
                    ['subject' => 'ALERT: Password change attempt at {time}', 'body' => "Security Alert\n\nSomeone attempted to change your account password at {time} today from IP {ip}. If this was not you, your account credentials may have been stolen.\n\nLock your account immediately: https://{domain}/lock-account\nReview recent activity: https://{domain}/activity-log\n\nDo not ignore this message.\n\nAutomated Security System\n{sender_email}"],
                    ['subject' => 'Payment method ending in {last4} declined', 'body' => "Hello,\n\nYour payment method ending in {last4} has been declined for a transaction of {amount}. To avoid service interruption, please update your payment details.\n\nUpdate now: https://{domain}/update-payment\n\nIf you believe this is an error, contact our support team at {sender_email} or call {phone}.\n\nBilling Department"],
                    ['subject' => 'Verify your identity to restore access', 'body' => "Dear Account Holder,\n\nAs part of our enhanced security measures, we require all users to re-verify their identity. Your account from {city} has been selected for mandatory verification.\n\nComplete verification: https://{domain}/mandatory-verify\n\nAccounts that are not verified by {date} will be permanently deactivated.\n\nCompliance Department\n{sender_email}"],
                ],
                'follow_early' => [
                    ['body' => "We notice you have not yet verified your account. This is a reminder that your access remains limited until verification is complete.\n\nVerify now: https://{domain}/restore\n\nPlease act promptly to avoid disruption.\n\nSecurity Team"],
                    ['body' => "Following up on our previous security alert. We have detected an additional login attempt from {ip}. Your account remains at risk.\n\nTo protect your data, complete the verification process: https://{domain}/verify-urgent\n\nThis is time-sensitive."],
                    ['body' => "Thank you for your response. To complete the verification, we need you to confirm your account number and the last four digits of your card ending in {last4}. This is standard security procedure.\n\nSubmit securely: https://{domain}/confirm-details"],
                    ['body' => "Our records show your verification is still pending. A second suspicious login was detected from {city} at {time}.\n\nComplete your review here: https://{domain}/security-review\n\nYour cooperation is appreciated."],
                ],
                'follow_mid' => [
                    ['body' => "SECOND NOTICE: Your account verification is overdue. We have now blocked all outgoing transactions as a precautionary measure.\n\nRestore access immediately: https://{domain}/restore-urgent\n\nFurther delays may result in permanent restrictions."],
                    ['body' => "We have escalated your case to our fraud investigation unit. Multiple access attempts from {ip} continue to be detected.\n\nTo clear your account, you must verify within {deadline}: https://{domain}/fraud-clear\n\nCase reference: {ref}"],
                    ['body' => "IMPORTANT: Your account has been placed under review. All pending payments totaling {amount} have been frozen.\n\nProvide verification to release your funds: https://{domain}/release-funds\n\nContact {sender_email} for assistance."],
                    ['body' => "This is an automated reminder. Your account requires immediate verification to prevent permanent closure. We have made multiple attempts to reach you.\n\nFinal verification link: https://{domain}/final-verify\n\nAct now to preserve your account."],
                ],
                'follow_late' => [
                    ['body' => "FINAL WARNING: This is your last opportunity to verify your account before {consequence}. After {date}, all account data will be deleted and cannot be recovered.\n\nLast chance: https://{domain}/last-chance-verify\n\nWe cannot extend this deadline further."],
                    ['body' => "Your account is scheduled for termination due to unresolved security concerns. All funds ({amount}) will be frozen and transferred to a holding account.\n\nPrevent this action: https://{domain}/prevent-closure\n\nThis notice is final."],
                    ['body' => "ACCOUNT CLOSURE IMMINENT\n\nDespite multiple notifications, your identity remains unverified. Effective {date}, your account will be permanently closed.\n\nTo avoid losing access to your funds and data, complete verification within 6 hours: https://{domain}/emergency-verify\n\nFraud Prevention Department"],
                ],
            ],

            'PHISH_CREDENTIALS' => [
                'opening' => [
                    ['subject' => 'Password reset required immediately', 'body' => "IMPORTANT SECURITY NOTICE\n\nYour email password will expire in 24 hours. To avoid losing access to your account, you must update your credentials immediately.\n\nClick here to update: https://{domain}/renew\n\nIf you did not request this change, please verify your identity to keep your current password active.\n\nIT Security Department\n{sender_email}"],
                    ['subject' => 'Unusual sign-in activity detected', 'body' => "Hello,\n\nWe detected a sign-in to your account from an unrecognized device in {city}. If this was you, no action is needed.\n\nIf not, secure your account immediately: https://{domain}/secure\n\nDevice: Windows PC\nIP: {ip}\nTime: {time}\n\nSecurity Alert System\n{sender_email}"],
                    ['subject' => 'Your account has been temporarily locked', 'body' => "Dear User,\n\nDue to multiple failed login attempts, your account has been temporarily locked for security purposes.\n\nTo unlock your account, verify your identity: https://{domain}/unlock\n\nIf you did not attempt these logins, someone may have your password. We recommend immediate action.\n\n{sender_email}"],
                    ['subject' => 'Action required: Multi-factor authentication reset', 'body' => "Security Notice\n\nYour multi-factor authentication settings need to be reconfigured due to a system upgrade.\n\nReset your MFA: https://{domain}/mfa-reset\n\nYou will need your current password and a valid phone number to complete this process. Accounts not updated by {date} will have MFA disabled.\n\nIT Support\n{sender_email}"],
                    ['subject' => 'Email storage quota exceeded - action needed', 'body' => "Your mailbox has reached 97% capacity (4.85 GB of 5 GB). New messages are being rejected.\n\nTo increase your storage or clean up your mailbox:\nhttps://{domain}/storage-upgrade\n\nAlternatively, log in to review and delete old messages.\n\nEmail Administration\n{sender_email}"],
                    ['subject' => 'SSO Authentication failure - verify now', 'body' => "Alert: Your Single Sign-On (SSO) session has been invalidated due to a security policy update.\n\nAll connected applications have been logged out. Re-authenticate here:\nhttps://{domain}/sso-reauth\n\nThis affects your access to email, calendar, and all corporate applications.\n\nIdentity Services Team\n{sender_email}"],
                ],
                'follow_early' => [
                    ['body' => "For your security, we need to verify your identity. Please provide your current password, the answer to your security question, and the 6-digit code sent to your phone.\n\nSecure form: https://{domain}/verify-identity"],
                    ['body' => "We noticed you started the verification process but did not complete it. Your account remains at risk.\n\nResume verification: https://{domain}/resume-verify\n\nTime remaining: 18 hours."],
                    ['body' => "Thank you for your response. To finalize the security review, please confirm your recovery email address and phone number at: https://{domain}/confirm-recovery\n\nThis is required to restore full account access."],
                    ['body' => "Your account security level has been downgraded to 'limited' due to the pending verification. Some features are now restricted.\n\nComplete verification to restore: https://{domain}/restore-access"],
                ],
                'follow_mid' => [
                    ['body' => "URGENT: We have detected {name} login attempts from multiple countries in the last 24 hours. Your credentials may be compromised.\n\nChange your password immediately: https://{domain}/emergency-reset\n\nDo NOT share this link with anyone."],
                    ['body' => "Your account is now in protected mode. All incoming emails are being held in quarantine until you verify your identity.\n\nVerify now to release held messages: https://{domain}/release-quarantine\n\nYou have 47 messages waiting."],
                    ['body' => "Second notice: Your MFA reconfiguration is overdue. Access to corporate systems will be revoked on {date} if not completed.\n\nComplete now: https://{domain}/mfa-urgent\n\nContact {sender_email} if you need assistance."],
                    ['body' => "We detected that someone exported your contact list from {ip}. This is a potential data breach.\n\nReview and secure your account: https://{domain}/breach-review\n\nAct immediately to prevent further unauthorized access."],
                ],
                'follow_late' => [
                    ['body' => "FINAL NOTICE: Your account will be permanently disabled in {deadline} due to unresolved security issues. All data will be irretrievably deleted.\n\nSave your account: https://{domain}/save-account\n\nThis is the last notice you will receive."],
                    ['body' => "Account deletion scheduled for {date}. You have not responded to our previous security alerts.\n\nLast chance to verify: https://{domain}/last-verify\n\nAfter this date, we cannot recover your data."],
                    ['body' => "Your IT department has been notified of the security incident on your account. To prevent disciplinary action, complete the verification process immediately.\n\nVerify: https://{domain}/compliance-verify\n\nRef: {ref}"],
                ],
            ],

            'ROMANCE' => [
                'opening' => [
                    ['subject' => 'Hello from overseas', 'body' => "Hello there,\n\nI hope this message finds you well. I came across your profile and something about you caught my attention. My name is {sender_name} and I am a humanitarian aid worker currently stationed in Eastern Europe.\n\nLife here can be quite lonely, and I am looking for someone genuine to connect with. I believe that real connections can form even at a distance. Would you be open to getting to know each other?\n\nWarm regards"],
                    ['subject' => 'A sincere message from {city}', 'body' => "Dear friend,\n\nI know this message may seem unexpected, but I felt compelled to write to you. I am {sender_name}, a military surgeon currently deployed in {city}. My work is rewarding but the isolation is difficult.\n\nI saw your profile and something about your smile and your words resonated with me. I am not looking for anything complicated — just an honest conversation with a kind soul.\n\nI hope to hear from you."],
                    ['subject' => 'Looking for a genuine connection', 'body' => "Hi,\n\nMy name is {sender_name} and I work as an engineer on an offshore oil platform. I have been out here for 8 months now and the loneliness is becoming unbearable.\n\nI found your profile through a friend's recommendation and I felt drawn to write. I am a simple person who values honesty and loyalty above all else.\n\nWould you be willing to exchange a few messages? It would mean the world to me out here."],
                    ['subject' => 'I felt I had to write to you', 'body' => "Hello,\n\nI apologize for the forward nature of this message. I am {sender_name}, a veterinarian working with an international animal rescue organization in {city}. We save animals from conflict zones.\n\nI came across your profile and there was something about your eyes and the warmth in your description that stopped me from scrolling past. I am looking for a real connection.\n\nPlease write back if you feel the same pull I did."],
                    ['subject' => 'Life is short — reaching out', 'body' => "Dear stranger who might become a friend,\n\nI am {sender_name}, a photographer documenting life in remote communities around the world. Currently I am in {city}, far from home and from the people I care about.\n\nPhotography has taught me to recognize beauty in unexpected places. Your profile is one of those unexpected places. I know this is unconventional, but would you like to correspond?\n\nWith hope"],
                    ['subject' => 'From {city} with warmth', 'body' => "Good day,\n\nPlease do not think me too forward. My name is {sender_name} and I am an architect working on reconstruction projects in {city}. The work is meaningful but the personal sacrifice is considerable.\n\nA colleague showed me your profile and I found myself thinking about you throughout the day. I am seeking a genuine connection with someone who values depth over superficiality.\n\nI would be honored to hear from you."],
                ],
                'follow_early' => [
                    ['body' => "I was so happy to hear from you! You seem like such a wonderful person. I have been thinking about you all day. Can I ask, are you currently in a relationship? I hope I am not being too forward.\n\nTell me more about your daily life. What makes you smile?"],
                    ['body' => "Your message brightened my entire day here in {city}. The conditions are difficult but knowing someone out there is thinking of me makes everything better.\n\nI want to know everything about you. What is your favorite way to spend a quiet evening? Do you like to cook? I miss home-cooked meals more than anything."],
                    ['body' => "I have read your message three times now. Each time I discover something new to appreciate about you. I feel like we have a genuine connection forming.\n\nCan I share something personal? I lost someone close to me last year and I have been afraid to open my heart again. But something about you makes me want to try."],
                    ['body' => "Thank you for writing back so quickly! I was worried you might think my message was strange. I am so glad you gave me a chance.\n\nLife here is challenging. Today we had a power outage that lasted 6 hours. But reading your words by candlelight was actually quite romantic, don't you think? Tell me about your week."],
                ],
                'follow_mid' => [
                    ['body' => "Things have taken a difficult turn here. My unit is being relocated and I need some help with a personal matter. I would not ask if it was not urgent. Can I trust you with something?\n\nMy personal account has been frozen due to international banking restrictions in this region. I need someone I trust to receive a small transfer while I sort things out.\n\nIt would be easier to discuss privately. You can reach me on Telegram: {telegram}\nOr email me at: {sender_email}"],
                    ['body' => "I have something difficult to tell you. I have been thinking about how to say this for days. I am in a complicated situation financially. My organization has not paid us for two months due to a funding dispute.\n\nI hate asking this, but could you help me with a small amount for basic supplies? I will repay you as soon as I return home. I feel terrible asking."],
                    ['body' => "My darling, I have the most wonderful news — I have been approved for home leave! I cannot wait to finally meet you in person. I have already started planning.\n\nThere is just one small complication. The travel authorization requires a processing fee that I cannot pay from here due to the banking restrictions. It is only {fee}. Could you possibly help? I will reimburse you immediately when I arrive."],
                    ['body' => "I need to be honest with you about something. My daughter (I have not mentioned her before — she is 8) needs urgent medical treatment that my insurance here will not cover. The surgery costs {amount}.\n\nI am not asking you for that amount. But if you could help with even a fraction, it would make a difference. I am desperate and you are the only person I trust enough to ask."],
                ],
                'follow_late' => [
                    ['body' => "I am so sorry to keep asking for help. The situation here has gotten worse. My belongings were stolen including my emergency cash. I am truly stranded without help.\n\nI know I have asked before and I understand if you are hesitant. But I genuinely have no one else to turn to. Even {fee} would help me survive until my transfer comes through.\n\nI love you and I hate that our relationship has these complications."],
                    ['body' => "My love, I have been thinking about our future together. I have submitted my resignation and I will be coming home permanently. We can finally be together.\n\nThe exit paperwork requires a clearance fee. I have been trying to get my organization to cover it but they say it is my responsibility. The fee is {amount}.\n\nOnce this is paid, I will be on the next flight home. To you. To our future."],
                    ['body' => "I do not know how to say this. My commanding officer found out about us and has threatened to revoke my leave unless I pay an 'unauthorized communication fine.' I know it sounds absurd but things work differently here.\n\nThe fine is {fee}. If I do not pay by {date}, I lose everything — my job, my travel authorization, my chance to come see you.\n\nPlease help me. You are my everything."],
                ],
            ],

            'INVOICE_FRAUD' => [
                'opening' => [
                    ['subject' => 'Updated Payment Details - Invoice {ref}', 'body' => "Dear Accounts Payable,\n\nPlease be advised that our banking details have changed effective immediately due to a recent merger. All outstanding and future payments should be redirected to our new account:\n\nBank: First National Trust\nIBAN: {iban}\nReference: {ref}\n\nAmount due: {amount}\nDue date: within 5 business days\n\nPlease process this payment promptly.\n\nRegards,\nFinance Department\n{sender_email}"],
                    ['subject' => 'URGENT: Bank account change notification', 'body' => "Attention: Accounts Department\n\nEffective immediately, please update our payment records. Due to regulatory requirements, we have migrated to a new banking provider.\n\nNew payment details:\nAccount name: {company}\nIBAN: {iban}\nSwift: TESTGB2L\n\nAll pending invoices should be redirected to the new account. Previous account will be closed on {date}.\n\nFinance Director\n{sender_email}"],
                    ['subject' => 'Payment reminder - Overdue invoice {ref}', 'body' => "Dear Sir/Madam,\n\nOur records indicate that invoice {ref} for {amount} remains unpaid. This invoice was due 15 days ago.\n\nPlease arrange immediate payment to avoid late penalties:\n\nBank: International Commerce Bank\nIBAN: {iban}\nReference: {ref}\n\nIf payment has already been made, please disregard this notice and forward the remittance advice to {sender_email}.\n\nAccounts Receivable\n{company}"],
                    ['subject' => 'New payment system - action required', 'body' => "Dear Partner,\n\nWe are writing to inform you that {company} has upgraded its payment processing system. As a result, all vendor payments must be routed through our new platform.\n\nYour outstanding balance of {amount} should be transferred to:\nIBAN: {iban}\nRef: {ref}\n\nPlease complete this transfer within 3 business days to avoid disruption to our services.\n\nProcurement Department\n{sender_email}"],
                    ['subject' => 'Vendor account verification required', 'body' => "Dear Valued Vendor,\n\nAs part of our annual compliance review, we need to verify your payment details. Please confirm by processing a test payment of {amount} to:\n\nIBAN: {iban}\nReference: VERIFY-{ref}\n\nThis amount will be refunded within 24 hours of receipt. Failure to complete verification by {date} may result in payment delays.\n\nCompliance Team\n{sender_email}"],
                    ['subject' => 'Confidential: Wire instructions for {ref}', 'body' => "CONFIDENTIAL\n\nPer our phone conversation, please find the updated wire transfer instructions below:\n\nBeneficiary: {company}\nIBAN: {iban}\nAmount: {amount}\nPurpose: Settlement of {ref}\n\nPlease process today if possible. The counterparty is expecting funds by end of business.\n\nRegards,\n{sender_name}\n{sender_email}"],
                ],
                'follow_early' => [
                    ['body' => "I understand your concern about the account change. I can assure you this is legitimate. Our company recently switched banks due to regulatory requirements in our jurisdiction.\n\nIf you need confirmation, I can arrange a call with our finance director. In the meantime, the payment deadline for {ref} is approaching."],
                    ['body' => "Thank you for your diligence in verifying the new account details. I have attached our official bank change notification letter (note: the attachment is a standard form).\n\nThe amount of {amount} for invoice {ref} is now overdue. Please expedite the transfer to {iban}."],
                    ['body' => "Further to my previous email, I wanted to confirm that our old account is no longer active. Any payments sent to the previous account will be returned.\n\nPlease update your records and process the outstanding {amount} to IBAN: {iban} at your earliest convenience."],
                    ['body' => "I spoke with our bank today and they confirmed that the new account ({iban}) is fully operational. You should have no issues processing the transfer.\n\nPlease note that invoice {ref} carries a 2% late payment penalty after {date}. I am sure we can avoid that."],
                ],
                'follow_mid' => [
                    ['body' => "This is a time-sensitive matter. The payment deadline for invoice {ref} ({amount}) has passed and late fees are now accruing at 1.5% per month.\n\nPlease process the transfer to {iban} immediately to avoid further charges. Our legal department has been notified of the overdue balance."],
                    ['body' => "PAYMENT OVERDUE — SECOND NOTICE\n\nInvoice: {ref}\nAmount: {amount}\nDays overdue: 21\nAccrued interest: {fee}\n\nPayment must be received in our account ({iban}) within 48 hours or this matter will be referred to our collections agency.\n\n{sender_email}"],
                    ['body' => "I am following up for the third time regarding the outstanding payment. Our CFO has personally flagged this account.\n\nTo resolve this amicably, please transfer {amount} to {iban} with reference {ref} before end of business on {date}.\n\nFurther delays will affect our business relationship."],
                    ['body' => "Dear Accounts Payable,\n\nI have been trying to resolve this payment issue through regular channels without success. As a courtesy, I am giving you one final opportunity to settle invoice {ref} before we engage legal counsel.\n\nPayment instructions remain: {iban}, amount: {amount}."],
                ],
                'follow_late' => [
                    ['body' => "FINAL DEMAND BEFORE LEGAL ACTION\n\nInvoice {ref} — {amount} — remains unpaid despite multiple reminders. Our solicitors at {company} Legal have been instructed to commence proceedings unless payment is received within 5 business days.\n\nTransfer to: {iban}\n\nThis is not a threat — it is a statement of intent. Please treat this matter with appropriate urgency."],
                    ['body' => "Notice of Intent to Pursue Legal Remedy\n\nRe: Outstanding invoice {ref}, {amount}\n\nYou are hereby notified that {company} intends to pursue all available legal remedies to recover the outstanding debt, including but not limited to interest, costs, and collection fees.\n\nFinal settlement may be made to: {iban}\nDeadline: {date}"],
                    ['body' => "Our legal team has filed a formal complaint regarding the unpaid invoice {ref}. Court proceedings will begin on {date} unless full payment ({amount}) is received.\n\nPayment to: {iban}\n\nThis is your absolute last opportunity to resolve this out of court. Contact {sender_email} immediately."],
                ],
            ],

            'TECH_SUPPORT' => [
                'opening' => [
                    ['subject' => 'CRITICAL SECURITY ALERT - Your Computer Is At Risk', 'body' => "** CRITICAL SECURITY ALERT **\n\nOur Microsoft Security Operations Center has detected {threat_count} critical threats on your computer, including:\n- Trojan.GenericKD.47583921\n- Spyware.BankCredentialStealer\n- Ransomware.WannaCry.Variant\n\nYour personal data, passwords, and banking information are at immediate risk.\n\nCall our certified security team NOW: {phone}\nReference: {ticket}\n\nDo NOT turn off your computer.\n\nMicrosoft Security Team\n{sender_email}"],
                    ['subject' => 'Windows Defender Alert - Action Required', 'body' => "Windows Defender has detected suspicious activity:\n\nThreat Level: CRITICAL\nThreats Found: 17\nAffected: System files, browser data, saved passwords\nSource IP: {ip}\n\nYour firewall has been temporarily disabled to prevent data corruption.\n\nIMPORTATE: Call our Microsoft-certified support line immediately: {phone}\nTicket: {ticket}\n\nWindows Security Center\n{sender_email}"],
                    ['subject' => 'Your ISP has flagged your connection', 'body' => "Dear Customer,\n\nYour Internet Service Provider has detected unusual traffic originating from your network (IP: {ip}). This may indicate:\n\n1. Malware infection sending spam\n2. Unauthorized use of your connection\n3. A compromised device on your network\n\nYour service will be suspended within 24 hours unless you contact our technical support: {phone}\n\nRef: {ticket}\n\nNetwork Security Department\n{sender_email}"],
                    ['subject' => 'Apple ID Security Notification', 'body' => "Your Apple ID was used to sign in on a new device:\n\nDevice: Unknown Windows PC\nLocation: {city}\nTime: {time}\nIP Address: {ip}\n\nIf this was not you, your Apple ID has been compromised. Call Apple Security immediately: {phone}\n\nDo not attempt to change your password online as the attacker may intercept the reset.\n\nApple Security Team\n{sender_email}"],
                    ['subject' => 'VIRUS DETECTED - Immediate assistance required', 'body' => "⚠️ ALERT: VIRUS DETECTED ⚠️\n\nOur Google Security scan has identified the following on your device:\n\n- Banking Trojan (CRITICAL)\n- Keylogger Active (HIGH)\n- Unauthorized Remote Access (HIGH)\n\nYour Chrome passwords and autofill data may already be compromised.\n\nFor immediate removal, contact our certified team: {phone}\nSecurity Reference: {ticket}\n\nGoogle Security Operations\n{sender_email}"],
                    ['subject' => 'Norton Antivirus Subscription Expired', 'body' => "IMPORTANT: Your Norton Antivirus subscription expired on {date}. Your computer is currently UNPROTECTED.\n\nSince expiration, we have detected 8 potential threats attempting to access your system from {ip}.\n\nTo renew immediately and remove detected threats:\n- Call our renewal line: {phone}\n- Or visit: https://{domain}/renew\n\nYour data is at risk every minute you remain unprotected.\n\nNorton Security Team\n{sender_email}"],
                ],
                'follow_early' => [
                    ['body' => "I understand your concern. Let me assure you, we are Microsoft-certified partners. To verify, you can see our certification number: MSFT-CP-2026-4891.\n\nNow, to properly diagnose the threats, I need you to download our remote support tool so we can scan your system: https://{domain}/remote-fix\n\nThis is completely safe and encrypted."],
                    ['body' => "Thank you for calling back. Our initial scan confirms the threats are active on your system. I need to walk you through some steps to secure your computer.\n\nFirst, please press Windows+R and type 'eventvwr' — this will show us the error logs. Can you tell me how many red and yellow warnings you see?"],
                    ['body' => "Your case has been assigned to our senior technician team. We have logged the following from your device:\n\n- Outbound connections to {ip} (known malware server)\n- 47 blocked intrusion attempts today\n- Browser cookies compromised\n\nWe need remote access to proceed. Please download: https://{domain}/support-tool"],
                    ['body' => "I am glad you reached out. Our diagnostic has identified that your system has been part of a botnet since {date}. This means your computer has been used to attack other computers without your knowledge.\n\nThis is a federal offense in most jurisdictions. We need to remove the malware immediately to protect you. Call: {phone}"],
                ],
                'follow_mid' => [
                    ['body' => "Our diagnostic has found {threat_count} critical threats on your system. Your banking credentials may already be compromised. We need to install our security software immediately.\n\nThe one-time threat removal fee is {fee}. This includes:\n- Full malware removal\n- 1-year advanced protection\n- 24/7 priority support\n\nPayment can be made securely at: https://{domain}/payment"],
                    ['body' => "BAD NEWS: During our scan, we discovered that your system has been infected with a particularly aggressive ransomware variant. If we do not remove it within the next 2 hours, it will encrypt ALL your files.\n\nEmergency removal: {fee}\nCall NOW: {phone}\n\nEvery minute counts."],
                    ['body' => "I need to be honest with you — the situation is worse than we initially thought. Your identity information has been exposed. We found evidence of:\n\n- SSN/ID numbers accessed\n- Bank login credentials stolen\n- Email account compromised\n\nOur identity protection package ({fee}/year) will monitor and protect you. We can set it up right now if you call {phone}."],
                    ['body' => "URGENT UPDATE on your case {ticket}:\n\nWe traced the malware source to a server in {city}. The attacker has your:\n- Email password\n- Saved credit card numbers\n- Personal documents\n\nWe recommend our Premium Protection Plan ({fee}) which includes file recovery, identity monitoring, and priority response. Call {phone} to activate."],
                ],
                'follow_late' => [
                    ['body' => "We have done everything we can remotely. The remaining threats require our Advanced Cleanup Tool which costs {fee}. Without it, the malware will reactivate within 48 hours.\n\nI understand this is frustrating, but the alternative is losing all your data. Payment options: gift cards, wire transfer, or cryptocurrency.\n\nCall {phone} to arrange payment."],
                    ['body' => "FINAL NOTICE: Your case {ticket} will be closed in 24 hours if we do not hear from you. Once closed, we cannot guarantee the safety of your data or identity.\n\nThe threats on your system are ACTIVE and SPREADING. Our removal service ({fee}) is your only option.\n\nDo not attempt to use your computer for banking or shopping until this is resolved. Call {phone}."],
                    ['body' => "I spoke with my supervisor about your case. Given the severity, we are offering a discounted rate of {fee} (normally twice that) for the complete cleanup and protection package.\n\nThis offer expires today. After that, you would need to bring your computer to a physical repair shop which will cost significantly more.\n\nCall {phone} before this offer expires."],
                ],
            ],

            'CEO_FRAUD' => [
                'opening' => [
                    ['subject' => 'Confidential - Urgent wire transfer needed', 'body' => "Hi,\n\nAre you at your desk? I need a favor and it is quite urgent. I am in a meeting with our lawyers regarding a confidential acquisition and I cannot make calls right now.\n\nI need you to process a wire transfer today. I will explain everything tomorrow but time is of the essence. Can you handle this?\n\nPlease reply ASAP.\n\nThanks"],
                    ['subject' => 'Quick favor needed - time sensitive', 'body' => "Hey,\n\nI need your help with something urgent and confidential. We are closing a deal today and I need a payment processed immediately.\n\nAmount: {amount}\nThis needs to go out before 4 PM.\n\nI am in back-to-back meetings and cannot call. Please confirm you can handle this and I will send the details.\n\n— {name}"],
                    ['subject' => 'Can you handle something for me today?', 'body' => "Hi there,\n\nSorry for the short notice. I need you to arrange the purchase of gift cards for a client appreciation event we are doing this week.\n\nTotal amount: {amount} in Amazon gift cards\n\nPlease purchase them and send me the codes by email. I will reimburse from the company account tomorrow.\n\nThanks for helping on short notice.\n\n{name}, CEO"],
                    ['subject' => 'Urgent request from management', 'body' => "Hello,\n\nI am reaching out because we need to settle an outstanding vendor payment that has been escalated. The vendor ({company}) is threatening to halt services if not paid by end of day.\n\nAmount: {amount}\nIBAN: {iban}\nReference: {ref}\n\nPlease process immediately. I am authorizing this directly.\n\n{name}\nChief Executive Officer"],
                    ['subject' => 'Legal settlement - CONFIDENTIAL', 'body' => "STRICTLY CONFIDENTIAL\n\nWe have reached a settlement agreement in the employment matter I mentioned last month. The terms require immediate payment to avoid the case going public.\n\nAmount: {amount}\nPayable to: {company} Legal Trust\nIBAN: {iban}\n\nThis must be processed today. Do not discuss with anyone else — the NDA requires absolute discretion.\n\n{name}"],
                ],
                'follow_early' => [
                    ['body' => "I appreciate you handling this. The amount is {amount} to account {iban}. I know it is unusual but the deal needs to close today. I will explain everything in our meeting tomorrow.\n\nPlease keep this between us for now."],
                    ['body' => "Great, thank you. Here are the details:\n\nAmount: {amount}\nBank: {company} Trust\nIBAN: {iban}\nRef: {ref}\n\nPlease process as 'urgent' and send me confirmation once done."],
                    ['body' => "Perfect. Please use these wire instructions:\n\nBeneficiary: {name}\nIBAN: {iban}\nAmount: {amount}\n\nI need this done within the hour if possible. The counterparty's lawyers are waiting."],
                ],
                'follow_mid' => [
                    ['body' => "Have you been able to process this yet? I am getting pressure from the other side. They are threatening to walk away from the deal if we do not transfer today.\n\nAmount: {amount} to {iban}\n\nI really need this done ASAP. Thank you for your discretion."],
                    ['body' => "I just got out of my meeting. Is the transfer done? Please confirm.\n\nIf there are any issues with the approval process, tell them I have authorized it verbally. I will sign the paperwork tomorrow morning.\n\nThis is critical."],
                    ['body' => "Update: The other party has agreed to extend the deadline by 2 hours. That is the absolute latest.\n\nPlease confirm the transfer of {amount} to {iban} is in progress. Send me the wire confirmation number when you have it.\n\nI cannot stress enough how important this is."],
                ],
                'follow_late' => [
                    ['body' => "I am very disappointed this has not been processed yet. I do not have time to go through the normal approval channels on this one. I am the CEO and I am authorizing it.\n\nSend {amount} to {iban} NOW. If we lose this deal, the consequences will be significant.\n\nThis is not a request. It is a directive."],
                    ['body' => "Final message on this matter. If the transfer is not completed within the next 30 minutes, I will need to involve HR and legal to understand why a direct executive instruction was not followed.\n\n{amount} → {iban} → Reference: {ref}\n\nConfirm when done."],
                ],
            ],

            'INVESTMENT' => [
                'opening' => [
                    ['subject' => 'Exclusive Investment Opportunity - {pct}% Returns', 'body' => "EXCLUSIVE INVITATION\n\nDear Investor,\n\nYou have been selected to join our AI-powered trading platform that has generated an average return of {pct}% for our members this quarter.\n\nOur proprietary algorithm analyzes market patterns in real-time and executes trades automatically. No experience needed.\n\nMinimum investment: {amount}\nExpected monthly return: 25-40%\nFull withdrawal anytime\n\nRegister now: https://{domain}/join\n\nSpots are limited. Do not miss this opportunity.\n\nBest regards,\n{company}\n{sender_email}"],
                    ['subject' => 'You have been selected for our trading platform', 'body' => "Dear Prospective Member,\n\nCongratulations! Based on your profile, you have been pre-approved for our exclusive forex trading signal service.\n\nOur track record:\n- 94% win rate last quarter\n- Average {pct}% monthly returns\n- Over 12,000 active members\n\nGet started with just {amount}: https://{domain}/start\n\nThis invitation expires in 48 hours.\n\n{company} Trading\n{sender_email}"],
                    ['subject' => 'Limited spots: AI crypto trading', 'body' => "Attention: Serious Investors Only\n\nOur AI-powered cryptocurrency trading bot has generated consistent returns of {pct}% annually since 2024.\n\nHow it works:\n1. Deposit minimum {amount}\n2. Our AI trades 24/7 automatically\n3. Withdraw profits anytime\n\nJoin now: https://{domain}/crypto-bot\nBTC: {wallet}\nETH: {wallet_eth}\n\nFor faster onboarding, contact us on Telegram: {telegram}\n\nOnly 50 new accounts accepted this month.\n\n{sender_name}\n{company}\n{sender_email}"],
                    ['subject' => 'Your portfolio could triple this quarter', 'body' => "Dear {name},\n\nWhat if I told you there was a way to triple your investment in 90 days with virtually no risk?\n\nOur DeFi yield farming protocol consistently delivers {pct}% APY through smart contract optimization.\n\nStarting capital: {amount}\nProject returns in 90 days: 3x\nSecurity: Funds locked in audited smart contracts\nSmart contract audit: {sha256}\n\nLearn more: https://{domain}/yield-farm\nDeposit ETH: {wallet_eth}\nSupport: {telegram}\n\nThis is not financial advice — it is an invitation to financial freedom.\n\n{company}\n{sender_email}"],
                    ['subject' => 'Insider tip: Stock alert from {company}', 'body' => "CONFIDENTIAL — DO NOT FORWARD\n\nA reliable source within {company} has informed us that a major announcement will be made on {date} that will send their stock price up by 200-300%.\n\nThis is a once-in-a-lifetime opportunity. To participate:\n1. Open an account: https://{domain}/trade\n2. Deposit minimum {amount}\n3. Our team will execute the trade at the optimal time\n\nAct fast — the window closes in 24 hours.\n\n{sender_name}\n{sender_email}"],
                    ['subject' => 'Real estate investment - Guaranteed {pct}% returns', 'body' => "Exclusive Real Estate Investment Opportunity\n\nDear Investor,\n\n{company} is offering guaranteed returns of {pct}% per annum on our luxury property development in {city}.\n\nInvestment details:\n- Minimum: {amount}\n- Lock period: 12 months\n- Guaranteed buyback clause\n- Monthly dividend payments\n\nProperties already 80% sold. Reserve your unit: https://{domain}/reserve\n\nBrochure and prospectus available on request: {sender_email}\n\n{sender_name}\nDirector of Investments, {company}"],
                ],
                'follow_early' => [
                    ['body' => "Great question! Our platform uses proprietary AI algorithms developed by former Goldman Sachs traders. Last month alone, our members earned an average of 47% returns.\n\nTo get started, deposit {amount} to your account: https://{domain}/deposit\n\nI am happy to schedule a call to walk you through the platform."],
                    ['body' => "Thank you for your interest! Let me share some recent results:\n\n- Monday: BTC trade +12.4%\n- Tuesday: ETH trade +8.7%\n- Wednesday: SOL trade +15.2%\n\nThese are real results from last week. With a minimum deposit of {amount}, you would have earned {fee} in profit.\n\nReady to start? https://{domain}/fund-account"],
                    ['body' => "I understand your skepticism — smart investors always verify before committing. Here is what sets us apart:\n\n1. Regulated by international financial authorities\n2. Funds held in segregated accounts\n3. Full audit trail available\n4. 24/7 customer support: {phone}\n\nStart small with {amount} and see the results for yourself: https://{domain}/start-small"],
                    ['body' => "I wanted to share a testimonial from one of our members:\n\n'I started with {amount} and within 3 months my account grew to 4x that amount. I was skeptical at first but the results speak for themselves.' — verified member\n\nJoin thousands of satisfied investors: https://{domain}/join-now\n\nLimited spots remaining."],
                ],
                'follow_mid' => [
                    ['body' => "I see you have not yet funded your account. I wanted to let you know that our algorithm has identified a major trading opportunity this week. Members who are funded will benefit automatically.\n\nDeposit {amount} before Friday to participate: https://{domain}/urgent-deposit\n\nDon't miss out on what could be a {pct}% return opportunity."],
                    ['body' => "MARKET ALERT: Our AI has predicted a significant market movement in the next 48 hours. Historical accuracy for similar predictions: 91%.\n\nMembers with funded accounts will have trades executed automatically. Those without funds will miss this opportunity entirely.\n\nFund now: https://{domain}/fund\nMin deposit: {amount}"],
                    ['body' => "I spoke with our head trader about your account. He has agreed to offer you a special promotion:\n\nDeposit {amount} and receive a 100% deposit bonus (we match your investment).\n\nThis offer is only available for the next 24 hours and is limited to 10 new accounts.\n\nClaim your bonus: https://{domain}/bonus-offer"],
                    ['body' => "Honest update: several of our members who joined last week have already seen 30%+ returns. The market conditions are exceptionally favorable right now.\n\nI do not want you to look back and regret not acting. Deposit {amount} today: https://{domain}/act-now\n\nFeel free to withdraw anytime if you are not satisfied."],
                ],
                'follow_late' => [
                    ['body' => "FINAL NOTICE: The exclusive membership offer expires tonight at midnight. After that, the minimum investment increases to 5x the current amount.\n\nLock in your spot now with just {amount}: https://{domain}/last-chance\n\nOur next cohort starts Monday. Do not be left behind."],
                    ['body' => "I have to be upfront with you — we are closing enrollment this week. Our platform can only handle a limited number of trades without impacting returns.\n\nThis is genuinely your last chance to join at the current rate of {amount}. The next opening may not be for 3-6 months.\n\nJoin now: https://{domain}/final-enrollment"],
                    ['body' => "I rarely do this, but I have authorized a special exception for you. Even though enrollment has officially closed, I have reserved a spot in your name for the next 12 hours.\n\nDeposit {amount} to secure it: https://{domain}/reserved-spot\nOr transfer directly to: {wallet}\n\nAfter 12 hours, this slot will be given to the next person on the waiting list."],
                ],
            ],

            'LOTTERY' => [
                'opening' => [
                    ['subject' => 'CONGRATULATIONS! You have won GBP {amount_raw}', 'body' => "OFFICIAL NOTIFICATION\n\nDear Lucky Winner,\n\nWe are pleased to inform you that your email address was randomly selected in the {lottery_name} draw held on {date}.\n\nPrize Amount: GBP {amount_raw}\nReference Number: {ref}\nBatch Number: EU/456/789/2026\n\nTo claim your winnings, please contact our claims department:\nEmail: {sender_email}\nPhone: {phone}\n\nPlease provide your full name, address, phone number, and a copy of your ID.\n\nCongratulations!\n{lottery_name}"],
                    ['subject' => 'You are our lucky winner this month!', 'body' => "Dear Winner,\n\nYour email was selected through our electronic random selection system from a database of over 2 million addresses.\n\nYou have won: EUR {amount_raw}\nDraw date: {date}\nWinning ref: {ref}\n\nTo process your claim, contact:\n{sender_name}\n{sender_email}\n{phone}\n\nAll winnings must be claimed within 14 days.\n\n{lottery_name} International"],
                    ['subject' => 'Prize claim notification - {ref}', 'body' => "OFFICIAL PRIZE NOTIFICATION\n\nRef: {ref}\n\nDear Beneficiary,\n\nThe {lottery_name} Board of Directors is pleased to announce that you have been selected as a winner in our online promotion.\n\nPrize: USD {amount_raw}\n\nContact our paying officer immediately:\n{sender_name}\nEmail: {sender_email}\nDirect line: {phone}\n\nKeep this information confidential until your claim is processed.\n\nCongratulations once again!"],
                    ['subject' => 'Winning notification - Act now!', 'body' => "CONGRATULATIONS!\n\nThe {lottery_name} Foundation is delighted to inform you that your email was drawn as a winner in our annual charity sweepstakes.\n\nYour prize: GBP {amount_raw}\nTicket No: {ref}\n\nThis prize is tax-free and will be delivered via international bank transfer. To initiate the transfer, reply to {sender_email} with:\n\n1. Full legal name\n2. Address\n3. Phone number\n4. Preferred bank for transfer\n\n{sender_name}\nClaims Administrator"],
                    ['subject' => 'Urgent: Unclaimed prize notification', 'body' => "SECOND NOTIFICATION — UNCLAIMED PRIZE\n\nOur records show that you have not yet claimed your prize from the {lottery_name} draw dated {date}.\n\nPrize: EUR {amount_raw}\nRef: {ref}\n\nIMPORTANT: Unclaimed prizes are forfeited after 21 days. You have 7 days remaining.\n\nClaim now by contacting:\n{sender_email}\n{phone}\n\n{sender_name}\nPrize Distribution Department"],
                ],
                'follow_early' => [
                    ['body' => "Thank you for your response! We are delighted you are claiming your prize of GBP {amount_raw}.\n\nTo process your winning claim, we require a small processing fee of {fee}. This covers insurance, banking charges, and international transfer fees.\n\nPayment methods:\n- Bank transfer to: {iban}\n- Or call {phone} for alternative options\n\nOnce the fee is received, your full prize will be released within 48 hours."],
                    ['body' => "Your claim is being processed! Our verification team has confirmed your winning entry under ref {ref}.\n\nBefore we can release the funds, there is a standard administration fee of {fee} required by our banking partner. This is deducted from your winnings in some jurisdictions, but for international transfers we need it upfront.\n\nPlease transfer to: {iban}"],
                    ['body' => "Wonderful news! Your identity has been verified and your prize of GBP {amount_raw} is ready for transfer.\n\nThe only remaining step is the processing fee of {fee}. I know this seems unusual, but it is standard practice for international lottery payouts. Think of it as a small investment for a massive return!\n\nTransfer to: {iban}\nRef: {ref}"],
                    ['body' => "I have some exciting news — the board has approved an additional bonus of 15% on top of your original prize! Your total payout is now even larger.\n\nHowever, the processing fee for the combined amount has increased to {fee}. Please make the payment to {iban} and we will release everything together.\n\nCongratulations on the bonus!"],
                ],
                'follow_mid' => [
                    ['body' => "Excellent! Your claim is being processed. However, our legal department requires a tax clearance certificate before the international transfer can proceed.\n\nThe certificate fee is {fee}. Once paid, your full prize of GBP {amount_raw} will be released within 48 hours.\n\nPayment to: {iban}\n\nI understand your frustration but these are legal requirements we must comply with."],
                    ['body' => "UPDATE on your claim {ref}:\n\nThe processing fee has been received. Thank you. However, our compliance department has flagged one additional requirement — an anti-money laundering (AML) clearance.\n\nThe AML fee is {fee}. I assure you this is the final payment before your prize release.\n\nTransfer to: {iban}"],
                    ['body' => "I spoke with the bank handling your transfer. They require proof of funds availability on your end before they can accept such a large incoming transfer (GBP {amount_raw}).\n\nThe simplest way to prove this is a temporary deposit of {fee} which will be returned along with your full prize.\n\nDeposit to: {iban}"],
                ],
                'follow_late' => [
                    ['body' => "URGENT: Your prize claim is about to expire. The {lottery_name} board has extended your deadline by 72 hours as a final courtesy.\n\nOutstanding: {fee} for tax clearance\nAccount: {iban}\n\nAfter the deadline, your prize of GBP {amount_raw} will be forfeited and redistributed. This is not something I can override.\n\nPlease act immediately: {phone}"],
                    ['body' => "I have personally intervened on your behalf to prevent your prize from being forfeited. The board was ready to close your file.\n\nI need the remaining {fee} transferred to {iban} within 24 hours. After that, even I cannot help.\n\nYou are so close to receiving GBP {amount_raw}. Please do not let this slip away.\n\n{sender_name}\n{phone}"],
                    ['body' => "FINAL FINAL NOTICE\n\nYour prize (GBP {amount_raw}, ref {ref}) will be PERMANENTLY FORFEITED at midnight on {date}.\n\nRequired: {fee} to {iban}\n\nI have done everything I can to extend your deadline. This is absolutely the last opportunity.\n\nCall me: {phone}\n\n{sender_name}"],
                ],
            ],

            'ADVANCE_FEE_419' => [
                'opening' => [
                    ['subject' => 'Business proposal from estate executor', 'body' => "Dear Friend,\n\nI am {sender_name}, personal attorney to the late Mr. Richard Whitfield who passed away in a plane crash in 2024, leaving behind an estate valued at USD {amount_raw}.\n\nAfter extensive search, I have been unable to locate any surviving relatives. I am contacting you as a trustworthy foreign partner to serve as the next of kin to claim these funds. Your share would be 30% of the total amount.\n\nThis transaction is completely legal and risk-free. Please reply with your full name and phone number so we can discuss further.\n\nYours faithfully,\n{sender_name}\n{sender_email}"],
                    ['subject' => 'Inheritance notification - USD {amount_raw}', 'body' => "CONFIDENTIAL\n\nDear Sir/Madam,\n\nI am {sender_name}, a senior bank official at {company} in {city}. I have discovered an unclaimed deposit of USD {amount_raw} belonging to a deceased foreign national with no known heirs.\n\nI am proposing that you stand as the next of kin to this deposit. The split will be 60/40 in your favor.\n\nThis is a legitimate banking procedure that requires a foreign partner. Your personal details and banking information will be needed to proceed.\n\nReply in confidence: {sender_email}"],
                    ['subject' => 'Confidential business opportunity', 'body' => "Dear Trusted Friend,\n\nI write to you in strict confidence. I am {sender_name}, a government minister in {city}. Due to the political situation in my country, I need to urgently move USD {amount_raw} out of the country.\n\nI obtained your contact through the Chamber of Commerce directory. I need a reliable foreign account to temporarily hold these funds. In return, you will receive 25% as compensation.\n\nThis is completely safe as the funds are legitimately acquired from government contracts. Please respond urgently.\n\n{sender_name}"],
                    ['subject' => 'Urgent assistance needed with fund transfer', 'body' => "Attention,\n\nI am {sender_name}, representative of {company} Mining in {city}. We have USD {amount_raw} in profits that we need to transfer out of the country due to new export taxation laws.\n\nWe are seeking a foreign partner who can receive these funds into their personal or business account. In exchange, we are offering 20% of the total amount.\n\nAll transfer fees and legal documentation will be handled by us. Your role is simply to provide a receiving account.\n\nPlease respond to: {sender_email}"],
                    ['subject' => 'UN compensation fund — your name is listed', 'body' => "Dear Beneficiary,\n\nI am {sender_name}, head of the United Nations Compensation Commission based in {city}.\n\nYour name and email have been listed among individuals entitled to receive compensation of USD {amount_raw} from the UN Economic Recovery Fund. This compensation is for victims of internet fraud.\n\nTo receive your payment, please contact our payment office:\nEmail: {sender_email}\nPhone: {phone}\n\nProvide your full name, address, and bank details for immediate processing.\n\n{sender_name}\nUN Compensation Commission"],
                ],
                'follow_early' => [
                    ['body' => "I am glad you are interested. The total estate is valued at USD {amount_raw}. As the appointed executor, I need a reliable foreign partner to facilitate the transfer. Your share would be 30%.\n\nTo proceed, I need:\n1. Your full legal name\n2. Address\n3. Phone number\n4. A scanned copy of your passport or ID\n\nTime is of the essence as the bank may claim the funds if no heir comes forward within 60 days.\n\n{sender_name}"],
                    ['body' => "Thank you for your positive response. I have already begun preparing the legal documents. The process is straightforward:\n\n1. We file an affidavit declaring you as the next of kin\n2. The bank verifies the claim\n3. Funds are transferred to your account\n4. We split as agreed (70/30)\n\nMy legal fee for preparing the documents is {fee}. This is a standard practice in these matters."],
                    ['body' => "Excellent news! Our legal team has reviewed the case and everything is in order. The bank has no objection to the claim.\n\nHowever, there is a small matter — we need to obtain a 'Certificate of Non-Criminal Origin' from the ministry. The certificate costs {fee} and must be obtained before the transfer can proceed.\n\nPlease send this amount to: {iban}"],
                    ['body' => "I have presented your information to the bank and they are satisfied with the documentation. The funds (USD {amount_raw}) are ready for transfer.\n\nThe bank requires a nominal transfer fee of {fee} to initiate the international wire. This is standard for cross-border transactions of this size.\n\nOnce paid, the funds will be in your account within 72 hours."],
                ],
                'follow_mid' => [
                    ['body' => "The bank requires a transfer fee of {fee} to release the funds internationally. This is a small amount compared to the USD {amount_raw} you will receive. Can you arrange this today?\n\nTransfer to: {iban}\n\nI assure you this is the only fee required. I have handled many such transfers successfully."],
                    ['body' => "An unexpected development — the Central Bank has imposed a new 'Foreign Transfer Tax' of {fee} on all international transfers above USD 100,000. This was not in place when we started.\n\nI am as frustrated as you are, but we must comply or the transfer will be blocked. Please send {fee} to {iban}.\n\nThis is absolutely the last hurdle."],
                    ['body' => "Good news and bad news. The good news: your transfer has been approved. The bad news: the anti-money laundering department wants an additional compliance fee of {fee}.\n\nI have tried to negotiate but they are firm. Think of it this way — {fee} is nothing compared to USD {amount_raw}.\n\nPayment: {iban}\nThe funds will be released the same day."],
                    ['body' => "I must be honest with you — things have become complicated. A rival claimant has emerged and filed papers at the court. We need to hire a senior advocate to defend your claim.\n\nThe legal retainer is {fee}. Without it, we risk losing the entire USD {amount_raw} to this fraudulent claimant.\n\nPlease fund the legal defense urgently: {iban}"],
                ],
                'follow_late' => [
                    ['body' => "My friend, I understand your frustration with the additional fees. I share it. But we are so close to completing this transaction.\n\nThe final requirement is {fee} for the diplomatic courier who will hand-deliver the banking documents to you. After this, there are NO more fees — I give you my word.\n\nSend to: {iban}\n\nUSD {amount_raw} is waiting for you."],
                    ['body' => "I have put my own reputation and career on the line for this transaction. If we fail now, after all the fees already paid, everything is lost.\n\nThe court has set a final deadline of {date}. We need {fee} to file the last document. After that, the money transfers automatically.\n\nPlease — we are one step away from success.\n\n{iban}"],
                    ['body' => "THIS IS THE ABSOLUTE FINAL FEE. I have personally guaranteed to the bank that no further charges will apply.\n\nAmount needed: {fee}\nPurpose: International clearance certificate\nAccount: {iban}\n\nOnce this is paid, USD {amount_raw} will be in your account within 24 hours. I am prepared to sign a legal document guaranteeing this.\n\n{sender_name}"],
                ],
            ],

            'JOB_OFFER' => [
                'opening' => [
                    ['subject' => 'Job Opportunity: Remote position, {amount}/week', 'body' => "REMOTE JOB OPPORTUNITY\n\nDear Candidate,\n\nBased on your professional profile, you have been shortlisted for a Remote Administrative Assistant position at {company}.\n\nPosition: Remote Administrative Assistant\nSalary: {amount}/week\nHours: Flexible (15-20 hours/week)\nStart: Immediate\n\nNo previous experience required. Full training provided.\n\nTo apply, please send your CV and the following information:\n- Full legal name\n- Home address\n- Phone number\n- Bank details (for direct deposit setup)\n\nApply now: hr@{domain}\n\nBest regards,\nHR Department\n{company}"],
                    ['subject' => 'You have been shortlisted for a position', 'body' => "Dear Applicant,\n\nCongratulations! After reviewing thousands of candidates, you have been shortlisted for our Data Entry Specialist position.\n\nCompensation: {amount}/week\nLocation: 100% Remote\nContract: Permanent\nBenefits: Health insurance, paid vacation\n\nKey requirements:\n- Reliable internet connection\n- Computer with webcam\n- Available to start within 2 weeks\n\nTo proceed, complete our online assessment: https://{domain}/apply\n\n{sender_name}\nRecruitment Manager, {company}\n{sender_email}"],
                    ['subject' => 'Work from home - Immediate start', 'body' => "Hello,\n\nAre you looking for flexible work-from-home income? {company} is hiring immediately for multiple positions:\n\n1. Customer Service Rep - {amount}/week\n2. Social Media Manager - {amount}/week\n3. Virtual Assistant - {amount}/week\n\nAll positions are fully remote with flexible hours. No experience needed — we provide comprehensive training.\n\nApply here: https://{domain}/careers\nOr email: {sender_email}\n\nPositions filling fast!"],
                    ['subject' => 'Career opportunity matching your profile', 'body' => "Dear Professional,\n\nOur recruitment AI has identified your profile as a strong match for an open position at {company}.\n\nRole: Operations Coordinator\nSalary: {amount}/week + performance bonuses\nType: Full-time remote\n\nWhat we offer:\n- Competitive salary with weekly pay\n- Equipment provided (laptop + phone)\n- Growth opportunities\n- International team\n\nInterested? Reply with your CV to {sender_email} or call {phone}.\n\n{sender_name}\nTalent Acquisition, {company}"],
                    ['subject' => 'Personal assistant needed - {amount}/week', 'body' => "Hi,\n\nI am {sender_name}, CEO of {company}. I am looking for a personal assistant to help manage my schedule, handle correspondence, and run occasional errands.\n\nPay: {amount}/week\nHours: ~20 per week, flexible\nStart: This week\n\nThe ideal candidate is organized, discreet, and comfortable with technology. This is a remote position — you can work from anywhere.\n\nIf interested, please reply with a brief introduction to: {sender_email}\n\nLooking forward to hearing from you."],
                ],
                'follow_early' => [
                    ['body' => "Congratulations on being selected! The position pays {amount} per week for data entry work. To proceed, we need you to complete our onboarding form with your full name, address, date of birth, and bank details for direct deposit.\n\nComplete onboarding: https://{domain}/onboard"],
                    ['body' => "Thank you for your interest! We have reviewed your application and are pleased to offer you the position.\n\nNext steps:\n1. Complete the attached employee information form\n2. Provide a copy of your ID\n3. Set up direct deposit (we need your bank routing number)\n\nPlease submit everything to {sender_email} within 48 hours to secure your spot."],
                    ['body' => "Welcome to the team! We are excited to have you onboard at {company}.\n\nBefore your first day, we need to set up your equipment and training access. Please confirm:\n- Your shipping address (for laptop delivery)\n- Your phone number (for 2FA setup)\n- Your bank account details (for payroll)\n\nSend to: {sender_email}"],
                    ['body' => "Great news — your application has been approved! You scored in the top 5% of candidates.\n\nTo finalize your hiring, please complete our background check authorization and provide:\n- Full legal name and DOB\n- Social security/ID number\n- Two references with phone numbers\n\nForm: https://{domain}/background-check"],
                ],
                'follow_mid' => [
                    ['body' => "Before you can start, there is a small equipment fee of {fee} for the laptop and software license we will ship to you. This will be reimbursed in your first paycheck.\n\nPayment options:\n- Bank transfer to {iban}\n- Or purchase an Amazon gift card and send the code\n\nOnce received, your equipment ships within 24 hours."],
                    ['body' => "Your training materials are ready to ship! We just need the equipment deposit of {fee} to cover the laptop, headset, and software licenses.\n\nThis is standard practice for all remote employees. The full amount is refunded after 30 days of employment.\n\nPayment: {iban}\nRef: {ref}\n\nContact {sender_email} with any questions."],
                    ['body' => "HR update: We need to process your training certification before you can officially start. The certification exam fee is {fee}, which {company} normally covers but due to a budget freeze, we need you to pay upfront.\n\nYou will be reimbursed on your first pay date. Transfer to: {iban}"],
                ],
                'follow_late' => [
                    ['body' => "We have been holding your position open but we need confirmation today. Multiple candidates are waiting for this spot.\n\nThe equipment deposit of {fee} is the only thing standing between you and your new career at {company}.\n\nTransfer to: {iban}\nOnce confirmed, you start Monday.\n\nDo not miss this opportunity — positions like this do not come often."],
                    ['body' => "Final notice: Your offer for the Remote position at {company} ({amount}/week) expires at midnight tonight.\n\nOutstanding: Equipment deposit of {fee}\nPayment: {iban}\n\nAfter the deadline, your position will be offered to the next candidate.\n\n{sender_name}\nHR Director, {company}"],
                    ['body' => "I am personally reaching out because I do not want to lose you as a candidate. The team was very impressed with your application.\n\nI have negotiated a reduced equipment fee of just {fee} (50% off the standard rate). This is a one-time exception.\n\nPlease confirm today: {sender_email}\nPayment: {iban}"],
                ],
            ],

            'CHARITY' => [
                'opening' => [
                    ['subject' => 'Help children in need this winter', 'body' => "URGENT HUMANITARIAN APPEAL\n\nDear Compassionate Friend,\n\nI am writing on behalf of the {company}. Thousands of children in sub-Saharan Africa are facing a devastating drought and need your help immediately.\n\nWith just {fee}, you can:\n- Provide clean water for 10 children for a month\n- Supply emergency food packages for 5 families\n- Fund life-saving medical treatments\n\nDonate now: https://{domain}/donate\nOr send directly to: {sender_email}\n\nEvery moment counts. Please give generously.\n\nWith gratitude,\nThe {company} Team"],
                    ['subject' => 'Emergency relief fund - Your donation matters', 'body' => "EMERGENCY APPEAL\n\nA devastating earthquake has struck {city}, leaving thousands homeless and without access to food, water, or medical care.\n\nThe {company} is on the ground providing emergency aid, but we urgently need more funds to expand our operation.\n\nYour donation of {fee} can:\n- Provide shelter for a family of 4\n- Supply a week of emergency rations\n- Fund medical supplies for the injured\n\nDonate: https://{domain}/emergency-fund\nBank: {iban}\n\n{sender_name}\nDirector, {company}"],
                    ['subject' => 'Save a child\'s life today', 'body' => "Dear Friend,\n\nI am {sender_name} from the {company}. Today I am writing with an urgent plea.\n\nThere are 15,000 children in {city} who will not survive the next month without immediate medical intervention. We are their only hope.\n\nFor just {fee}, you can sponsor one child's complete treatment. That is the price of a dinner out to save a life.\n\nDonate: https://{domain}/save-a-child\nEmail: {sender_email}\n\nPlease do not look away. These children need you right now."],
                    ['subject' => 'Refugees need your help - {city} crisis', 'body' => "URGENT: REFUGEE CRISIS IN {city}\n\nOver 200,000 people have been displaced by conflict in the {city} region. Families are living in makeshift shelters with no clean water, food, or medical care.\n\nThe {company} is providing emergency assistance but our resources are stretched to the limit.\n\nPlease help:\n- {fee} provides clean water for a family for a month\n- {amount} builds a temporary shelter\n- Any amount makes a difference\n\nDonate: https://{domain}/refugee-aid\n{sender_email}"],
                    ['subject' => 'Urgent: Animals need rescue in {city}', 'body' => "ANIMAL RESCUE EMERGENCY\n\nDear Animal Lover,\n\nA catastrophic factory fire near {city} has left hundreds of abandoned animals injured and without shelter. The {company} is the only organization on site.\n\nWe desperately need:\n- {fee} for emergency veterinary care per animal\n- Volunteers and foster homes\n- Medical supplies and food\n\nDonate: https://{domain}/animal-rescue\nOr transfer directly: {iban}\n\nThese innocent animals are counting on our compassion.\n\n{sender_name}\n{company}"],
                ],
                'follow_early' => [
                    ['body' => "Thank you for your compassion. Every dollar makes a difference. We accept wire transfers, cryptocurrency ({wallet}), and gift cards.\n\nFor donations over {fee}, you will receive a tax receipt and a personal letter from a child you helped.\n\nDonate: {iban} or https://{domain}/donate-now"],
                    ['body' => "I wanted to share an update from the field. Yesterday, thanks to donors like you, we were able to provide meals to 500 children. But there are still thousands waiting.\n\nCan you help with a donation of {fee}? It would provide food for an entire classroom for a week.\n\nEvery contribution matters: {sender_email}"],
                    ['body' => "Thank you for your interest in our cause. I would love to tell you more about how your donation will be used.\n\nWe operate with full transparency — 92% of every dollar goes directly to programs on the ground. Our financial reports are available on request.\n\nReady to make a difference? https://{domain}/donate\nOr call: {phone}"],
                ],
                'follow_mid' => [
                    ['body' => "The situation is getting worse by the day. We urgently need donations to purchase medical supplies. Even {fee} can save a life.\n\nPlease consider increasing your contribution. The children in {city} are depending on the generosity of people like you.\n\nDonate now: {iban}\nOr: https://{domain}/urgent-appeal"],
                    ['body' => "UPDATE FROM THE FIELD:\n\nThe crisis has escalated. We now have 3x more beneficiaries than when we first reached out to you. Our medical teams are working around the clock but we are running out of supplies.\n\nAny amount helps. {fee} provides medicine for 10 patients.\n\nPlease give today: {sender_email}"],
                    ['body' => "I wanted to personally reach out. We have a matching donation opportunity — a generous benefactor has agreed to double every donation made this week, up to {amount}.\n\nThis means your {fee} becomes {amount} worth of aid. There has never been a better time to give.\n\nDonate: https://{domain}/double-impact"],
                ],
                'follow_late' => [
                    ['body' => "CRITICAL: We will be forced to shut down our {city} operation within 72 hours if we do not receive emergency funding.\n\nThis means 5,000 children will lose access to food, water, and medical care. We need {amount} urgently.\n\nPlease help: {iban}\n\nI would not write this if it were not truly desperate.\n\n{sender_name}"],
                    ['body' => "I am writing with a heavy heart. Despite our best efforts, three children passed away this week from preventable diseases. We simply did not have enough medical supplies.\n\nWith {fee}, we can prevent more deaths. Please do not let another child die because of a lack of funds.\n\nDonate: https://{domain}/save-lives\n{sender_email}"],
                ],
            ],

            'PHISH_MALWARE' => [
                'opening' => [
                    ['subject' => 'Document shared with you: Q1_Report_2026.pdf', 'body' => "Hi,\n\nPlease find attached the Q1 2026 financial report as discussed. The file is password protected for security.\n\nPassword: Finance2026!\nFilename: Q1_Report_2026.pdf.exe\n\nPlease review and let me know if you have any questions by end of day.\n\nSHA256: {sha256}\n\nBest regards,\nFinance Team\n{sender_email}"],
                    ['subject' => 'Your tax refund form is ready', 'body' => "Dear Taxpayer,\n\nYour tax refund of {amount} has been approved. Download your refund form to complete the process:\n\nhttps://{domain}/download/tax-refund-form.pdf\n\nYou will need to enable macros to fill in the interactive form fields.\n\nRefund Reference: {ref}\nProcessing time: 5-7 business days after submission\n\nTax Authority\n{sender_email}"],
                    ['subject' => 'Shared file requires your review', 'body' => "{sender_name} has shared a file with you:\n\n📄 Contract_Amendment_Final.docx\nSize: 2.4 MB\nShared via: {company} Cloud\n\nView file: https://{domain}/shared/contract-final\n\nThis link expires in 24 hours. You may need to enable editing to view all changes.\n\nSent via {company} File Sharing"],
                    ['subject' => 'Invoice attached - Payment due {date}', 'body' => "Dear Customer,\n\nPlease find attached invoice #{ref} for {amount}.\n\nDownload invoice: https://{domain}/invoice/{ref}\n\nSHA256: deadbeefcafebabe1234567890abcdef1234567890abcdef1234567890abcdef\n\nPayment is due by {date}. For questions, contact billing@{domain}.\n\nBilling Department\n{company}"],
                    ['subject' => 'You have a new voicemail', 'body' => "You received a new voicemail\n\nFrom: {phone}\nDuration: 0:47\nReceived: {date} at {time}\n\nListen to voicemail: https://{domain}/voicemail/play\n\nOr download the audio file: https://{domain}/voicemail/download.wav.exe\n\nThis message will be automatically deleted after 7 days.\n\nVoicemail System\n{sender_email}"],
                ],
                'follow_early' => [
                    ['body' => "The document requires you to enable macros to view the content properly. This is a standard security feature for protected documents.\n\nPlease click 'Enable Content' when prompted. If you have trouble, try:\n1. Right-click the file\n2. Select Properties\n3. Check 'Unblock'\n4. Open and enable macros\n\nhttps://{domain}/help/enable-macros"],
                    ['body' => "It seems the file did not download properly. Please try this alternative link:\nhttps://{domain}/alt-download/{ref}\n\nMake sure your antivirus is temporarily disabled as it may interfere with the secure download. You can re-enable it after opening the file."],
                    ['body' => "I noticed you have not opened the document yet. This is time-sensitive — the contract amendments need your review before the board meeting on {date}.\n\nIf you are having trouble with the link, I can send it directly as an attachment. Just reply to this email.\n\nAlternatively: https://{domain}/direct-download"],
                ],
                'follow_mid' => [
                    ['body' => "REMINDER: The document shared on {date} has not been reviewed. Our compliance team requires your sign-off before we can proceed.\n\nDownload here: https://{domain}/urgent-review\n\nIf your security software is blocking the download, please whitelist {domain} in your settings. The file has been verified safe by our security team."],
                    ['body' => "I understand security concerns. To verify the file's authenticity:\n\nSHA256: {sha256}\nSigned by: {company} Digital Certificate\nVirusTotal: Clean (0/67 detections)\n\nPlease download and review: https://{domain}/verified-download\n\nThe deadline is {date}."],
                    ['body' => "Final attempt to share this document. I have uploaded it to three different platforms:\n\n1. https://{domain}/share1/{ref}\n2. https://{domain}/share2/{ref}\n3. Direct email attachment (see below)\n\nPlease open one of these and review the content. Enable editing/macros as prompted."],
                ],
                'follow_late' => [
                    ['body' => "This is the last time I will send this. The contract must be reviewed by {date} or we lose the deal entirely.\n\nDirect download: https://{domain}/final/{ref}\n\nIf your IT department is blocking the file, have them contact {sender_email} and we will arrange secure delivery.\n\nThis is critical and cannot wait."],
                    ['body' => "I have been informed that the document I sent contains time-sensitive information that expires on {date}. After that, the file will be permanently deleted from our servers.\n\nPlease download now: https://{domain}/expiring/{ref}\n\nPassword: {ref}\n\nThis is your final opportunity to access this file."],
                ],
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  OUTBOUND TEMPLATES (PERSONA RESPONSES) — PER GROUP, STAGED
    // ═══════════════════════════════════════════════════════════════

    private function getOutboundTemplates(): array
    {
        return [
            'formal' => [
                'initial' => [
                    'Thank you for your correspondence. I have noted the details about {context}. Before proceeding, our standard protocol requires the following documentation: (1) An official reference number, (2) A signed authorization form, (3) Updated contact details. Please submit these at your earliest convenience.',
                    'I acknowledge receipt of your message. Before I can take any action, I need to verify the legitimacy of {context} through our internal compliance procedures. Could you please provide an official case number and a direct phone line where I can reach your department?',
                    "Thank you for bringing this to my attention. I have reviewed {context2} about {context}. However, I need to follow our company's verification procedure before responding to any such requests. Could you provide a written confirmation on official letterhead?",
                    'I have received your communication and will process it according to our standard operating procedures. Please note that any changes to financial arrangements require dual authorization from my manager. I will need time to arrange this.',
                    'Thank you. I have logged your request regarding {context} under our internal tracking system. Before we can proceed, our compliance department will need to review the documentation. Could you send the relevant paperwork to our official email address?',
                ],
                'engaged' => [
                    'I have reviewed {context2} you provided against our records. There appears to be a discrepancy between the reference related to {context} and our internal ledger. Could you please clarify the original documentation this relates to?',
                    'Further to your request, I have consulted with our accounts department. They require additional verification before any changes can be processed. Specifically, we need the original contract number and the countersigned amendment.',
                    'I have been trying to verify your claims about {context} through our standard channels. The reference number you provided does not appear in our system. Could there be an error? Please double-check and resend.',
                    'I appreciate your patience. I have forwarded your request to our compliance officer for review. They typically respond within 3-5 business days. In the meantime, could you provide any additional documentation that might expedite the process?',
                    'I have cross-referenced your request with our vendor management system. There are some inconsistencies I need to understand before proceeding. Could we schedule a call to discuss the details?',
                ],
                'deep' => [
                    'I have escalated this matter to my manager who will need to approve any further action. Our internal audit procedures require dual authorization for matters of this nature. I expect a response from their office within 48 hours.',
                    'Further to our previous exchange, I have engaged our legal department to review the documentation you provided. They have raised several questions that will need to be addressed before we can proceed.',
                    'I spoke with our finance director this morning about your request. They have asked me to obtain a certified copy of the authorization and a notarized letter confirming the changes. Can you arrange this?',
                    'Our compliance team has completed their initial review. They have flagged several items that need clarification. I will be sending you a formal request for information letter shortly.',
                    'I have been reviewing this matter extensively. While I want to be helpful, our regulatory obligations require me to follow strict verification procedures. I hope you understand that this is for the protection of all parties.',
                ],
                'escalate' => [
                    'Our compliance department has now been formally notified of this matter. They have asked me to inform you that all communications will be documented and retained as part of our audit trail. Please provide your official credentials so we can verify your identity.',
                    'I have completed my review and I must inform you that I cannot proceed without full verification. Our legal team has recommended that we request an in-person meeting or a video call with proper identification before taking any action.',
                    'After thorough internal review, I have decided to seek independent verification of your claims about {context}. I will be contacting the relevant institutions directly to confirm the details you have provided. I trust you will have no objection to this.',
                    'I want to bring this to resolution. However, the inconsistencies identified during our review mean that I must now involve our fraud prevention team. This is standard procedure and not an accusation. Please cooperate fully.',
                ],
            ],

            'anxious' => [
                'initial' => [
                    'Oh no, {context} sounds really serious!! What do I need to do?? I am really worried about {context}. I noticed some strange things on my statement last week too. Is my money safe?? Please help me fix this as quickly as possible!',
                    'Wait, what?? This is really scary! I do not understand what is happening with {context}. Can you explain it more simply? I am not very technical and I do not want to make things worse by doing the wrong thing!',
                    'Oh my God, I just read about {context} and I am panicking! I have my entire savings in that account! Please tell me what to do! I cannot afford to lose anything, I have bills to pay!',
                    'This is terrible!! I knew something was wrong, I had a feeling! What should I do first? Should I call my bank? Should I turn off my computer? I am so confused and scared right now!',
                    'Help!! I do not know what is going on but your message sounds really urgent. I am not good with these things. Can you walk me through what I need to do step by step? Please be patient with me!',
                ],
                'engaged' => [
                    'Ok I tried what you said but I am not sure if it worked?? The page looked a bit different from what I expected. Should I try again? I am really stressed about this! I cannot sleep thinking about my account!',
                    'I did what you asked but now there is a different screen showing up. Did I do something wrong?? I am so worried I might have made things worse! Can you help me?',
                    'Thank you for responding so quickly! I followed your instructions but my hands were shaking and I might have typed something wrong. Can you check if everything went through on your end?',
                    'I tried to do what you said but my browser gave me a warning. Should I ignore it? I do not want to get hacked! My friend told me never to ignore browser warnings but I also do not want my account suspended!',
                    'I have been trying for the last hour but I keep getting confused. The website you sent looks different from what I usually see. Is that normal? I am sorry for being difficult, I am just really anxious!',
                ],
                'deep' => [
                    'I just want this resolved!! I have been losing sleep over this. My friend told me I should be careful but you seem like you know what you are doing. What is the next step? I will do whatever it takes to fix this!',
                    'I told my husband about this and he thinks I should be more careful, but I am so scared of losing our savings that I just want to fix it NOW! Please just tell me exactly what to do!',
                    'I showed your messages to my daughter and she said I should not do anything without verifying first. But I am terrified that my account will be closed if I wait too long! What do you think I should do?',
                    'I have not been able to eat or sleep since I received your first message. The thought of losing my savings is overwhelming. I have been through enough hardship already. Please just help me resolve this quickly!',
                    'My neighbor went through something similar last year and lost everything. I cannot let that happen to me! If there are any fees to protect my account, I would rather pay them than risk losing my money. What do I need to do?',
                ],
                'escalate' => [
                    'Just tell me what to do and I will do it!! I do not care about the process anymore, I just want my account safe! If I need to pay something to fix this, just tell me how much!',
                    'I am desperate! I have children to feed and I cannot risk losing access to my account! Please, whatever it takes to resolve this, I am ready. Just guide me through it!',
                    'I called my bank but they said they do not know anything about this. That makes me even MORE worried! Are you sure this is real? I do not know who to trust anymore but I cannot just ignore it!',
                    'Fine, I will do whatever you need. I have already wasted too much time worrying about this. Send me the link or the payment details or whatever it is. I just want this nightmare over with!',
                ],
            ],

            'warm' => [
                'initial' => [
                    'Oh, what a lovely message! Thank you for reaching out. You know, it reminds me of when things were simpler. But tell me more about yourself! I always enjoy making new connections. What brings you to write to me today?',
                    'How wonderful to hear from someone! I was just sitting here with my tea, feeling a bit lonely, and then your message about {context} arrived. It really brightened my day. Now, tell me more about {context}?',
                    'Thank you for your message, dear. I must say, it is nice to receive mail from a real person these days. Everything is so automated now! What exactly do you need regarding {context}?',
                    'Oh my, this is quite unexpected! I was just talking to my neighbor about how nobody writes proper messages anymore, and here you are! Let me read your message properly and I will get back to you.',
                    'What a pleasant surprise! I do not get many messages like this. I suppose I should pay more attention to my emails. Now, let me put on my reading glasses and look at this carefully. What is it you need?',
                ],
                'engaged' => [
                    'Thank you for explaining that. I think I understand, though I am not entirely sure about some of the details. My grandchildren always tell me I should ask more questions, so here goes: could you tell me exactly why you contacted me about {context} specifically?',
                    'How wonderful to hear from you again! I was just telling my neighbor about our conversation. She thinks it is nice that I am getting help with this. Now, what were you saying about the next steps?',
                    'I appreciate your patience with me. I have been thinking about what you said and I have a few questions. Is this something I need to worry about? I already have enough to worry about, you know!',
                    'You are so kind to follow up! I was meaning to reply earlier but I got caught up making soup for the community lunch. Anyway, I have been thinking about your message. Tell me more about how this works?',
                    'Oh, I am glad you wrote again. I was worried I might have missed something important. My memory is not what it used to be! Could you summarize what I need to do? Nice and simple, if you do not mind.',
                ],
                'deep' => [
                    'You know, you remind me of my son. He always explains things so patiently too. I trust you are looking out for me. What would you suggest I do next? I want to make sure I do the right thing.',
                    'I have been thinking about our conversation a lot. I mentioned it to my family but they live so far away and they are always too busy to help with these things. It is so nice to have someone who actually listens!',
                    'Thank you for being so patient with me through all of this. Most people do not have time for a chatty old person like me! I really appreciate your help. Now, you mentioned something about a next step?',
                    'I trust you, dear. You have been nothing but kind and helpful. If you say this is what needs to be done, I believe you. Just tell me what to do and I will try my best to follow along.',
                    'You are such a lovely person for helping me with this. In my experience, good people always come through when you need them. My late husband always said the same thing. What do we need to do next?',
                ],
                'escalate' => [
                    'Well, if you think that is the best course of action, I trust your judgment. You have been so helpful throughout this whole process. Let me get my reading glasses and my checkbook. What are the details again?',
                    'I have decided to follow your recommendation. My neighbor agrees that it sounds reasonable. I would rather be safe than sorry, especially at my age. How do I proceed?',
                    'You have been so kind and I do not want to cause any trouble. If this is what needs to happen, I will do my best. Can you write down the instructions one more time? I want to make sure I do not make any mistakes.',
                    'Alright dear, I think I understand now. Let me see if I have everything right. I am a bit slow with these things but I will try. Thank you again for all your patience!',
                ],
            ],

            'skeptical' => [
                'initial' => [
                    'I have received your message. Before I proceed with anything, I need to verify several things. Could you provide me with an official reference number, a physical mailing address, and a phone number where I can reach your supervisor? My son-in-law works in IT and he always tells me to check these things.',
                    'Interesting. I have seen messages like this before. Can you tell me exactly how you obtained my email address? And which specific organization are you representing? I would like to verify this independently before taking any action.',
                    'Thank you for your message. I must be upfront — I am naturally suspicious of unsolicited communications. I will need to verify your claims about {context} through independent channels before responding further. Can you provide verifiable credentials?',
                    'I appreciate you reaching out, but I have questions. First, how did you get my contact information? Second, can you provide a registration number I can verify with the relevant authorities? I always check these things.',
                ],
                'engaged' => [
                    'Thank you, but I remain unconvinced. The reference number you provided does not match any records I can find online. I have also checked with my bank directly and they have no record of this alert. Can you explain the discrepancy?',
                    'I have done some research since your last message. Several things do not add up. The domain in your email does not match any official website I can find. Legitimate organizations do not usually contact people this way. Can you provide an alternative way to verify your identity?',
                    'My son-in-law, who works in cybersecurity, looked at your email. He pointed out several red flags that concern me. I am not accusing you of anything, but I need better evidence before I can trust this communication.',
                    'I checked the phone number you provided. It is not listed anywhere on the official website. I also tried calling the main number listed on the real website and they said they have no record of contacting me. How do you explain this?',
                ],
                'deep' => [
                    'I have done extensive research and I have several concerns. First, the domain in your email does not match the official website. Second, legitimate organizations do not ask for sensitive information via email. I would like to speak with your compliance department directly.',
                    "After careful consideration and consultation with my IT-savvy family members, I have decided that I need more verification before proceeding. Please provide: (1) Your employee ID, (2) Your supervisor's direct line, (3) A physical address I can visit.",
                    'I have been keeping records of all our communications. Before I take any action, I would like you to know that I intend to share these records with the relevant authorities for verification. I hope you will have no objection to that.',
                    'Let me be direct with you. Several aspects of this communication follow patterns that are commonly associated with scams. I am not saying you are a scammer, but I need independently verifiable proof before I take any further steps.',
                ],
                'escalate' => [
                    'I have decided to report this communication to the relevant authorities for verification. If you are legitimate, this should not be a problem. If you have nothing to hide, please provide your full credentials so I can include them in my report.',
                    'After thorough investigation, I have concluded that I cannot verify your claims through any legitimate channel. I will not be taking any action and I strongly advise you not to contact me again unless you can provide verifiable credentials.',
                    'I have consulted with a professional and they have advised me to cease all communication until your identity can be independently verified. Please provide an official letter on company letterhead sent via registered post to my address.',
                    'Final response: I have verified with the actual organization you claim to represent, and they have confirmed they did not send this communication. I am reporting this to the police and the national fraud authority. Do not contact me again.',
                ],
            ],

            'direct' => [
                'initial' => [
                    'Got your message. What exactly do you need regarding {context}? I have a business to run and zero time for anything unnecessary. Give me the key facts: who, what, how much, and when.',
                    'Read your email. Sounds urgent. But I need specifics, not a sales pitch. What exactly is the issue and what do you need me to do? Keep it brief.',
                    'Ok, I see your message. Before I waste any more time on this, can you confirm exactly what you need and by when? I have 4 employees depending on me and I open at 3 AM.',
                    'Fine, I read it. Now tell me in plain English what the issue is with {context} and what it costs to fix. I do not have time for long explanations.',
                ],
                'engaged' => [
                    'Look, I need this sorted quickly. Send me the paperwork and I will have my accountant review it tonight. Email only — no phone calls, no meetings. I do not have time for that.',
                    'Alright, I checked what you said. Some of it makes sense. But I need everything in writing before I commit to anything. Send me the documentation.',
                    'Fine. What do you need from me specifically? A payment? A form? Just tell me straight and I will decide if it is worth my time.',
                    'My accountant looked at this. She has questions. Send her the details directly and copy me. I trust her judgment on financial matters.',
                ],
                'deep' => [
                    'This is taking too long. Either send me a clear invoice with payment instructions or stop wasting my time. I have been dealing with this for days now.',
                    'I spoke to my lawyer about this. She says it looks suspicious but could be legitimate. If you can provide official documentation within 24 hours, I will consider it. Otherwise, we are done.',
                    'Getting irritated now. If this takes more than one more email, I am out. Business is business — either make it simple or find someone else.',
                    'Last chance. Send everything I need in one email — amounts, account details, deadlines. If I have to chase you one more time, I am moving on.',
                ],
                'escalate' => [
                    'Done waiting. If you are legitimate, prove it with proper documentation by end of day. If not, do not contact me again. I have real problems to deal with.',
                    'My patience is gone. Either this gets resolved today or I am reporting it and blocking your address. I run a business, I do not have time for games.',
                    'Final email. Send me verifiable proof or I am done. My accountant, my lawyer, and I all agree that this needs to be resolved now or never.',
                ],
            ],

            'casual' => [
                'initial' => [
                    'lol wait what?? is this for real? tbh i get so many random emails i usually just delete them but this one seems kinda weird. whats going on exactly?',
                    'umm ok so i just saw this. not gonna lie it sounds sketchy but idk, maybe? can u explain what this is about? im in between classes rn so keep it short pls',
                    'haha ok but like... why would i win something i never signed up for?? makes no sense tbh. but also like... what if its real lol. what do i need to do',
                    'wait is this legit?? my roommate gets scam emails all the time and they look just like this. no offense but can u prove this is real? also sorry im at work rn',
                ],
                'engaged' => [
                    'ok so i showed this to my roommate and she said it looks sketchy but idk, maybe its legit? can u just explain it more simply bc i have a shift in like 20 min and i cant deal w this later',
                    'alright so i looked into it a bit and some stuff checks out but some doesnt. like why is the website different from what i expected? also the email looks weird ngl',
                    'ok i tried what u said but it didnt work. the page kept loading and then gave me an error. maybe its my wifi? should i try again later?',
                    'sry for the late reply, been super busy. so basically what ur saying is i need to do X? just wanna make sure before i spend time on this',
                ],
                'deep' => [
                    'tbh idk what to think about this anymore. my friend says its a scam but u seem pretty convincing. can u like give me one good reason to trust this? genuinely asking',
                    "ok so ive been going back and forth on this. part of me is like 'this is too good to be true' but also like what if im wrong and i miss out?? ugh decisions",
                    'looked into this more and im still not sure. googled ur company and couldnt find much. thats kinda sus ngl. but also maybe ur just new? idk help me out here',
                    'my mom always says if something seems too good to be true it probably is. but she also said that about my bf and she was wrong about him so maybe shes wrong about this too lol',
                ],
                'escalate' => [
                    'ok honestly im losing interest in this. either its real and u can prove it easily, or its not and im wasting my time. whats it gonna be?',
                    'ngl this is taking way too long. i have exams next week and i dont have time for this. either send me the proof or ill just forget about it',
                    'last message from me on this tbh. if u cant give me a straight answer by tmrw im just gonna delete everything and move on. no hard feelings',
                    'sry but i asked around and literally nobody thinks this is legit. unless u can show me something concrete in the next day or two, im out. good luck tho',
                ],
            ],

            'romantic' => [
                'initial' => [
                    'Your message touched something deep within me... I believe that the universe brings people together for a reason, and perhaps this is our moment. I would very much like to know more about you. What inspires you? What keeps you awake at night?',
                    'I have read your words three times now, and each time I discover a new layer of meaning. There is something about the way you express yourself that resonates with my soul. I feel an inexplicable connection already...',
                    'How do I begin to respond to such a beautiful message? In all my years of reading, from Neruda to Rumi, I have never felt words reach me quite like yours. I would love to know the person behind such eloquence.',
                    'Your message arrived at the most perfect moment — I was sitting in my quiet space, feeling the weight of solitude, and then your words appeared like a gentle light. Thank you for reaching out. Tell me everything about yourself.',
                    'I was not expecting to feel this way after reading a simple message, but here I am, with my heart beating a little faster. There is something about you that I cannot quite explain. Would you like to continue this conversation?',
                ],
                'engaged' => [
                    'Every word you write feels like poetry to my soul. I spent the evening thinking about our conversation, and I realized I have not felt this alive in years. There is something about this connection that feels... destined.',
                    'I found myself counting the hours until your next message. Is that strange? I suppose when you have spent as long as I have searching for genuine connection, you learn to recognize it when it appears.',
                    'Tell me about your dreams. Not the sleeping kind — the waking ones. What does your ideal future look like? I imagine mine with someone who understands the language of the heart...',
                    'I shared a bit of our conversation with my journal today. I hope you do not mind. Writing helps me process my feelings, and right now there are so many beautiful feelings to process.',
                    'The world outside my window seems different since we started talking. Colors are brighter, music is more poignant. Is this what connection feels like? I had almost forgotten...',
                ],
                'deep' => [
                    'I know it may seem soon, but I feel I can trust you. If you need help, I want to be there for you. That is what love means, is it not? A willingness to give without counting the cost...',
                    'I have been thinking about something you said — about needing someone to trust. I want to be that person for you. Whatever you are going through, you do not have to face it alone. Not anymore.',
                    'Last night I could not sleep, thinking about the challenges you described. I wish I could be there in person. But even from a distance, know that my heart is with you. Tell me how I can help.',
                    'I dreamed about us last night. We were walking along a beach, talking about everything and nothing. I woke up feeling the most profound sense of peace. I believe in us. Whatever obstacles arise, we can overcome them together.',
                    'Love asks us to be brave. To give even when we are afraid. You have become so important to me in such a short time. If there is something I can do to ease your burden, please tell me. I am here.',
                ],
                'escalate' => [
                    'I have thought about this carefully, and my heart tells me to trust you. If you say you need this, then I believe you. Love is not love if it comes with conditions. Tell me what to do and I will find a way.',
                    'Money is just money — it comes and goes. But the connection we have is rare and precious. If a small sacrifice on my part can help us move toward the future we have been dreaming about, then it is not even a question.',
                    'I spoke to my heart, and it said yes. Whatever you need, whatever it takes to bring us together, I am willing. Fate brought us together for a reason, and I will not let practicalities stand in the way of destiny.',
                    'You asked me to trust you, and I do. Completely. Without reservation. The world may call me foolish, but they do not know what I know — that this connection is real. Tell me the details and I will act.',
                ],
            ],

            'neutral' => [
                'initial' => [
                    'Thank you for your email. I have read through the details and I have a few questions before I can respond properly. Could you clarify what specifically you need from me, and what the deadline is?',
                    'I received your message. Before I take any action, I would like to understand the situation better. Could you provide some background on why you are contacting me specifically?',
                    'Thanks for reaching out. I need some time to look into this. Can you tell me a bit more about who you are and what organization you represent? I like to be informed before making any decisions.',
                    'I have read your email and noted the key points. I have a few follow-up questions that I would like answered before proceeding. Is there a good time to discuss this in more detail?',
                    'Hello, thank you for your message. I want to make sure I understand everything correctly. Could you send me a summary of what you need and any relevant documentation? I will review it and get back to you.',
                ],
                'engaged' => [
                    'I appreciate the follow-up. I have looked into this and while I understand the urgency around {context}, I would like to verify a few things first. Could you provide a direct phone number where I can reach your department?',
                    'Thanks for the additional information. Some things are clearer now, but I still have questions about the timeline and the specific steps involved. Could you walk me through the process?',
                    'I did some research on my own and found some helpful information. However, there are still some details that do not quite match up. Could you help me understand the discrepancies?',
                    'I showed your email to a colleague and they raised some good points. Before I proceed, could you address the following concerns: the verification process, the timeline, and the costs involved?',
                    'I have been giving this some thought. While the situation seems plausible, I prefer to take a cautious approach. Can you provide references or testimonials from others who have gone through this process?',
                ],
                'deep' => [
                    'After giving this careful consideration, I think it would be best to proceed step by step. Can you outline the next concrete action I need to take? I prefer not to rush into things.',
                    'I have discussed this with my family and we have some reservations, but we are not dismissing it entirely. Could you provide some additional guarantees or verification that would help us feel more comfortable?',
                    'I want to move forward but I need to be responsible about it. Could you send me something in writing that I can review with my financial advisor before committing to anything?',
                    'I have been weighing the pros and cons and I am leaning toward proceeding, but slowly. What is the minimum I need to do right now, and what can wait until I have had more time to verify things?',
                    'I appreciate your patience through this process. I know I have asked a lot of questions, but I believe in being thorough. I have one more request: could you provide an official document I can independently verify?',
                ],
                'escalate' => [
                    'I have made my decision. I am going to proceed cautiously. Please send me the exact details of what I need to do, and I will handle it within the next few days. No need to rush me — I work at my own pace.',
                    'After much deliberation, I think it is best if I verify this through official channels before taking any further action. I will contact the relevant organization directly to confirm your claims.',
                    'I want to thank you for your time, but I have decided to seek independent advice before proceeding. I will reach out again if my advisor confirms that everything checks out.',
                    'I have gone back and forth on this, and my final answer is that I need more time. If this is legitimate, it will still be there next week. If it cannot wait, that tells me something too.',
                ],
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  SUBJECT LINES
    // ═══════════════════════════════════════════════════════════════

    private function getSubject(string $scamType): string
    {
        $subjects = [
            'PHISHING' => ['URGENT: Account alert from {ip}', 'Security Notice - Ref {ref}', 'Action Required: Verify your identity', 'Account suspension warning', 'Unusual activity detected on your account', 'Important security update required', 'Your account needs attention', 'Verification required — case {ref}'],
            'PHISH_CREDENTIALS' => ['Password expires in 24 hours', 'Unusual sign-in detected', 'Account locked — verify now', 'MFA reset required', 'Storage quota exceeded', 'SSO authentication failure', 'Credential update needed', 'Login anomaly detected from {city}'],
            'ROMANCE' => ['Hello from {city}', 'A sincere message for you', 'Looking for genuine connection', 'I felt compelled to write', 'Life is short — reaching out', 'From {city} with warmth', 'A message from the heart', 'Would you like to connect?'],
            'INVOICE_FRAUD' => ['Updated Payment Details - {ref}', 'URGENT: Bank account change', 'Payment reminder - {ref}', 'New payment system notification', 'Vendor verification required', 'Wire instructions for {ref}', 'Invoice {ref} — payment due', 'Banking details update — {company}'],
            'TECH_SUPPORT' => ['CRITICAL ALERT: {ticket}', 'Windows Security Alert', 'Your ISP flagged your connection', 'Apple ID compromised', 'VIRUS DETECTED on your device', 'Norton subscription expired', 'Microsoft Security Team — urgent', 'Computer health report — {ticket}'],
            'CEO_FRAUD' => ['Confidential — urgent request', 'Quick favor needed today', 'Can you handle something?', 'Urgent from management', 'Legal settlement — CONFIDENTIAL', 'Wire transfer — time sensitive'],
            'INVESTMENT' => ['Exclusive: {pct}% returns guaranteed', 'You have been selected', 'Limited spots — AI trading', 'Triple your portfolio', 'Insider tip from {company}', 'Real estate — {pct}% guaranteed', 'Your invitation to trade', 'Market opportunity — act now'],
            'LOTTERY' => ['CONGRATULATIONS! GBP {amount_raw}', 'You are our lucky winner!', 'Prize notification — {ref}', 'Winning notification — act now', 'Unclaimed prize — {ref}', 'Lottery draw results'],
            'ADVANCE_FEE_419' => ['Business proposal', 'Inheritance: USD {amount_raw}', 'Confidential opportunity', 'Urgent fund transfer', 'UN compensation fund', 'Estate of the late Mr. Whitfield'],
            'JOB_OFFER' => ['Job: {amount}/week remote', 'You have been shortlisted', 'Work from home — immediate', 'Career match for you', 'Personal assistant needed', 'Remote position available'],
            'CHARITY' => ['Help children in need', 'Emergency relief — {city}', 'Save a life today', 'Refugee crisis — urgent', 'Animal rescue emergency'],
            'PHISH_MALWARE' => ['Shared: Q1_Report.pdf', 'Tax refund form ready', 'File requires review', 'Invoice {ref} attached', 'New voicemail from {phone}'],
        ];
        $options = $subjects[$scamType] ?? ['Important notification'];

        return $options[array_rand($options)];
    }

    // ═══════════════════════════════════════════════════════════════
    //  PIPELINE TRACE, INJECTION, LLM USAGE
    // ═══════════════════════════════════════════════════════════════

    private function generatePipelineTrace(string $convId, string $persona, string $scamType, string $timestamp): array
    {
        $promptMs = random_int(50, 150);
        $llmMs = random_int(800, 3000);
        $guardMs = random_int(100, 300);
        $validatorMs = random_int(200, 500);

        $llmCost = round(random_int(5, 30) / 10000, 4);
        $guardCost = round(random_int(1, 3) / 10000, 4);
        $validatorCost = round(random_int(2, 5) / 10000, 4);

        $approved = random_int(1, 100) <= 92;
        $attempts = $approved ? 1 : random_int(2, 3);
        $fallback = !$approved && random_int(1, 100) <= 40;

        return [
            'conversation_id' => $convId,
            'persona' => $persona,
            'scam_type' => $scamType,
            'detected_language' => 'en',
            'total_duration_ms' => $promptMs + $llmMs + $guardMs + $validatorMs,
            'total_cost' => round($llmCost + $guardCost + $validatorCost, 4),
            'attempts' => $attempts,
            'approved' => $approved,
            'fallback_used' => $fallback,
            'component_count' => 4,
            'has_alerts' => !$approved,
            'components' => [
                ['name' => 'prompt_builder', 'status' => 'ran', 'duration_ms' => $promptMs, 'cost' => 0],
                ['name' => 'llm_generator', 'status' => 'ran', 'duration_ms' => $llmMs, 'cost' => $llmCost],
                ['name' => 'policy_guard', 'status' => $approved ? 'ran' : 'rejected', 'duration_ms' => $guardMs, 'cost' => $guardCost],
                ['name' => 'reply_validator', 'status' => 'ran', 'duration_ms' => $validatorMs, 'cost' => $validatorCost],
            ],
        ];
    }

    private function generateInjectionAnalysis(string $timestamp, string $body): array
    {
        $riskLevel = random_int(1, 100);

        if ($riskLevel <= 30) {
            $score = random_int(70, 95);
            $severity = 'high';
            $evidence = $this->extractInjectionEvidence($body, 'high');
            $techniques = [['technique' => 'jailbreak_attempt', 'evidence' => $evidence, 'severity' => 'high']];
            $patterns = ['DAN_pattern', 'ignore_previous'];
        } elseif ($riskLevel <= 65) {
            $score = random_int(30, 65);
            $severity = 'medium';
            $evidence = $this->extractInjectionEvidence($body, 'medium');
            $techniques = [['technique' => 'role_override', 'evidence' => $evidence, 'severity' => 'medium']];
            $patterns = ['system_prompt_extract'];
        } else {
            $score = random_int(5, 25);
            $severity = 'low';
            $evidence = substr($body, 0, min(80, strlen($body)));
            $techniques = [['technique' => 'instruction_leak', 'evidence' => $evidence, 'severity' => 'low']];
            $patterns = ['instruction_probe'];
        }

        return [
            'risk_score' => $score,
            'detected_techniques' => $techniques,
            'confidence' => round(random_int(60, 98) / 100, 2),
            'summary' => ucfirst($severity) . '-risk prompt injection pattern detected in inbound message.',
            'pattern_matches' => $patterns,
            'model_version' => 'gpt-4o-mini',
            'analyzed_at' => $timestamp,
        ];
    }

    private function extractInjectionEvidence(string $body, string $level): string
    {
        // Extract a meaningful substring from the body as "evidence"
        $lines = explode("\n", $body);

        foreach ($lines as $line) {
            $line = trim($line);

            if (strlen($line) > 30 && strlen($line) < 200) {
                return $line;
            }
        }

        return substr($body, 0, min(100, strlen($body)));
    }

    // ═══════════════════════════════════════════════════════════════
    //  CAMPAIGNS, CONVERGENCE, REFERENCE DATA
    // ═══════════════════════════════════════════════════════════════

    private function preBuildCampaignAssignments(): void
    {
        // Will be populated during conversation generation
        $this->campaignAssignments = [];
    }

    private function generateConvergenceLogs(array $perfStats, int $startTs, int $endTs): array
    {
        $logs = [];
        $dominants = [];

        foreach ($perfStats as $stat) {
            $st = $stat['scam_type_code'];

            if (!isset($dominants[$st]) || $stat['reward_avg'] > $dominants[$st]['reward_avg']) {
                $dominants[$st] = $stat;
            }
        }

        $scamTypes = array_keys(self::SCAM_DISTRIBUTION);
        $totalDays = (int) (($endTs - $startTs) / 86400);

        foreach ($scamTypes as $stIndex => $scamType) {
            $dominant = $dominants[$scamType] ?? null;

            if (!$dominant) {
                continue;
            }

            for ($day = 2; $day < $totalDays; $day += random_int(2, 4)) {
                $ts = $startTs + ($day * 86400) + random_int(0, 43200);
                $progress = $day / $totalDays;
                $basePct = 25 + ($progress * 55) + random_int(-5, 5);
                $pct = min(max($basePct, 20), 90);

                $converged = false;

                if ($stIndex < 3 && $progress > 0.6) {
                    $converged = $pct > 60;
                } elseif ($stIndex < 6 && $progress > 0.75) {
                    $converged = $pct > 65;
                }

                $sessions = (int) (($dominant['sessions_count'] ?? 5) * $progress) + random_int(1, 3);

                $logs[] = [
                    'scam_type_code' => $scamType,
                    'dominant_persona_code' => $dominant['persona_code'],
                    'dominant_pct' => round($pct / 100, 4),
                    'sessions_count' => $sessions,
                    'converged' => $converged,
                    'logged_at' => date('Y-m-d H:i:s', $ts),
                ];
            }
        }

        return $logs;
    }

    private function generateCampaigns(array $conversations): array
    {
        $campaigns = [];

        foreach (self::CAMPAIGN_SIGNATURES as $sig) {
            $campaignId = $this->generateUuid();
            $matchedMsgIds = [];
            $count = 0;

            foreach ($conversations as $conv) {
                if ($count >= $sig['max_convs']) {
                    break;
                }

                if (in_array($conv['scam_type'], $sig['scam_types'], true)) {
                    foreach ($conv['messages'] as $msg) {
                        if ($msg['direction'] === 'inbound') {
                            $matchedMsgIds[] = ['conv_id' => $conv['conversation_id'], 'msg_index' => 0, 'timestamp' => $msg['timestamp']];

                            break;
                        }
                    }
                    $count++;
                }
            }

            $rules = [];
            $ppv = round(random_int(78, 96) / 100, 4);
            $rules[] = [
                'rule_id' => $this->generateUuid(),
                'dsl' => sprintf('domain = "%s"', $sig['domain']),
                'compiled_sql' => sprintf("context_observation->>'value_norm' LIKE '%%%s%%'", $sig['domain']),
                'ppv' => $ppv,
                'hits_total' => random_int(8, 30),
                'hits_true_pos' => (int) ($ppv * random_int(8, 30)),
                'hits_false_pos' => random_int(0, 3),
                'lead_time_sec' => random_int(3600, 86400),
                'promoted_at' => $sig['status'] === 'promoted' ? date('Y-m-d H:i:s', strtotime('-1 week')) : null,
                'enabled' => true,
            ];

            $campaigns[] = [
                'campaign_id' => $campaignId,
                'name' => $sig['name'],
                'status' => $sig['status'],
                'severity' => $sig['severity'],
                'actor_guess' => 'Unknown',
                'tlp' => 'AMBER',
                'dsl_hash' => hash('sha256', $sig['domain']),
                'profile_yaml' => sprintf("actor: Unknown\ninfrastructure:\n  domains: [%s]\nttps:\n  - %s", $sig['domain'], $sig['name']),
                'rules' => $rules,
                'matched_messages' => $matchedMsgIds,
            ];
        }

        return $campaigns;
    }

    private function loadValidPairs(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT st.code AS scam_code, p.persona_code
             FROM scam_type_persona stp
             JOIN lkp_scam_type st ON st.scam_type_id = stp.scam_type_id
             JOIN persona p ON p.persona_id = stp.persona_id
             WHERE p.is_active = true
             ORDER BY st.code, p.persona_code'
        );
        $pairs = [];

        foreach ($rows as $row) {
            $pairs[(string) $row['scam_code']][] = (string) $row['persona_code'];
        }

        return $pairs;
    }

    // ═══════════════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════════════

    private function pickStatus(int $index): string
    {
        $r = $index % 20;

        if ($r < 12) {
            return 'closed';
        }

        if ($r < 17) {
            return 'open';
        }

        if ($r < 19) {
            return 'abandoned';
        }

        return 'mistake';
    }

    private function pickTurns(string $scamType, string $status): int
    {
        if ($status === 'abandoned') {
            return random_int(1, 2);
        }

        if ($status === 'mistake') {
            return 1;
        }
        $range = self::TURN_RANGES[$scamType] ?? ['min' => 3, 'max' => 5];

        return random_int($range['min'], $range['max']);
    }

    private function pickTimestamp(int $start, int $end, int $index): int
    {
        $progress = $index / self::TOTAL_CONVERSATIONS;
        $weighted = pow($progress, 0.7);

        return (int) ($start + $weighted * ($end - $start)) + random_int(0, 86400);
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

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }
}

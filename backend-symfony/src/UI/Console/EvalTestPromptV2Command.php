<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\LLM\Port\LLMClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Prompt v2 intervention test.
 *
 * Runs a redesigned prompt (v2) against the same IOCs the production
 * pipeline already enriched. Uses the SAME model (gpt-4o-mini) so that
 * delta is attributable to the prompt change alone — model upgrade is
 * a separate intervention (E-S7).
 *
 * Prompt v2 addresses 5 axes from Phase D baseline findings:
 *  1. PASSIVE bias (60% prod vs 28% judge on test 100)
 *  2. urgency_score clumping at default values
 *  3. context_excerpt templating (77% duplicates in pop)
 *  4. hesitation_detected over-flagging (21% prod vs 0.67% judge)
 *  5. language_switch over-flagging (20% prod vs 3% judge)
 *
 * Plus a 6th refinement: semantic_role PHISHING_CREDENTIAL_URL was
 * over-extended to legitimate marketing URLs (Apollo, Snov, Facebook
 * notifications). v2 tightens the definition.
 *
 * Output:
 *   {out_dir}/enricher-v2-{obs_id}.json — same shape as judge JSON
 *   so app:eval:compute-metrics can ingest with --judge-model enricher-v2
 */
#[AsCommand(
    name: 'app:eval:test-prompt-v2',
    description: 'Run prompt v2 on a batch of IOCs (same model gpt-4o-mini)',
)]
final class EvalTestPromptV2Command extends Command
{
    private const MAX_IOCS_PER_INVOCATION = 200;
    private const SYSTEM_PROMPT = 'You are a cybersecurity analyst. Respond with valid JSON only, no markdown.';

    public function __construct(
        private readonly LLMClientInterface $llmClient,
        // Bound to %kernel.project_dir% (see config/services.yaml _defaults).
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('obs-id', null, InputOption::VALUE_REQUIRED, 'Single obs_id')
            ->addOption('from-csv', null, InputOption::VALUE_REQUIRED, 'CSV with obs_id column')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'LLM model', 'gpt-4o-mini')
            ->addOption('out-dir', null, InputOption::VALUE_REQUIRED, 'Output directory', '/app/var/eval')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'IOCs per invocation cap', '200')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Re-run even if output exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $obsIdSingle = (string) ($input->getOption('obs-id') ?? '');
        $fromCsv = (string) ($input->getOption('from-csv') ?? '');
        $model = (string) ($input->getOption('model') ?? 'gpt-4o-mini');
        $outDir = (string) ($input->getOption('out-dir') ?? '/app/var/eval');
        $limit = min(self::MAX_IOCS_PER_INVOCATION, (int) ($input->getOption('limit') ?? '200'));
        $overwrite = (bool) $input->getOption('overwrite');

        if ($obsIdSingle === '' && $fromCsv === '') {
            $io->error('Either --obs-id or --from-csv required');

            return Command::FAILURE;
        }

        if (!is_dir($outDir)) {
            $io->error("out-dir does not exist: {$outDir}");

            return Command::FAILURE;
        }

        $obsIds = $obsIdSingle !== '' ? [$obsIdSingle] : $this->readObsIdsFromCsv($fromCsv, $limit);
        $modelSlug = 'enricher-v2-' . str_replace(['/', ' ', ':'], '-', $model);

        $io->title("Prompt v2 run ({$model})");
        $io->writeln('  IOCs: ' . \count($obsIds));
        $io->writeln('  Tag:  ' . $modelSlug);
        $io->newLine();

        $okCount = 0;
        $skipCount = 0;
        $errCount = 0;

        foreach ($obsIds as $i => $obsId) {
            $outPath = sprintf('%s/judge-%s-%s.json', rtrim($outDir, '/'), $modelSlug, $obsId);

            if (!$overwrite && file_exists($outPath)) {
                ++$skipCount;

                continue;
            }

            $rendered = $this->renderIoc($obsId);

            if ($rendered === null) {
                $io->writeln(sprintf('  [%d/%d] %s — RENDER FAILED', $i + 1, \count($obsIds), $obsId));
                ++$errCount;

                continue;
            }

            $prompt = $this->buildPromptV2($rendered);

            try {
                $response = $this->llmClient->chat([
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => $prompt],
                ], [
                    'model' => $model,
                    'temperature' => 0.3,
                    'max_tokens' => 600,
                    'purpose' => 'prompt_v2',
                ]);
                $verdict = $this->parseResponse($response);

                $envelope = [
                    'obs_id' => $obsId,
                    'judge_model' => $modelSlug,
                    'judge_prompt_version' => 'v2',
                    'verdict' => $verdict,
                    'raw_response' => $response,
                ];
                file_put_contents($outPath, json_encode($envelope, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
                $io->writeln(sprintf('  [%d/%d] %s — OK', $i + 1, \count($obsIds), $obsId));
                ++$okCount;
            } catch (\Throwable $e) {
                $io->writeln(sprintf('  [%d/%d] %s — ERROR: %s', $i + 1, \count($obsIds), $obsId, $e->getMessage()));
                ++$errCount;
            }
        }

        $io->newLine();
        $io->writeln("ok={$okCount} skipped={$skipCount} errors={$errCount}");

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function readObsIdsFromCsv(string $csvPath, int $limit): array
    {
        if (!is_file($csvPath)) {
            throw new \RuntimeException("CSV not found: {$csvPath}");
        }
        $fh = fopen($csvPath, 'r');

        if ($fh === false) {
            throw new \RuntimeException("Cannot open CSV: {$csvPath}");
        }
        $header = fgetcsv($fh);

        if ($header === false || !\in_array('obs_id', $header, true)) {
            fclose($fh);

            throw new \RuntimeException('CSV must have an obs_id column');
        }
        $idx = array_search('obs_id', $header, true);
        $ids = [];

        while (($row = fgetcsv($fh)) !== false) {
            if (\count($ids) >= $limit) {
                break;
            }
            $ids[] = (string) $row[$idx];
        }
        fclose($fh);

        return $ids;
    }

    private function renderIoc(string $obsId): ?array
    {
        // Resolve the console binary from the project dir + current PHP
        // interpreter so this runs outside the container too (no /app/bin/console).
        $process = new Process([\PHP_BINARY, $this->projectDir . '/bin/console', 'app:eval:render-ioc', '--obs-id', $obsId, '--format', 'json']);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }
        $data = json_decode($process->getOutput(), true);

        return \is_array($data) ? $data : null;
    }

    private function buildPromptV2(array $payload): string
    {
        $msgWindow = $payload['window'] ?? [];
        $prev = $msgWindow['previous_inbound']['body_text'] ?? '(not available)';
        $stim = $msgWindow['stimulus']['body_text'] ?? '(not available)';
        $rev = $msgWindow['revelation']['body_text'] ?? '';
        $iocType = $payload['ioc']['type'] ?? 'unknown';
        $iocValue = $payload['ioc']['value'] ?? '';
        $scamType = $payload['scam_type'] ?? 'unknown';
        $persona = $payload['persona']['code'] ?? 'unknown';
        $turn = $payload['turn']['revelation_turn'] ?? '?';
        $total = $payload['turn']['total_turns'] ?? '?';

        return <<<PROMPT
            You are a cybersecurity analyst specializing in scambaiting honeypot analysis. Analyze this 3-message window and determine the semantic context of an IOC the scammer revealed.

            ## Context
            - Scam type (upstream classification, may be wrong): {$scamType}
            - Honeypot persona: {$persona}
            - IOC revelation turn: {$turn} of {$total}
            - IOC type: {$iocType}
            - IOC value: {$iocValue}

            ## Message Window

            ### Previous inbound (scammer message before our honeypot reply):
            {$prev}

            ### Stimulus (our honeypot reply, if any):
            {$stim}

            ### Revelation (scammer message containing the IOC):
            {$rev}

            ## Task — predict 7 fields. Strict definitions below.

            ### 1. stimulus_type — what triggered the scammer to reveal this IOC?

            DEFAULT EXPECTATION: stimulus type is NOT PASSIVE for any conversation past turn 1. PASSIVE is reserved for the SPECIFIC case described below. If a stimulus message exists, the IOC is almost always in REACTION to it.

            Options (consider in this order):

            - **DIRECT_REQUEST**: our honeypot explicitly asked for payment/contact/account info ("can you send me the IBAN?", "what's your phone number?")
            - **DOCUMENT_REQUEST**: our honeypot asked for documents, scammer responds with link or hash ("send me the invoice", "share the contract")
            - **PAYMENT_INITIATION**: scammer initiates payment flow with banking details for the first time (often in response to a soft cue)
            - **URGENCY_PRESSURE**: scammer uses explicit time limits, threats of closure, deadlines, or escalates pressure across turns
            - **TRUST_BUILDING**: scammer offers reassurance, social proof, or credentials before revealing IOCs (typical at early turns)
            - **PASSIVE**: scammer sent IOCs UNPROMPTED — either (a) first-contact spam with no honeypot reply yet, OR (b) automated follow-up signature/marketing nudge unrelated to the prior honeypot message
            - **UNKNOWN**: context genuinely ambiguous, do NOT use if any of the above fits

            ANTI-BIAS RULE: if you are tempted to pick PASSIVE on a multi-turn conversation, re-read the stimulus message. If our honeypot asked anything that the scammer is now answering, it is NOT PASSIVE.

            ### 2. scammer_urgency_score — float [0.0, 1.0]

            Calibrate to actual textual cues. Do NOT default to 0.5 or 0.75. Sample anchors:

            - 0.05: marketing nudge, automated follow-up, "let me know when you have time"
            - 0.20: "I look forward to your reply"
            - 0.40: clear request with reason ("please review this week")
            - 0.60: firm deadline ("by Friday or your application is delayed")
            - 0.80: explicit threat with hard deadline ("24 hours or account closure")
            - 0.95: ultimatum with legal/financial consequence ("pay now or we initiate proceedings")

            ### 3. hesitation_detected — boolean (STRICT)

            TRUE **only if** the scammer text shows clear self-correction or doubt:
            - "actually, let me check"
            - reformulation mid-sentence
            - asking for confirmation in the middle of a promise
            - abrupt topic switch suggesting evasion

            FALSE for all of these:
            - politeness ("I understand your concern")
            - delay apology ("sorry for the late reply")
            - scammer providing more detail in response to a question (this is RESPONSIVENESS, not hesitation)
            - scammer adjusting tactic across turns (this is adaptation, not hesitation)

            When in doubt → FALSE.

            ### 4. language_switch — boolean (STRICT)

            TRUE **only if** the scammer changes language WITHIN this message for a meaningful sentence.

            FALSE for all of these:
            - the entire email is in one non-English language (e.g. fully-French marketing email)
            - URL parameters or query strings in another language
            - proper nouns or brand names ("Bonjour", "Hola", "ciao")
            - email footers / unsubscribe links in a different language

            When in doubt → FALSE.

            ### 5. semantic_role — most specific role for THIS IOC

            For url:
            - **PHISHING_CREDENTIAL_URL**: URL path requests credentials (/login, /verify, /restore, /account-suspended, /unlock)
            - **MALWARE_DOWNLOAD_URL**: URL ends in executable (.exe, .pdf.exe) or hosts a payload download
            - **PAYMENT_REDIRECT_URL**: URL path is /pay, /checkout, crypto wallet redirect
            - **INFRASTRUCTURE_DOMAIN**: marketing URL, notification URL, unsubscribe URL, social profile, generic tracker — even on a suspicious-looking domain, if it is not credential-soliciting it is INFRASTRUCTURE, not PHISHING_CREDENTIAL_URL

            For domain: usually **INFRASTRUCTURE_DOMAIN**. Only PHISHING_CREDENTIAL_URL if the bare domain is a known phishing landing.

            For email / phone / telegram_username / discord_username: almost always **CONTACT_CHANNEL**.

            For iban / bic: **PAYMENT_DESTINATION** by default; **MONEY_MULE_ACCOUNT** when conversation strongly suggests laundering (intermediary account).

            For wallet_btc / wallet_eth / wallet_xmr: **PAYMENT_DESTINATION**.

            For sha256 / md5 / sha1:
            - if appears inline as a download integrity marker for a file the scammer wants you to run → MALWARE_DOWNLOAD_URL
            - otherwise → IDENTITY_DOCUMENT (signature blocks, audit fingerprints)

            ### 6. context_excerpt — ONE specific sentence, max 150 chars

            MUST name at least one CONCRETE detail from this conversation:
            - the pretext (inheritance, invoice, virus alert, package customs)
            - a named entity from the message (the alias the scammer used, the fake company, the impersonated brand)
            - the framing (24h deadline, crypto alternative, document request)

            BAD (generic, banned):
            - "Scammer provided contact details after engagement"
            - "Scammer revealed IOCs in phishing attempt"
            - "First-contact phishing email"

            GOOD:
            - "Captain Mark Thompson invoice fraud pretext with 24h deadline and crypto wallet alternative after grieving-widow honeypot replied"
            - "Apex Capital advance-fee scam offering BTC payment after skeptical honeypot demanded company verification"

            NO PII (no emails, phones, IBANs, wallet addresses, real victim names).

            ### 7. enrichment_confidence — float [0.0, 1.0]

            Honest self-assessment:
            - 0.30-0.50: only revelation message available, no stimulus, no previous inbound
            - 0.50-0.65: 2-message window, partial context
            - 0.65-0.85: full 3-message window with clear stimulus-response dynamic
            - 0.85-0.95: full window, unambiguous scammer pattern

            ## Output (strict JSON, no markdown)

            {
              "stimulus_type": "...",
              "scammer_urgency_score": 0.0,
              "hesitation_detected": false,
              "language_switch": false,
              "semantic_role": "...",
              "context_excerpt": "...",
              "enrichment_confidence": 0.0
            }

            Field name aliases also accepted (the metrics harness reads either): urgency_score, language_switch_detected, hesitation_detected.
            PROMPT;
    }

    /**
     * Normalize to the same shape produced by the judge command so the
     * metrics harness can ingest both via the same loader.
     */
    private function parseResponse(string $response): ?array
    {
        $r = trim($response);

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $r, $m)) {
            $r = $m[1];
        } elseif (preg_match('/(\{.*\})/s', $r, $m)) {
            $r = $m[1];
        }

        try {
            $decoded = json_decode($r, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        // Field aliasing: production prompt uses scammer_urgency_score +
        // language_switch_detected; judge prompt uses urgency_score +
        // language_switch. Normalize to the judge shape for the metrics
        // harness.
        return [
            'stimulus_type' => $decoded['stimulus_type'] ?? null,
            'urgency_score' => $decoded['urgency_score'] ?? $decoded['scammer_urgency_score'] ?? null,
            'hesitation_detected' => $decoded['hesitation_detected'] ?? null,
            'language_switch' => $decoded['language_switch'] ?? $decoded['language_switch_detected'] ?? null,
            'semantic_role' => $decoded['semantic_role'] ?? null,
            'context_excerpt' => $decoded['context_excerpt'] ?? '',
            'enrichment_confidence' => $decoded['enrichment_confidence'] ?? null,
        ];
    }
}

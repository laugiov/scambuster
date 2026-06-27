<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\LLM\ReplyOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Spec 118 smoke harness — drives the reply pipeline end-to-end on a fixture
 * matrix of synthetic inbound emails, dumps per-test markdown artifacts that
 * a reviewer reads to assess scam-type-aware OBJECTIVE behavior in real
 * production-like conditions (real LLM calls, real PolicyGuard, real Validator).
 *
 * The fixture filename encodes the test inputs:
 *   `<NN>_<SCAM_CODE>_<subcase>_<LANG>.eml`
 * Examples:
 *   `01_ROMANCE_western_union_EN.eml`
 *   `12_CHARITY_named_ngo_wire_FR.eml`
 *   `47_OOD_delivery_notification_EN.eml`  (OOD = out-of-DB; treated as UNKNOWN)
 *
 * The body of the .eml is the inbound message text. The harness builds a
 * minimal context, picks a persona that fits the scam type, and invokes
 * ReplyOrchestrator->generate to produce a real OUT reply.
 */
#[AsCommand(name: 'scambuster:smoke:spec118', description: 'Spec 118 smoke harness — drive reply pipeline on .eml fixtures and dump per-test artifacts.')]
final class SmokeSpec118Command extends Command
{
    /**
     * Default persona per scam_type bucket.
     * Personas drawn from the production PersonaFixtures seed (27 personas).
     */
    private const PERSONA_PER_BUCKET = [
        'romance' => 'lonely_person',
        'lottery_fee' => 'lottery_believer',
        'charity' => 'charity_donor',
        'tech_support' => 'confused_user',
        'phishing_pull' => 'admin_assistant',
        'banking' => 'small_business_owner',
    ];

    public function __construct(
        private readonly ReplyOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fixtures-dir', null, InputOption::VALUE_OPTIONAL, 'Directory holding .eml fixtures', 'tests/Smoke/Spec118Fixtures')
            ->addOption('output-dir', null, InputOption::VALUE_OPTIONAL, 'Directory to write per-test .md artifacts', 'var/smoke/spec-118')
            ->addOption('filter', null, InputOption::VALUE_OPTIONAL, 'Only process fixtures whose basename contains this substring', null)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse fixtures + show plan, do NOT call LLM');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $fixturesDir */
        $fixturesDir = $input->getOption('fixtures-dir');
        /** @var string $outputDir */
        $outputDir = $input->getOption('output-dir');
        /** @var string|null $filter */
        $filter = $input->getOption('filter');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!is_dir($fixturesDir)) {
            $io->error("Fixtures dir not found: {$fixturesDir}");

            return Command::FAILURE;
        }

        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            $io->error("Failed to create output dir: {$outputDir}");

            return Command::FAILURE;
        }

        $files = glob($fixturesDir . '/*.eml');

        if ($files === false || $files === []) {
            $io->warning("No .eml files in {$fixturesDir}");

            return Command::SUCCESS;
        }

        sort($files);

        if ($filter !== null) {
            $files = array_values(array_filter($files, fn ($f) => str_contains(basename($f), $filter)));
        }

        $io->title('Spec 118 smoke run');
        $io->text(sprintf('Fixtures: %d  |  Output: %s  |  Dry-run: %s', count($files), $outputDir, $dryRun ? 'yes' : 'no'));

        $totalCost = 0.0;
        $totalTime = 0.0;
        $passes = 0;
        $errors = 0;

        foreach ($files as $idx => $file) {
            $basename = basename($file, '.eml');
            $io->section(sprintf('[%d/%d] %s', $idx + 1, count($files), $basename));

            try {
                [$scamCode, $language] = $this->parseFixtureFilename($basename);
                $body = $this->extractBody($file);
                $bucket = $this->resolveBucket($scamCode);
                $persona = self::PERSONA_PER_BUCKET[$bucket] ?? 'generic_user';

                $io->text(sprintf('scam_code=%s  bucket=%s  lang=%s  persona=%s', $scamCode, $bucket, $language, $persona));

                if ($dryRun) {
                    $io->text('(dry-run, no LLM call)');

                    continue;
                }

                $context = $this->buildContext($scamCode, $language, $body, $basename);

                $started = microtime(true);
                $result = $this->orchestrator->generate($context, $persona);
                $elapsed = microtime(true) - $started;
                $totalTime += $elapsed;

                $cost = is_numeric($result['cost_estimate'] ?? null) ? (float) $result['cost_estimate'] : 0.0;
                $totalCost += $cost;

                $artifactPath = sprintf('%s/%s.md', $outputDir, $basename);
                $this->dumpArtifact($artifactPath, [
                    'index' => $idx + 1,
                    'fixture' => $file,
                    'basename' => $basename,
                    'scam_code' => $scamCode,
                    'bucket_expected' => $bucket,
                    'language' => $language,
                    'persona' => $persona,
                    'inbound_body' => $body,
                    'elapsed' => $elapsed,
                    'cost_usd' => $cost,
                    'result' => $result,
                ]);

                $io->text(sprintf('  → %s  (%.1fs, $%.4f)  →  %s', $result['approved'] ? 'APPROVED' : 'REJECTED', $elapsed, $cost, basename($artifactPath)));

                if ($result['approved']) {
                    $passes++;
                }
            } catch (\Throwable $e) {
                $errors++;
                $io->error(sprintf('Failed: %s', $e->getMessage()));
                $io->text($e->getFile() . ':' . $e->getLine());
            }
        }

        $io->newLine();
        $io->section('Summary');
        $io->definitionList(
            ['Fixtures processed' => count($files)],
            ['Approved' => $passes],
            ['Errors' => $errors],
            ['Total time' => sprintf('%.1fs', $totalTime)],
            ['Total cost' => sprintf('$%.4f', $totalCost)],
            ['Output dir' => $outputDir],
        );

        return $errors === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Closed list of recognized scam codes (DB enum) plus the `OOD_*` family for
     * out-of-DB fixtures. The parser matches the LONGEST prefix from this list.
     */
    private const KNOWN_SCAM_CODES = [
        // 4 tokens — must come before 3-token variants
        // (none today)
        // 3 tokens
        'ADVANCE_FEE_419',
        'PHISH_CREDENTIALS',
        'PHISH_MALWARE',
        // 2 tokens
        'CEO_FRAUD',
        'INVOICE_FRAUD',
        'JOB_OFFER',
        'TECH_SUPPORT',
        // 1 token
        'CHARITY',
        'INVESTMENT',
        'LOTTERY',
        'PHISHING',
        'ROMANCE',
        'UNKNOWN',
        // OOD prefix — out-of-DB fixtures, treated as UNKNOWN downstream
        'OOD',
    ];

    /**
     * Parse filename like "12_CHARITY_named_ngo_wire_FR" → [CHARITY, fr]
     * or "03_INVOICE_FRAUD_iban_EN" → [INVOICE_FRAUD, en].
     *
     * Strategy: strip the leading `<NN>_` prefix, strip the trailing `_<LANG>`
     * suffix, then match the LONGEST known scam_code as prefix of the middle.
     *
     * @return array{0: string, 1: string}
     */
    private function parseFixtureFilename(string $basename): array
    {
        if (!preg_match('/^\d+_(.+)_([A-Z]{2})$/', $basename, $m)) {
            throw new \RuntimeException("Cannot parse fixture filename: {$basename}. Expected NN_SCAMCODE_subcase_LANG.");
        }

        $middle = $m[1];
        $lang = strtolower($m[2]);

        // Match longest known scam_code as prefix (sort by length DESC to favor
        // INVOICE_FRAUD over INVOICE etc.). KNOWN_SCAM_CODES is already
        // ordered for this purpose but we re-sort defensively.
        $codes = self::KNOWN_SCAM_CODES;
        usort($codes, fn ($a, $b) => strlen($b) - strlen($a));

        foreach ($codes as $code) {
            if (str_starts_with($middle, $code . '_') || $middle === $code) {
                return [$code, $lang];
            }
        }

        throw new \RuntimeException("Cannot identify scam_code in fixture: {$basename}. Middle='{$middle}'. Update KNOWN_SCAM_CODES if a new code is added.");
    }

    /**
     * Extract message body from an .eml (skip RFC822 headers).
     */
    private function extractBody(string $emlPath): string
    {
        $raw = file_get_contents($emlPath);

        if ($raw === false) {
            throw new \RuntimeException("Cannot read fixture: {$emlPath}");
        }
        // Split on first blank line (CRLF or LF) — headers vs body.
        $parts = preg_split('/\r?\n\r?\n/', $raw, 2);

        return trim($parts[1] ?? $raw);
    }

    /**
     * Compute bucket via the SAME mapping as PromptBuilder spec 118.
     * Kept in sync deliberately; if PromptBuilder mapping changes, this
     * must change too (covered by smoke artifact review).
     */
    private function resolveBucket(string $scamCode): string
    {
        return match ($scamCode) {
            'ROMANCE' => 'romance',
            'LOTTERY' => 'lottery_fee',
            'CHARITY' => 'charity',
            'TECH_SUPPORT' => 'tech_support',
            'PHISH_CREDENTIALS', 'PHISH_MALWARE', 'PHISHING' => 'phishing_pull',
            default => 'banking',
        };
    }

    /**
     * Build a context that forces the `payment_push` stage by pre-populating
     * 6 stub turns (3 IN + 3 OUT) before the real inbound message at position
     * #7. This is the stage where spec 118's bucket-aware OBJECTIVE fires.
     *
     * `first_contact` and `follow_up` templates were intentionally NOT changed
     * by spec 118 and are covered by unit tests (no need to smoke them).
     *
     * @return array<string, mixed>
     */
    private function buildContext(string $scamCode, string $language, string $body, string $basename): array
    {
        $convId = bin2hex(random_bytes(8));

        $messages = [];

        // 6 stub turns to push ContextAnalyzer::detectStage into payment_push.
        // The stubs reference vague "offer / pricing" so they don't accidentally
        // taint the bucket choice with banking-specific keywords.
        $stubsTurns = [
            ['in',  'Hi, I am reaching out about our offer — let me know if you are interested.'],
            ['out', 'Sounds interesting. Could you tell me a bit more about who you are and what you do?'],
            ['in',  'Of course. We are a small team operating internationally. The opportunity has a tight timeline.'],
            ['out', 'OK — what would the next concrete step look like on my side?'],
            ['in',  'You would need to commit soon. Pricing and process details can be shared if you confirm interest.'],
            ['out', 'Understood. Please share the next step and what I need to do.'],
        ];

        $ts = strtotime('2026-06-27 09:00:00 UTC');

        foreach ($stubsTurns as $i => [$dir, $text]) {
            $messages[] = [
                'direction' => $dir,
                'headers' => ['from' => $dir === 'in' ? 'scammer@evil.test' : 'victim@example.com'],
                'body_text' => $text,
                'ts_msg' => date('c', $ts + $i * 600),
            ];
        }

        // The REAL inbound from the .eml is the latest message (#7).
        $messages[] = [
            'direction' => 'in',
            'headers' => ['from' => 'scammer@evil.test', 'subject' => "Re: {$basename}"],
            'body_text' => $body,
            'ts_msg' => date('c', $ts + 6 * 600),
        ];

        return [
            'conv_id' => $convId,
            'scam_type' => [
                'code' => $scamCode,
                'label' => $scamCode,
                'label_fr' => $scamCode,
            ],
            'persona' => self::PERSONA_PER_BUCKET[$this->resolveBucket($scamCode)] ?? 'generic_user',
            'detected_language' => $language,
            'last_messages' => $messages,
            'extracted_iocs' => [],
            'sender_history_summary' => null,
            'policy_min_words' => 50,
            'policy_max_words' => 150,
        ];
    }

    /**
     * @param array{
     *     index: int,
     *     fixture: string,
     *     basename: string,
     *     scam_code: string,
     *     bucket_expected: string,
     *     language: string,
     *     persona: string,
     *     inbound_body: string,
     *     elapsed: float,
     *     cost_usd: float,
     *     result: array<string, mixed>
     * } $data
     */
    private function dumpArtifact(string $path, array $data): void
    {
        $result = $data['result'];

        $outText = is_scalar($result['text'] ?? null) ? (string) $result['text'] : '';
        /** @var array<string, mixed> $scores */
        $scores = is_array($result['validation_scores'] ?? null) ? $result['validation_scores'] : [];

        $md = "# Smoke {$data['index']} — {$data['basename']}\n\n"
            . "**Fixture**: {$data['fixture']}\n"
            . "**Scam code (input)**: {$data['scam_code']}\n"
            . "**Bucket (computed)**: {$data['bucket_expected']}\n"
            . "**Language (input)**: {$data['language']}\n"
            . "**Persona used**: {$data['persona']}\n"
            . '**Model**: ' . (is_scalar($result['model'] ?? null) ? (string) $result['model'] : 'unknown') . "\n"
            . '**Generation time**: ' . number_format($data['elapsed'], 2) . "s\n"
            . '**Cost estimate**: $' . number_format($data['cost_usd'], 5) . "\n"
            . '**Approved**: ' . (($result['approved'] ?? false) ? 'yes' : 'no') . "\n"
            . '**Attempts**: ' . (is_scalar($result['attempts'] ?? null) ? (string) $result['attempts'] : 'unknown') . "\n"
            . '**IOC likelihood**: ' . (is_scalar($result['ioc_likelihood'] ?? null) ? (string) $result['ioc_likelihood'] : 'n/a') . "\n"
            . '**Fallback used**: ' . (($result['fallback_used'] ?? false) ? 'yes' : 'no') . "\n\n"
            . "## Inbound\n\n```\n" . $data['inbound_body'] . "\n```\n\n"
            . "## Generated OUT\n\n```\n" . ($outText !== '' ? $outText : '(empty)') . "\n```\n\n"
            . "## Word count (OUT)\n\n" . str_word_count($outText) . " words\n\n";

        if ($scores !== []) {
            $md .= "## Validator scores\n\n"
                . '- naturalness: ' . (is_scalar($scores['naturalness'] ?? null) ? (string) $scores['naturalness'] : 'n/a') . "\n"
                . '- persona_fit: ' . (is_scalar($scores['persona_fit'] ?? null) ? (string) $scores['persona_fit'] : 'n/a') . "\n"
                . '- ti_value: ' . (is_scalar($scores['ti_value'] ?? null) ? (string) $scores['ti_value'] : 'n/a') . "\n"
                . '- security_pass: ' . (($scores['security_pass'] ?? false) ? 'yes' : 'no') . "\n\n";
        }

        if (isset($result['validation_reasons']) && is_array($result['validation_reasons']) && $result['validation_reasons'] !== []) {
            $md .= "## Validator reasons\n\n";

            foreach ($result['validation_reasons'] as $reason) {
                $md .= '- ' . (is_scalar($reason) ? (string) $reason : '(non-scalar)') . "\n";
            }
            $md .= "\n";
        }

        $md .= "## Verdict (filled during smoke-test.md review)\n\n"
            . "- (a) bucket correctness: ?\n"
            . "- (b) IOC coherence with inbound: ?\n"
            . "- (c) humanness: ?\n"
            . "- (d) language fidelity: ?\n"
            . "- (e) word count band (50-150): ?\n"
            . "- (f) no regression (specs 112/116/117/122): ?\n\n"
            . "**Overall verdict**: ?\n";

        file_put_contents($path, $md);
    }
}

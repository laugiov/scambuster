<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Guard\CanarySummary;
use App\Application\LLM\ReplyOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Smoke harness — drives the reply pipeline end-to-end on a fixture
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
#[AsCommand(name: 'scambuster:smoke:reply-objective', description: 'Smoke harness — drive reply pipeline on .eml fixtures and dump per-test artifacts.')]
final class ReplyObjectiveSmokeCommand extends Command
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
        private readonly ?\App\Application\Communication\PersonaManager $personaManager = null,
    ) {
        parent::__construct();
    }

    /**
     * Resolve a persona's free-text tone descriptor so the generated
     * context mirrors production (PolicyGuardConfig reads persona_tone to
     * give terse archetypes a reachable length floor). Null when the
     * persona is unknown or the manager is unavailable.
     */
    private function resolvePersonaTone(string $personaCode): ?string
    {
        $persona = $this->personaManager?->findByCode($personaCode);

        return $persona?->getPersonaTone();
    }

    /**
     * A synthetic RFC 4122 v4 UUID for the throwaway conversation id.
     * The guards query the DB by conv_id (a uuid column), so a bare hex
     * string fails the Postgres uuid cast; the value never matches a real
     * row (read-only, nothing is persisted by the smoke).
     */
    private function syntheticConvId(): string
    {
        $b = random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0F) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    protected function configure(): void
    {
        $this
            ->addOption('fixtures-dir', null, InputOption::VALUE_OPTIONAL, 'Directory holding .eml or .json fixtures', 'tests/Smoke/ReplyObjectiveFixtures')
            ->addOption('output-dir', null, InputOption::VALUE_OPTIONAL, 'Directory to write per-test .md artifacts', 'var/smoke/reply-objective')
            ->addOption('filter', null, InputOption::VALUE_OPTIONAL, 'Only process fixtures whose basename contains this substring', null)
            ->addOption('runs', null, InputOption::VALUE_OPTIONAL, 'Number of times to run each fixture (variance check)', '1')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse fixtures + show plan, do NOT call LLM')
            ->addOption('summary-json', null, InputOption::VALUE_OPTIONAL, 'Write a machine-readable JSON summary (per-fixture + aggregate) to this path', null);
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
        $summaryJsonOpt = $input->getOption('summary-json');
        $summaryJsonPath = is_string($summaryJsonOpt) && $summaryJsonOpt !== '' ? $summaryJsonOpt : null;
        $summary = $summaryJsonPath !== null ? new CanarySummary() : null;

        if (!is_dir($fixturesDir)) {
            $io->error("Fixtures dir not found: {$fixturesDir}");

            return Command::FAILURE;
        }

        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            $io->error("Failed to create output dir: {$outputDir}");

            return Command::FAILURE;
        }

        $emlFiles = glob($fixturesDir . '/*.eml') ?: [];
        $jsonFiles = glob($fixturesDir . '/*.json') ?: [];
        $files = array_merge($emlFiles, $jsonFiles);

        if ($files === []) {
            $io->warning("No .eml or .json files in {$fixturesDir}");

            return Command::SUCCESS;
        }

        sort($files);

        if ($filter !== null) {
            $files = array_values(array_filter($files, fn ($f) => str_contains(basename($f), $filter)));
        }

        $runsOpt = $input->getOption('runs');
        $runs = max(1, is_numeric($runsOpt) ? (int) $runsOpt : 1);

        $io->title('Smoke run');
        $io->text(sprintf('Fixtures: %d  |  Runs each: %d  |  Output: %s  |  Dry-run: %s', count($files), $runs, $outputDir, $dryRun ? 'yes' : 'no'));

        $totalCost = 0.0;
        $totalTime = 0.0;
        $passes = 0;
        $errors = 0;

        foreach ($files as $idx => $file) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            $basename = basename($file, '.' . $ext);
            $io->section(sprintf('[%d/%d] %s', $idx + 1, count($files), $basename));

            for ($run = 1; $run <= $runs; $run++) {
                $runSuffix = $runs > 1 ? sprintf('_run%d', $run) : '';
                $artifactPath = sprintf('%s/%s%s.md', $outputDir, $basename, $runSuffix);

                try {
                    if ($ext === 'json') {
                        [$cost, $elapsed, $approved] = $this->runMultiTurnFixture($io, $file, $basename, $artifactPath, $idx + 1, $dryRun, $summary);
                    } else {
                        [$cost, $elapsed, $approved] = $this->runSingleTurnFixture($io, $file, $basename, $artifactPath, $idx + 1, $dryRun, $summary);
                    }

                    if ($dryRun) {
                        break; // dry-run shows plan once
                    }

                    $totalCost += $cost;
                    $totalTime += $elapsed;

                    if ($approved) {
                        $passes++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    $summary?->recordError();
                    $io->error(sprintf('Failed: %s', $e->getMessage()));
                    $io->text($e->getFile() . ':' . $e->getLine());
                }
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

        if ($summary !== null && !$dryRun) {
            // $summaryJsonPath is non-null whenever $summary was created; cast so static
            // analysis sees a string (the two are set together above).
            $path = (string) $summaryJsonPath;
            $summaryDir = dirname($path);

            if (!is_dir($summaryDir) && !mkdir($summaryDir, 0755, true) && !is_dir($summaryDir)) {
                $io->error("Failed to create summary dir: {$summaryDir}");

                return Command::FAILURE;
            }

            if (file_put_contents($path, $summary->toJson()) === false) {
                $io->error("Failed to write summary JSON: {$path}");

                return Command::FAILURE;
            }

            $io->writeln(sprintf('Summary JSON: <comment>%s</comment>', $path));
        }

        return $errors === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Single-turn .eml fixture: parse filename + body, build context with
     * 6 stub prior turns forcing payment_push, call orchestrator once.
     *
     * @return array{0: float, 1: float, 2: bool} [cost, elapsed, approved]
     */
    private function runSingleTurnFixture(SymfonyStyle $io, string $file, string $basename, string $artifactPath, int $idx, bool $dryRun, ?CanarySummary $summary = null): array
    {
        [$scamCode, $language] = $this->parseFixtureFilename($basename);
        $body = $this->extractBody($file);
        $bucket = $this->resolveBucket($scamCode);
        $persona = self::PERSONA_PER_BUCKET[$bucket] ?? 'generic_user';

        $io->text(sprintf('scam_code=%s  bucket=%s  lang=%s  persona=%s', $scamCode, $bucket, $language, $persona));

        if ($dryRun) {
            $io->text('(dry-run, no LLM call)');

            return [0.0, 0.0, false];
        }

        $context = $this->buildContext($scamCode, $language, $body, $basename);

        $started = microtime(true);
        $result = $this->orchestrator->generate($context, $persona);
        $elapsed = microtime(true) - $started;

        $cost = is_numeric($result['cost_estimate'] ?? null) ? (float) $result['cost_estimate'] : 0.0;

        $this->dumpArtifact($artifactPath, [
            'index' => $idx,
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

        $approved = (bool) ($result['approved'] ?? false);
        $io->text(sprintf('  → %s  (%.1fs, $%.4f)  →  %s', $approved ? 'APPROVED' : 'REJECTED', $elapsed, $cost, basename($artifactPath)));

        $summary?->record(
            $basename,
            $approved,
            is_numeric($result['attempts'] ?? null) ? (int) $result['attempts'] : 0,
            (bool) ($result['fallback_used'] ?? false),
            $cost,
            is_scalar($result['text'] ?? null) ? (string) $result['text'] : null,
            $language,
        );

        return [$cost, $elapsed, $approved];
    }

    /**
     * Multi-turn .json fixture: walk turns array left-to-right, generate
     * persona replies at each `"generate": true` step, capture per-turn
     * artifacts in one combined markdown file.
     *
     * Expected JSON shape:
     *   {
     *     "scam_code": "CEO_FRAUD",
     *     "language": "en",
     *     "expected_bucket": "banking",
     *     "persona": "small_business_owner" (optional, defaults from bucket),
     *     "scenario": "free-form description",
     *     "turns": [
     *       {"from": "attacker", "body": "..."},
     *       {"from": "persona", "body": "..."}    // scripted
     *       OR
     *       {"from": "persona", "generate": true}  // generated at run time
     *     ]
     *   }
     *
     * @return array{0: float, 1: float, 2: bool} [totalCost, totalElapsed, allApproved]
     */
    private function runMultiTurnFixture(SymfonyStyle $io, string $file, string $basename, string $artifactPath, int $idx, bool $dryRun, ?CanarySummary $summary = null): array
    {
        $raw = file_get_contents($file);

        if ($raw === false) {
            throw new \RuntimeException("Cannot read fixture: {$file}");
        }
        $script = json_decode($raw, true);

        if (!is_array($script)) {
            throw new \RuntimeException("Invalid JSON in fixture: {$file}");
        }

        $scamCode = is_string($script['scam_code'] ?? null) ? $script['scam_code'] : 'UNKNOWN';
        $language = is_string($script['language'] ?? null) ? $script['language'] : 'en';
        $bucket = $this->resolveBucket($scamCode);
        $persona = is_string($script['persona'] ?? null) ? $script['persona'] : (self::PERSONA_PER_BUCKET[$bucket] ?? 'generic_user');
        $scenario = is_string($script['scenario'] ?? null) ? $script['scenario'] : '(no scenario)';
        /** @var list<array<string, mixed>> $turns */
        $turns = is_array($script['turns'] ?? null) ? $script['turns'] : [];

        $io->text(sprintf('multi-turn  scam_code=%s  bucket=%s  lang=%s  persona=%s  turns=%d', $scamCode, $bucket, $language, $persona, count($turns)));
        $io->text(sprintf('scenario: %s', $scenario));

        if ($dryRun) {
            $io->text('(dry-run, no LLM call)');

            return [0.0, 0.0, false];
        }

        $ts = strtotime('2026-06-27 09:00:00 UTC');
        $messages = [];
        $genRuns = []; // captured per-generate artifacts
        $totalCost = 0.0;
        $totalElapsed = 0.0;
        $allApproved = true;

        foreach ($turns as $turnIdx => $turn) {
            $from = is_string($turn['from'] ?? null) ? $turn['from'] : 'attacker';
            $dir = $from === 'persona' ? 'out' : 'in';
            $shouldGenerate = $from === 'persona' && (bool) ($turn['generate'] ?? false);

            if ($shouldGenerate) {
                // Build context up to this point and generate.
                $context = [
                    'conv_id' => $this->syntheticConvId(),
                    'scam_type' => ['code' => $scamCode, 'label' => $scamCode, 'label_fr' => $scamCode],
                    'persona' => $persona,
                    'persona_tone' => $this->resolvePersonaTone($persona),
                    'detected_language' => $language,
                    'last_messages' => $messages,
                    'extracted_iocs' => [],
                    'sender_history_summary' => null,
                    'policy_min_words' => 20,
                    'policy_max_words' => 150,
                ];

                $started = microtime(true);
                $result = $this->orchestrator->generate($context, $persona);
                $elapsed = microtime(true) - $started;
                $cost = is_numeric($result['cost_estimate'] ?? null) ? (float) $result['cost_estimate'] : 0.0;
                $totalCost += $cost;
                $totalElapsed += $elapsed;

                $approved = (bool) ($result['approved'] ?? false);
                $generatedText = is_scalar($result['text'] ?? null) ? (string) $result['text'] : '';

                if (!$approved) {
                    $allApproved = false;
                }

                $messages[] = [
                    'direction' => 'out',
                    'headers' => ['from' => 'victim@example.com'],
                    'body_text' => $generatedText,
                    'ts_msg' => date('c', $ts + $turnIdx * 600),
                ];

                $genRuns[] = [
                    'turn' => $turnIdx + 1,
                    'elapsed' => $elapsed,
                    'cost' => $cost,
                    'approved' => $approved,
                    'text' => $generatedText,
                    'attempts' => $result['attempts'] ?? '?',
                    'scores' => is_array($result['validation_scores'] ?? null) ? $result['validation_scores'] : [],
                    'reasons' => is_array($result['validation_reasons'] ?? null) ? $result['validation_reasons'] : [],
                ];

                $summary?->record(
                    $basename . '#turn' . ($turnIdx + 1),
                    $approved,
                    is_numeric($result['attempts'] ?? null) ? (int) $result['attempts'] : 0,
                    (bool) ($result['fallback_used'] ?? false),
                    $cost,
                    $generatedText !== '' ? $generatedText : null,
                    $language,
                );

                $io->text(sprintf('  turn %d (generate)  → %s  (%.1fs, $%.4f)', $turnIdx + 1, $approved ? 'APPROVED' : 'REJECTED', $elapsed, $cost));
            } else {
                $body = is_string($turn['body'] ?? null) ? $turn['body'] : '';
                $messages[] = [
                    'direction' => $dir,
                    'headers' => ['from' => $dir === 'in' ? 'scammer@evil.test' : 'victim@example.com'],
                    'body_text' => $body,
                    'ts_msg' => date('c', $ts + $turnIdx * 600),
                ];
            }
        }

        $this->dumpMultiTurnArtifact($artifactPath, [
            'index' => $idx,
            'fixture' => $file,
            'basename' => $basename,
            'scam_code' => $scamCode,
            'bucket_expected' => $bucket,
            'language' => $language,
            'persona' => $persona,
            'scenario' => $scenario,
            'messages' => $messages,
            'gen_runs' => $genRuns,
            'total_elapsed' => $totalElapsed,
            'total_cost' => $totalCost,
        ]);

        $io->text(sprintf('  → multi-turn done  (%.1fs total, $%.4f total, %d generations)  →  %s', $totalElapsed, $totalCost, count($genRuns), basename($artifactPath)));

        return [$totalCost, $totalElapsed, $allApproved];
    }

    /**
     * @param array{
     *     index: int, fixture: string, basename: string, scam_code: string,
     *     bucket_expected: string, language: string, persona: string,
     *     scenario: string,
     *     messages: list<array<string, mixed>>,
     *     gen_runs: list<array<string, mixed>>,
     *     total_elapsed: float, total_cost: float
     * } $data
     */
    private function dumpMultiTurnArtifact(string $path, array $data): void
    {
        $md = "# Smoke {$data['index']} (multi-turn) — {$data['basename']}\n\n"
            . "**Fixture**: {$data['fixture']}\n"
            . "**Scam code**: {$data['scam_code']}\n"
            . "**Bucket**: {$data['bucket_expected']}\n"
            . "**Language**: {$data['language']}\n"
            . "**Persona**: {$data['persona']}\n"
            . "**Scenario**: {$data['scenario']}\n"
            . '**Total time**: ' . number_format($data['total_elapsed'], 2) . "s\n"
            . '**Total cost**: $' . number_format($data['total_cost'], 5) . "\n"
            . '**Generations**: ' . count($data['gen_runs']) . "\n\n"
            . "## Full conversation transcript\n\n";

        foreach ($data['messages'] as $i => $m) {
            $tag = ($m['direction'] ?? '') === 'in' ? 'ATTACKER' : 'PERSONA';
            $body = is_scalar($m['body_text'] ?? null) ? (string) $m['body_text'] : '';
            $md .= '### Turn ' . ($i + 1) . " — {$tag}\n\n```\n" . $body . "\n```\n\n";
        }

        $md .= "## Per-generation details\n\n";

        foreach ($data['gen_runs'] as $gen) {
            $scores = is_array($gen['scores'] ?? null) ? $gen['scores'] : [];
            $turnNo = is_scalar($gen['turn'] ?? null) ? (string) $gen['turn'] : '?';
            $md .= "### Generated turn {$turnNo}\n\n"
                . '- approved: ' . ((bool) ($gen['approved'] ?? false) ? 'yes' : 'no') . "\n"
                . '- attempts: ' . (is_scalar($gen['attempts'] ?? null) ? (string) $gen['attempts'] : '?') . "\n"
                . '- elapsed: ' . number_format(is_numeric($gen['elapsed'] ?? null) ? (float) $gen['elapsed'] : 0.0, 2) . "s\n"
                . '- cost: $' . number_format(is_numeric($gen['cost'] ?? null) ? (float) $gen['cost'] : 0.0, 5) . "\n";

            if ($scores !== []) {
                $md .= '- scores: naturalness=' . (is_scalar($scores['naturalness'] ?? null) ? (string) $scores['naturalness'] : 'n/a')
                    . ', persona_fit=' . (is_scalar($scores['persona_fit'] ?? null) ? (string) $scores['persona_fit'] : 'n/a')
                    . ', ti_value=' . (is_scalar($scores['ti_value'] ?? null) ? (string) $scores['ti_value'] : 'n/a')
                    . ', security_pass=' . (($scores['security_pass'] ?? false) ? 'yes' : 'no') . "\n";
            }
            $md .= "\n";
        }

        $md .= "## Verdict (filled during smoke-test-v2.md review)\n\n"
            . "- Bucket correctness across all turns: ?\n"
            . "- IOC coherence (no off-topic asks): ?\n"
            . "- Anti-repetition (no Q re-asked verbatim): ?\n"
            . "- No payment instigation: ?\n"
            . "- No out-of-band channel offered: ?\n"
            . "- Humanness across turns: ?\n"
            . "- Language fidelity: ?\n\n"
            . "**Overall verdict**: ?\n";

        file_put_contents($path, $md);
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
     * Compute bucket via the SAME mapping as PromptBuilder.
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
     * #7. This is the stage where the bucket-aware OBJECTIVE fires.
     *
     * `first_contact` and `follow_up` templates were intentionally NOT changed
     * and are covered by unit tests (no need to smoke them).
     *
     * @return array<string, mixed>
     */
    private function buildContext(string $scamCode, string $language, string $body, string $basename): array
    {
        $convId = $this->syntheticConvId();

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
            'persona_tone' => $this->resolvePersonaTone(self::PERSONA_PER_BUCKET[$this->resolveBucket($scamCode)] ?? 'generic_user'),
            'detected_language' => $language,
            'last_messages' => $messages,
            'extracted_iocs' => [],
            'sender_history_summary' => null,
            'policy_min_words' => 20,
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
            . "## Word count (OUT)\n\n" . \App\Application\LLM\WordCounter::count($outText) . " words\n\n";

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
            . "- (e) word count band (20-150): ?\n"
            . "- (f) no regression (payment/out-of-band/anti-repetition guards): ?\n\n"
            . "**Overall verdict**: ?\n";

        file_put_contents($path, $md);
    }
}

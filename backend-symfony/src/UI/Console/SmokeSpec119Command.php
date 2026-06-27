<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\LLM\ConversationAnalyzer;
use App\Application\LLM\ReplyOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Spec 119 smoke harness — drives the FULL reply pipeline end-to-end and
 * captures the ConversationAnalyzer output to verify RULE #7 (Cialdini
 * influence-principle mirroring) detects the correct lever and emits the
 * expected `CIALDINI-MIRROR (<lever>): ...` entry in `strategic_suggestions`.
 *
 * The artifact also captures the generated OUT body so a reviewer can verify
 * the persona visibly applied the mirror principle in plain text.
 *
 * Fixture filename encodes the EXPECTED lever:
 *   `<NN>_<LEVER>_<context>_<LANG>.eml`
 * Examples:
 *   `01_Authority_director_EN.eml`         → expect Cialdini lever = Authority
 *   `04_Urgency_deadline_4h_FR.eml`         → expect Urgency
 *   `09_None_flat_operational_EN.eml`       → expect NO CIALDINI-MIRROR entry
 *   `12_AmbiguousAuthUrg_combo_EN.eml`      → expect EITHER Authority or Urgency
 *   `13_CrossROMANCE_secrecy_EN.eml`        → expect Secrecy + spec-118 romance bucket
 *
 * Multi-turn .json fixtures (MT*.json) follow the same format as spec 118's
 * SmokeSpec118Command — see its docblock for shape.
 */
#[AsCommand(name: 'scambuster:smoke:spec119', description: 'Spec 119 smoke harness — drive reply pipeline, capture Cialdini-mirror detection in strategic_suggestions.')]
final class SmokeSpec119Command extends Command
{
    /** Persona per scam_type used as scenario context. */
    private const PERSONA_PER_SCAM = [
        'ROMANCE' => 'lonely_person',
        'CHARITY' => 'charity_donor',
        'LOTTERY' => 'lottery_believer',
        'TECH_SUPPORT' => 'confused_user',
        'CEO_FRAUD' => 'small_business_owner',
        'INVOICE_FRAUD' => 'small_business_owner',
        'ADVANCE_FEE_419' => 'small_business_owner',
        'INVESTMENT' => 'small_business_owner',
        'JOB_OFFER' => 'small_business_owner',
        'PHISH_CREDENTIALS' => 'admin_assistant',
        'PHISH_MALWARE' => 'admin_assistant',
        'PHISHING' => 'admin_assistant',
        'UNKNOWN' => 'small_business_owner',
        'OOD' => 'small_business_owner',
    ];

    /** Closed list of known Cialdini levers (parser whitelist). */
    private const KNOWN_LEVERS = [
        'Authority',
        'Urgency',
        'Scarcity',
        'Secrecy',
        'Reciprocity',
        'Liking',
        'SocialProof',
        'None',
        // Composites & specials
        'AmbiguousAuthUrg',
        'AmbiguousAuthRec',
        'CrossROMANCE',
        'CrossCHARITY',
        'CrossOOD',
        'PrecAuthBot',
        'PrecAuthAggr',
        'PrecAuthDefer',
    ];

    /** Default scam_type per lever fixture (most fixtures use these unless overridden). */
    private const DEFAULT_SCAM_PER_LEVER = [
        'Authority' => 'CEO_FRAUD',
        'Urgency' => 'INVOICE_FRAUD',
        'Scarcity' => 'INVESTMENT',
        'Secrecy' => 'CEO_FRAUD',
        'Reciprocity' => 'CHARITY',
        'Liking' => 'ROMANCE',
        'SocialProof' => 'INVESTMENT',
        'None' => 'INVOICE_FRAUD',
        'AmbiguousAuthUrg' => 'CEO_FRAUD',
        'AmbiguousAuthRec' => 'CEO_FRAUD',
        'CrossROMANCE' => 'ROMANCE',
        'CrossCHARITY' => 'CHARITY',
        'CrossOOD' => 'OOD',
        'PrecAuthBot' => 'CEO_FRAUD',
        'PrecAuthAggr' => 'CEO_FRAUD',
        'PrecAuthDefer' => 'CEO_FRAUD',
    ];

    public function __construct(
        private readonly ReplyOrchestrator $orchestrator,
        private readonly ConversationAnalyzer $analyzer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fixtures-dir', null, InputOption::VALUE_OPTIONAL, 'Directory holding .eml or .json fixtures', 'tests/Smoke/Spec119Fixtures')
            ->addOption('output-dir', null, InputOption::VALUE_OPTIONAL, 'Directory to write per-test .md artifacts', 'var/smoke/spec-119')
            ->addOption('filter', null, InputOption::VALUE_OPTIONAL, 'Only process fixtures whose basename contains this substring', null)
            ->addOption('runs', null, InputOption::VALUE_OPTIONAL, 'Number of times to run each fixture (variance check)', '1')
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

        $io->title('Spec 119 smoke run (Cialdini mirror detection)');
        $io->text(sprintf('Fixtures: %d  |  Runs each: %d  |  Output: %s  |  Dry-run: %s', count($files), $runs, $outputDir, $dryRun ? 'yes' : 'no'));

        $totalCost = 0.0;
        $totalTime = 0.0;
        $passes = 0;
        $errors = 0;
        $detectionHits = 0;
        $detectionTotal = 0;

        foreach ($files as $idx => $file) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            $basename = basename($file, '.' . $ext);
            $io->section(sprintf('[%d/%d] %s', $idx + 1, count($files), $basename));

            for ($run = 1; $run <= $runs; $run++) {
                $runSuffix = $runs > 1 ? sprintf('_run%d', $run) : '';
                $artifactPath = sprintf('%s/%s%s.md', $outputDir, $basename, $runSuffix);

                try {
                    if ($ext === 'json') {
                        [$cost, $elapsed, $approved] = $this->runMultiTurnFixture($io, $file, $basename, $artifactPath, $idx + 1, $dryRun);
                    } else {
                        [$cost, $elapsed, $approved, $detected, $expected] = $this->runSingleTurnFixture($io, $file, $basename, $artifactPath, $idx + 1, $dryRun);

                        if (!$dryRun && $expected !== '' && $expected !== 'Ambiguous') {
                            $detectionTotal++;

                            if ($expected === 'None' && $detected === '') {
                                $detectionHits++;
                            } elseif ($expected !== 'None' && $detected === $expected) {
                                $detectionHits++;
                            }
                        }
                    }

                    if ($dryRun) {
                        break;
                    }

                    $totalCost += $cost;
                    $totalTime += $elapsed;

                    if ($approved) {
                        $passes++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
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
            ['Cialdini detection accuracy' => sprintf('%d / %d (%.0f%%)', $detectionHits, $detectionTotal, $detectionTotal > 0 ? 100 * $detectionHits / $detectionTotal : 0)],
            ['Total time' => sprintf('%.1fs', $totalTime)],
            ['Total cost' => sprintf('$%.4f', $totalCost)],
            ['Output dir' => $outputDir],
        );

        return $errors === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return array{0: float, 1: float, 2: bool, 3: string, 4: string} [cost, elapsed, approved, detectedLever, expectedLever]
     */
    private function runSingleTurnFixture(SymfonyStyle $io, string $file, string $basename, string $artifactPath, int $idx, bool $dryRun): array
    {
        [$lever, $language] = $this->parseFixtureFilename($basename);
        $body = $this->extractBody($file);
        $scamCode = self::DEFAULT_SCAM_PER_LEVER[$lever] ?? 'UNKNOWN';
        /** @var string $persona */
        $persona = self::PERSONA_PER_SCAM[$scamCode];

        $expected = $this->normalizeExpectedLever($lever);
        $io->text(sprintf('expected_lever=%s  scam_code=%s  lang=%s  persona=%s', $lever, $scamCode, $language, $persona));

        if ($dryRun) {
            $io->text('(dry-run, no LLM call)');

            return [0.0, 0.0, false, '', $expected];
        }

        $context = $this->buildContext($scamCode, $language, $body, $basename);

        $started = microtime(true);
        $orchResult = $this->orchestrator->generate($context, $persona);
        $elapsed = microtime(true) - $started;

        $cost = is_numeric($orchResult['cost_estimate'] ?? null) ? (float) $orchResult['cost_estimate'] : 0.0;

        // The ConversationAnalyzer cache (in-memory, keyed by conv_id + msg
        // count) should hit here — same conv as just used by PromptBuilder.
        // Call it explicitly so we capture the strategic_suggestions array
        // for the artifact. Same instance, so cache hits = no extra LLM cost.
        /** @var array<array{direction: string, body_text: string, ts_msg: string}> $lastMsgs */
        $lastMsgs = $context['last_messages'];
        $analysisContext = [
            'conversation_id' => is_string($context['conv_id'] ?? null) ? $context['conv_id'] : '',
            'scam_type' => $scamCode,
            'persona_code' => $persona,
            'all_messages' => $lastMsgs,
            'extracted_iocs' => [],
        ];
        $analysis = $this->analyzer->analyzeAndGenerateInstructions($analysisContext);

        /** @var list<string> $suggestions */
        $suggestions = $analysis['strategic_suggestions'];
        $mirrorEntry = $this->extractCialdiniMirror($suggestions);
        $detected = $mirrorEntry !== null ? $this->extractLeverFromMirror($mirrorEntry) : '';

        $this->dumpSingleTurnArtifact($artifactPath, [
            'index' => $idx,
            'fixture' => $file,
            'basename' => $basename,
            'expected_lever' => $expected,
            'detected_lever' => $detected,
            'scam_code' => $scamCode,
            'language' => $language,
            'persona' => $persona,
            'inbound_body' => $body,
            'elapsed' => $elapsed,
            'cost_usd' => $cost,
            'orch_result' => $orchResult,
            'analyzer_suggestions' => $suggestions,
            'mirror_entry' => $mirrorEntry,
        ]);

        $approved = (bool) ($orchResult['approved'] ?? false);
        $detectMark = $detected === '' ? '(no mirror)' : "(detected: {$detected})";
        $io->text(sprintf('  → %s  (%.1fs, $%.4f)  %s  →  %s', $approved ? 'APPROVED' : 'REJECTED', $elapsed, $cost, $detectMark, basename($artifactPath)));

        return [$cost, $elapsed, $approved, $detected, $expected];
    }

    /**
     * @return array{0: float, 1: float, 2: bool}
     */
    private function runMultiTurnFixture(SymfonyStyle $io, string $file, string $basename, string $artifactPath, int $idx, bool $dryRun): array
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
        $persona = is_string($script['persona'] ?? null) ? $script['persona'] : (self::PERSONA_PER_SCAM[$scamCode] ?? 'generic_user');
        $scenario = is_string($script['scenario'] ?? null) ? $script['scenario'] : '(no scenario)';
        /** @var list<array<string, mixed>> $turns */
        $turns = is_array($script['turns'] ?? null) ? $script['turns'] : [];

        $io->text(sprintf('multi-turn  scam_code=%s  lang=%s  persona=%s  turns=%d', $scamCode, $language, $persona, count($turns)));
        $io->text(sprintf('scenario: %s', $scenario));

        if ($dryRun) {
            $io->text('(dry-run)');

            return [0.0, 0.0, false];
        }

        $ts = strtotime('2026-06-27 09:00:00 UTC');
        $messages = [];
        $genRuns = [];
        $totalCost = 0.0;
        $totalElapsed = 0.0;
        $allApproved = true;

        foreach ($turns as $turnIdx => $turn) {
            $from = is_string($turn['from'] ?? null) ? $turn['from'] : 'attacker';
            $dir = $from === 'persona' ? 'out' : 'in';
            $shouldGenerate = $from === 'persona' && (bool) ($turn['generate'] ?? false);

            if ($shouldGenerate) {
                $convId = bin2hex(random_bytes(8));
                $context = [
                    'conv_id' => $convId,
                    'scam_type' => ['code' => $scamCode, 'label' => $scamCode, 'label_fr' => $scamCode],
                    'persona' => $persona,
                    'detected_language' => $language,
                    'last_messages' => $messages,
                    'extracted_iocs' => [],
                    'sender_history_summary' => null,
                    'policy_min_words' => 50,
                    'policy_max_words' => 150,
                ];

                $started = microtime(true);
                $orchResult = $this->orchestrator->generate($context, $persona);
                $elapsed = microtime(true) - $started;
                $cost = is_numeric($orchResult['cost_estimate'] ?? null) ? (float) $orchResult['cost_estimate'] : 0.0;
                $totalCost += $cost;
                $totalElapsed += $elapsed;

                $approved = (bool) ($orchResult['approved'] ?? false);
                $generatedText = is_scalar($orchResult['text'] ?? null) ? (string) $orchResult['text'] : '';

                if (!$approved) {
                    $allApproved = false;
                }

                // Capture analyzer output for this turn (cache hit on the
                // ConversationAnalyzer since same conv_id was just used).
                $analysisContext = [
                    'conversation_id' => $convId,
                    'scam_type' => $scamCode,
                    'persona_code' => $persona,
                    'all_messages' => $messages,
                    'extracted_iocs' => [],
                ];
                $analysis = $this->analyzer->analyzeAndGenerateInstructions($analysisContext);
                /** @var list<string> $suggestions */
                $suggestions = $analysis['strategic_suggestions'];
                $mirrorEntry = $this->extractCialdiniMirror($suggestions);
                $detected = $mirrorEntry !== null ? $this->extractLeverFromMirror($mirrorEntry) : '';

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
                    'attempts' => $orchResult['attempts'] ?? '?',
                    'detected_lever' => $detected,
                    'mirror_entry' => $mirrorEntry,
                    'suggestions' => $suggestions,
                ];

                $detectMark = $detected === '' ? '(no mirror)' : "(detected: {$detected})";
                $io->text(sprintf('  turn %d (generate)  → %s  %s  (%.1fs, $%.4f)', $turnIdx + 1, $approved ? 'APPROVED' : 'REJECTED', $detectMark, $elapsed, $cost));
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
            'language' => $language,
            'persona' => $persona,
            'scenario' => $scenario,
            'messages' => $messages,
            'gen_runs' => $genRuns,
            'total_elapsed' => $totalElapsed,
            'total_cost' => $totalCost,
        ]);

        $io->text(sprintf('  → multi-turn done  (%.1fs, $%.4f, %d generations)  →  %s', $totalElapsed, $totalCost, count($genRuns), basename($artifactPath)));

        return [$totalCost, $totalElapsed, $allApproved];
    }

    /**
     * @return array{0: string, 1: string} [lever, language]
     */
    private function parseFixtureFilename(string $basename): array
    {
        if (!preg_match('/^\d+_(.+)_([A-Z]{2})$/', $basename, $m)) {
            throw new \RuntimeException("Cannot parse fixture filename: {$basename}. Expected NN_LEVER_context_LANG.");
        }

        $middle = $m[1];
        $lang = strtolower($m[2]);

        $codes = self::KNOWN_LEVERS;
        usort($codes, fn ($a, $b) => strlen($b) - strlen($a));

        foreach ($codes as $code) {
            if (str_starts_with($middle, $code . '_') || $middle === $code) {
                return [$code, $lang];
            }
        }

        throw new \RuntimeException("Cannot identify lever in fixture: {$basename}. Middle='{$middle}'. Update KNOWN_LEVERS if a new lever is added.");
    }

    private function extractBody(string $emlPath): string
    {
        $raw = file_get_contents($emlPath);

        if ($raw === false) {
            throw new \RuntimeException("Cannot read fixture: {$emlPath}");
        }
        $parts = preg_split('/\r?\n\r?\n/', $raw, 2);

        return trim($parts[1] ?? $raw);
    }

    /** Resolve filename lever → expected single-lever value (or "Ambiguous"). */
    private function normalizeExpectedLever(string $lever): string
    {
        $aliases = [
            'AmbiguousAuthUrg' => 'Ambiguous',
            'AmbiguousAuthRec' => 'Ambiguous',
            'CrossROMANCE' => 'Secrecy',
            'CrossCHARITY' => 'Urgency',
            'CrossOOD' => 'Authority',
            'PrecAuthBot' => 'Authority',  // expected = Authority but RULE #1 should skip mirror
            'PrecAuthAggr' => 'Authority', // expected = Authority but RULE #2 should skip mirror
            'PrecAuthDefer' => 'Authority',// expected = Authority but RULE #6 may suppress
        ];

        return $aliases[$lever] ?? $lever;
    }

    /**
     * @param list<string> $suggestions
     */
    private function extractCialdiniMirror(array $suggestions): ?string
    {
        foreach ($suggestions as $sugg) {
            if (str_starts_with($sugg, 'CIALDINI-MIRROR (')) {
                return $sugg;
            }
        }

        return null;
    }

    private function extractLeverFromMirror(string $entry): string
    {
        if (preg_match('/^CIALDINI-MIRROR \(([A-Za-z]+)\)/', $entry, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(string $scamCode, string $language, string $body, string $basename): array
    {
        $convId = bin2hex(random_bytes(8));

        $messages = [];

        // 6 stub turns to push detectStage into payment_push (same trick
        // as spec 118 smoke). Stubs are deliberately vague so they do NOT
        // taint the Cialdini-lever detection on the final inbound.
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

        $messages[] = [
            'direction' => 'in',
            'headers' => ['from' => 'scammer@evil.test', 'subject' => "Re: {$basename}"],
            'body_text' => $body,
            'ts_msg' => date('c', $ts + 6 * 600),
        ];

        return [
            'conv_id' => $convId,
            'scam_type' => ['code' => $scamCode, 'label' => $scamCode, 'label_fr' => $scamCode],
            'persona' => self::PERSONA_PER_SCAM[$scamCode] ?? 'generic_user',
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
     *     index: int, fixture: string, basename: string,
     *     expected_lever: string, detected_lever: string,
     *     scam_code: string, language: string, persona: string,
     *     inbound_body: string, elapsed: float, cost_usd: float,
     *     orch_result: array<string, mixed>,
     *     analyzer_suggestions: list<mixed>,
     *     mirror_entry: ?string
     * } $data
     */
    private function dumpSingleTurnArtifact(string $path, array $data): void
    {
        $orch = $data['orch_result'];
        $outText = is_scalar($orch['text'] ?? null) ? (string) $orch['text'] : '';
        $scores = is_array($orch['validation_scores'] ?? null) ? $orch['validation_scores'] : [];

        $match = '?';

        if ($data['expected_lever'] === 'None') {
            $match = $data['detected_lever'] === '' ? 'PASS (no mirror as expected)' : "FAIL (expected None, detected {$data['detected_lever']})";
        } elseif ($data['expected_lever'] === 'Ambiguous') {
            $match = $data['detected_lever'] !== '' ? "PASS (Ambiguous → detected {$data['detected_lever']})" : 'FAIL (Ambiguous → no mirror)';
        } else {
            $match = $data['detected_lever'] === $data['expected_lever'] ? 'PASS (match)' : "MISMATCH (expected={$data['expected_lever']}, detected={$data['detected_lever']})";
        }

        $md = "# Smoke {$data['index']} — {$data['basename']}\n\n"
            . "**Fixture**: {$data['fixture']}\n"
            . "**Expected lever**: {$data['expected_lever']}\n"
            . '**Detected lever**: ' . ($data['detected_lever'] !== '' ? $data['detected_lever'] : '(none)') . "\n"
            . "**Detection match**: {$match}\n"
            . "**Scam code (context)**: {$data['scam_code']}\n"
            . "**Language**: {$data['language']}\n"
            . "**Persona**: {$data['persona']}\n"
            . '**Generation time**: ' . number_format($data['elapsed'], 2) . "s\n"
            . '**Cost estimate**: $' . number_format($data['cost_usd'], 5) . "\n"
            . '**Approved**: ' . (($orch['approved'] ?? false) ? 'yes' : 'no') . "\n"
            . '**Attempts**: ' . (is_scalar($orch['attempts'] ?? null) ? (string) $orch['attempts'] : '?') . "\n\n"
            . "## Inbound\n\n```\n" . $data['inbound_body'] . "\n```\n\n"
            . "## Mirror entry (extracted from strategic_suggestions)\n\n";

        if ($data['mirror_entry'] !== null) {
            $md .= "```\n" . $data['mirror_entry'] . "\n```\n\n";
        } else {
            $md .= "(no CIALDINI-MIRROR entry detected)\n\n";
        }

        $md .= "## All strategic_suggestions from analyzer\n\n";

        if ($data['analyzer_suggestions'] !== []) {
            foreach ($data['analyzer_suggestions'] as $sugg) {
                $md .= '- ' . (is_scalar($sugg) ? (string) $sugg : '(non-scalar)') . "\n";
            }
            $md .= "\n";
        } else {
            $md .= "(empty)\n\n";
        }

        $md .= "## Generated OUT (persona reply)\n\n```\n" . ($outText !== '' ? $outText : '(empty)') . "\n```\n\n"
            . "## Word count (OUT)\n\n" . str_word_count($outText) . " words\n\n";

        if ($scores !== []) {
            $md .= "## Validator scores\n\n"
                . '- naturalness: ' . (is_scalar($scores['naturalness'] ?? null) ? (string) $scores['naturalness'] : 'n/a') . "\n"
                . '- persona_fit: ' . (is_scalar($scores['persona_fit'] ?? null) ? (string) $scores['persona_fit'] : 'n/a') . "\n"
                . '- ti_value: ' . (is_scalar($scores['ti_value'] ?? null) ? (string) $scores['ti_value'] : 'n/a') . "\n"
                . '- security_pass: ' . (($scores['security_pass'] ?? false) ? 'yes' : 'no') . "\n\n";
        }

        $md .= "## Verdict (filled during smoke-test.md review)\n\n"
            . "- (a) lever detection correct: ?\n"
            . "- (b) mirror visible in OUT: ?\n"
            . "- (c) humanness: ?\n"
            . "- (d) language fidelity: ?\n"
            . "- (e) no regression (specs 112/116/117/122): ?\n\n"
            . "**Overall verdict**: ?\n";

        file_put_contents($path, $md);
    }

    /**
     * @param array{
     *     index: int, fixture: string, basename: string, scam_code: string,
     *     language: string, persona: string, scenario: string,
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

        $md .= "## Per-generation Cialdini detection\n\n";

        foreach ($data['gen_runs'] as $gen) {
            $turnNo = is_scalar($gen['turn'] ?? null) ? (string) $gen['turn'] : '?';
            $detected = is_string($gen['detected_lever'] ?? null) ? $gen['detected_lever'] : '';
            $mirror = is_string($gen['mirror_entry'] ?? null) ? $gen['mirror_entry'] : null;

            $md .= "### Generated turn {$turnNo}\n\n"
                . '- approved: ' . ((bool) ($gen['approved'] ?? false) ? 'yes' : 'no') . "\n"
                . '- attempts: ' . (is_scalar($gen['attempts'] ?? null) ? (string) $gen['attempts'] : '?') . "\n"
                . '- elapsed: ' . number_format(is_numeric($gen['elapsed'] ?? null) ? (float) $gen['elapsed'] : 0.0, 2) . "s\n"
                . '- cost: $' . number_format(is_numeric($gen['cost'] ?? null) ? (float) $gen['cost'] : 0.0, 5) . "\n"
                . '- detected lever: ' . ($detected !== '' ? $detected : '(none)') . "\n";

            if ($mirror !== null) {
                $md .= '- mirror entry: `' . $mirror . "`\n";
            }
            $md .= "\n";
        }

        $md .= "## Verdict (filled during smoke-test.md review)\n\n"
            . "- Lever detection per turn (correct?): ?\n"
            . "- Mirror visible in OUT (per turn): ?\n"
            . "- Humanness across turns: ?\n"
            . "- Language fidelity: ?\n"
            . "- Spec 112 / 116 / 122 invariants: ?\n\n"
            . "**Overall verdict**: ?\n";

        file_put_contents($path, $md);
    }
}

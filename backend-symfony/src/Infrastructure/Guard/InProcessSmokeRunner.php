<?php

declare(strict_types=1);

namespace App\Infrastructure\Guard;

use App\Application\Guard\CanarySmokeRunnerInterface;
use App\UI\Console\ReplyObjectiveSmokeCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Runs the reply-objective smoke command IN-PROCESS (same PHP process, not a subshell) and reads
 * back its summary JSON. Same process ⇒ the command's shared reply pipeline resolves prompts
 * through the same container — so a candidate held in EphemeralPromptOverride is seen — and the
 * run is byte-for-byte the same code that produced the frozen baseline (no drift, no extraction).
 */
final readonly class InProcessSmokeRunner implements CanarySmokeRunnerInterface
{
    public function __construct(
        private ReplyObjectiveSmokeCommand $smoke,
        private string $fixturesDir,
        private string $outputDir,
    ) {
    }

    public function run(): array
    {
        // A UNIQUE per-invocation summary path: a leftover summary from a previous job can never
        // be mistaken for this run's output (e.g. if the smoke writes nothing because the
        // fixtures dir is empty/missing, is_file() below is false and we fail loudly instead of
        // silently scoring stale evidence). Removed in the finally so nothing accumulates.
        $summaryPath = rtrim($this->outputDir, '/') . '/summary-' . bin2hex(random_bytes(8)) . '.json';

        try {
            $this->smoke->run(new ArrayInput([
                '--fixtures-dir' => $this->fixturesDir,
                '--output-dir' => $this->outputDir,
                '--summary-json' => $summaryPath,
            ]), new NullOutput());

            if (!is_file($summaryPath)) {
                throw new \RuntimeException("Smoke produced no summary at {$summaryPath} — the run wrote nothing (check the fixtures dir).");
            }

            $raw = file_get_contents($summaryPath);

            if ($raw === false) {
                throw new \RuntimeException("Cannot read smoke summary at {$summaryPath}.");
            }

            $summary = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

            if (!is_array($summary)) {
                throw new \RuntimeException("Smoke summary at {$summaryPath} is not a JSON object.");
            }

            /** @var array<string, mixed> $summary */
            return $summary;
        } finally {
            @unlink($summaryPath);
        }
    }
}

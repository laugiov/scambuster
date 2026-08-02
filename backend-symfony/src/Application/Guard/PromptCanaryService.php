<?php

declare(strict_types=1);

namespace App\Application\Guard;

/**
 * GUARD shared core: turn a raw smoke summary + a frozen baseline into a single verdict.
 *
 * This is the one place that defines the verdict shape both façades present — the CLI
 * merge-gate ({@see \App\UI\Console} `guard:check`) and the operator "Validate this prompt"
 * API. Neither the aggregation rules nor the tolerance live here; this only wires
 * {@see CanaryAggregate} (score the candidate) to {@see CanaryBaselineComparator} (diff it
 * against the baseline). It performs no I/O and calls no LLM, so it is fully deterministic
 * and unit-testable — the callers own the smoke run and loading the baseline file.
 */
final readonly class PromptCanaryService
{
    public function __construct(
        private CanaryAggregate $aggregate,
        private CanaryBaselineComparator $comparator,
    ) {
    }

    /**
     * @param array<string, mixed> $summary  a smoke summary (scambuster:smoke:reply-objective --summary-json)
     * @param array<string, mixed> $baseline the frozen baseline (guard-baseline.json)
     *
     * @return array{ok: bool, fingerprint_ok: bool, regressions: list<array{signal: string, baseline: float, candidate: float, delta: float, reason: string}>, candidate: array<string, mixed>}
     */
    public function evaluate(array $summary, array $baseline): array
    {
        $candidate = $this->aggregate->build($summary);
        $verdict = $this->comparator->compare($baseline, $candidate);

        return [
            'ok' => $verdict['ok'],
            'fingerprint_ok' => $verdict['fingerprint_ok'],
            'regressions' => $verdict['regressions'],
            'candidate' => $candidate,
        ];
    }
}

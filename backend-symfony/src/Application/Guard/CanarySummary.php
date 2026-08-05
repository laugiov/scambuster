<?php

declare(strict_types=1);

namespace App\Application\Guard;

/**
 * Machine-readable aggregate of a canary smoke run: per-fixture and overall
 * approved/fallback/attempts rates plus cost, averaged across N runs. This is the raw
 * summary of a single run; the frozen baseline (GUARD-a) keeps only the stable aggregates
 * (rates/averages), and the safety oracle (GUARD) consumes the captured out-texts.
 *
 * Pure/offline: no LLM, no I/O — it only accumulates outcomes and serializes them, so it is
 * fully unit-testable independently of the real-LLM smoke command that feeds it.
 */
final class CanarySummary
{
    /**
     * @var array<string, list<array{approved: bool, attempts: int, fallback_used: bool, cost: float, out_text: string|null}>>
     */
    private array $byFixture = [];

    /** @var array<string, string> fixture => expected language (for the safety oracle) */
    private array $language = [];

    private int $errors = 0;

    public function record(string $fixture, bool $approved, int $attempts, bool $fallbackUsed, float $cost, ?string $outText = null, string $expectedLanguage = 'en'): void
    {
        $this->byFixture[$fixture][] = [
            'approved' => $approved,
            'attempts' => $attempts,
            'fallback_used' => $fallbackUsed,
            'cost' => $cost,
            // Normalize empty text to null so out_texts is consistent across single-turn
            // and multi-turn fixtures (the safety oracle only cares about real output).
            'out_text' => ($outText === '' ? null : $outText),
        ];
        $this->language[$fixture] = $expectedLanguage;
    }

    /**
     * A fixture/run that threw before producing a result. Surfaced in the aggregate so a
     * consumer (baseline / comparator) can tell a real behaviour drift from "a fixture
     * started erroring" rather than reading rates over successes only.
     */
    public function recordError(): void
    {
        ++$this->errors;
    }

    /**
     * @return array{fixtures: array<string, array{runs: int, approved_rate: float, fallback_rate: float, attempts_avg: float, cost_avg: float, language: string, out_texts: list<string>}>, aggregate: array{fixtures_count: int, total_runs: int, errors: int, approved_rate: float, fallback_rate: float, attempts_avg: float, total_cost: float}}
     */
    public function toArray(): array
    {
        $fixtures = [];
        $allApproved = 0;
        $allFallback = 0;
        $allAttempts = 0;
        $allRuns = 0;
        $totalCost = 0.0;

        foreach ($this->byFixture as $name => $runs) {
            $n = count($runs);
            $approved = count(array_filter($runs, static fn (array $r): bool => $r['approved']));
            $fallback = count(array_filter($runs, static fn (array $r): bool => $r['fallback_used']));
            $attempts = array_sum(array_column($runs, 'attempts'));
            $cost = array_sum(array_column($runs, 'cost'));
            $outTexts = array_values(array_filter(
                array_column($runs, 'out_text'),
                static fn (?string $t): bool => $t !== null,
            ));

            $fixtures[$name] = [
                'runs' => $n,
                // Cast to float: PHP's "/" returns int on even division (1/1 === 1), which
                // would make the JSON type value-dependent; keep every rate a float.
                'approved_rate' => (float) ($approved / $n),
                'fallback_rate' => (float) ($fallback / $n),
                'attempts_avg' => (float) ($attempts / $n),
                'cost_avg' => (float) ($cost / $n),
                'language' => $this->language[$name] ?? 'en',
                'out_texts' => $outTexts,
            ];

            $allApproved += $approved;
            $allFallback += $fallback;
            $allAttempts += $attempts;
            $allRuns += $n;
            $totalCost += $cost;
        }

        return [
            'fixtures' => $fixtures,
            'aggregate' => [
                'fixtures_count' => count($this->byFixture),
                'total_runs' => $allRuns,
                'errors' => $this->errors,
                'approved_rate' => $allRuns > 0 ? (float) ($allApproved / $allRuns) : 0.0,
                'fallback_rate' => $allRuns > 0 ? (float) ($allFallback / $allRuns) : 0.0,
                'attempts_avg' => $allRuns > 0 ? (float) ($allAttempts / $allRuns) : 0.0,
                'total_cost' => (float) $totalCost,
            ],
        ];
    }

    public function toJson(): string
    {
        // JSON_PRESERVE_ZERO_FRACTION keeps whole-number rates as floats (1.0, not 1) so the
        // frozen baseline and the comparator see a stable numeric type regardless of value.
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . "\n";
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Guard;

/**
 * Turns a raw {@see CanarySummary} into the comparable canary aggregate: the stable
 * behaviour metrics (approved/fallback/attempts) plus per-invariant safety-violation rates
 * computed by running the {@see SafetyInvariantOracle} over every captured OUT text (with
 * its fixture's expected language). This is the shape both the frozen baseline (GUARD-a) and
 * the candidate-vs-baseline comparator (GUARD-b) consume — so it lives in one place.
 *
 * Deterministic and offline: given the same summary it always yields the same aggregate.
 * Volatile out-texts are dropped; only rates survive, so the frozen baseline is stable.
 */
final readonly class CanaryAggregate
{
    public function __construct(
        private SafetyInvariantOracle $oracle,
    ) {
    }

    /**
     * @param array<string, mixed> $summary CanarySummary::toArray() output (possibly json-decoded)
     *
     * @return array{metrics: array{approved_rate: float, fallback_rate: float, attempts_avg: float}, violation_rates: array<string, float>, meta: array{recording_slots: int, runs: int, errors: int, out_texts_scored: int, oracle_fingerprint: string}}
     */
    public function build(array $summary): array
    {
        $counts = [];
        $scored = 0;

        $fixtures = is_array($summary['fixtures'] ?? null) ? $summary['fixtures'] : [];

        foreach ($fixtures as $fixture) {
            if (!is_array($fixture)) {
                continue;
            }

            $language = is_string($fixture['language'] ?? null) ? $fixture['language'] : 'en';
            $outTexts = is_array($fixture['out_texts'] ?? null) ? $fixture['out_texts'] : [];

            foreach ($outTexts as $text) {
                if (!is_string($text)) {
                    continue;
                }

                ++$scored;

                foreach ($this->oracle->violations($text, $language) as $code) {
                    $counts[$code] = ($counts[$code] ?? 0) + 1;
                }
            }
        }

        $rates = [];

        foreach (SafetyInvariantOracle::ALL_CODES as $code) {
            $rates[$code] = $scored > 0 ? (float) (($counts[$code] ?? 0) / $scored) : 0.0;
        }

        $agg = is_array($summary['aggregate'] ?? null) ? $summary['aggregate'] : [];

        return [
            'metrics' => [
                'approved_rate' => $this->floatOf($agg['approved_rate'] ?? null),
                'fallback_rate' => $this->floatOf($agg['fallback_rate'] ?? null),
                'attempts_avg' => $this->floatOf($agg['attempts_avg'] ?? null),
            ],
            'violation_rates' => $rates,
            'meta' => [
                // recording_slots counts summary keys (multi-turn fixtures record one key per
                // generate-turn), so it can exceed the number of fixture files. out_texts_scored
                // is the true denominator for the violation rates.
                'recording_slots' => $this->intOf($agg['fixtures_count'] ?? null),
                'runs' => $this->intOf($agg['total_runs'] ?? null),
                'errors' => $this->intOf($agg['errors'] ?? null),
                'out_texts_scored' => $scored,
                'oracle_fingerprint' => SafetyInvariantOracle::fingerprint(),
            ],
        ];
    }

    private function floatOf(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}

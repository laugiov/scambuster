<?php

declare(strict_types=1);

namespace App\Application\Evaluation\Metric;

/**
 * Result of a single quality metric computation.
 *
 * Encapsulates measured value, target threshold, comparison direction,
 * and pass/fail verdict for one metric in the evaluation suite.
 */
final readonly class MetricResult
{
    public string $verdict;

    /**
     * @param string $name            Metric identifier (e.g., "non_repetitiveness")
     * @param string $dimension       Quality dimension (e.g., "diversity")
     * @param float  $measuredValue   Computed metric value
     * @param float  $targetThreshold Expected threshold
     * @param string $comparison      "lt" (less than) or "gt" (greater than)
     * @param int    $sampleSize      Number of samples used
     * @param string $details         Human-readable explanation
     * @param int    $minSampleSize   Minimum samples required for valid verdict
     */
    public function __construct(
        public string $name,
        public string $dimension,
        public float $measuredValue,
        public float $targetThreshold,
        public string $comparison,
        public int $sampleSize,
        public string $details,
        public int $minSampleSize = 10,
    ) {
        $this->verdict = $this->computeVerdict();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'dimension' => $this->dimension,
            'measured_value' => $this->measuredValue,
            'target_threshold' => $this->targetThreshold,
            'comparison' => $this->comparison,
            'verdict' => $this->verdict,
            'sample_size' => $this->sampleSize,
            'details' => $this->details,
        ];
    }

    private function computeVerdict(): string
    {
        if ($this->sampleSize < $this->minSampleSize) {
            return 'INSUFFICIENT_DATA';
        }

        if ($this->comparison === 'lt') {
            return $this->measuredValue < $this->targetThreshold ? 'PASS' : 'FAIL';
        }

        return $this->measuredValue > $this->targetThreshold ? 'PASS' : 'FAIL';
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Evaluation\Metric;

/**
 * Contract for evaluation metrics that compute quality scores from a corpus.
 */
interface MetricInterface
{
    /**
     * Compute a metric from the evaluation corpus.
     *
     * @param array<int, array<string, mixed>> $corpus
     */
    public function compute(array $corpus): MetricResult;
}

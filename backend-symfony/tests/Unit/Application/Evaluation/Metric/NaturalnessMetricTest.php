<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation\Metric;

use App\Application\Evaluation\Metric\NaturalnessMetric;
use PHPUnit\Framework\TestCase;

final class NaturalnessMetricTest extends TestCase
{
    private NaturalnessMetric $metric; // @phpstan-ignore-line

    protected function setUp(): void
    {
        $this->metric = new NaturalnessMetric();
    }

    public function test_all_first_attempt_approvals(): void
    {
        $corpus = [];

        for ($i = 0; $i < 10; ++$i) {
            $corpus[] = ['text' => 'Reply ' . $i, 'attempts' => 1];
        }

        $result = $this->metric->compute($corpus);

        $this->assertSame(1.0, $result->measuredValue);
        $this->assertSame('PASS', $result->verdict);
    }

    public function test_mixed_attempts(): void
    {
        $corpus = [];

        for ($i = 0; $i < 10; ++$i) {
            $corpus[] = ['text' => 'Reply ' . $i, 'attempts' => $i % 2 === 0 ? 1 : 2];
        }

        $result = $this->metric->compute($corpus);

        $this->assertSame(0.5, $result->measuredValue);
        $this->assertSame('FAIL', $result->verdict);
        $this->assertSame(10, $result->sampleSize);
    }

    public function test_fallbacks_excluded_from_attempt_rate(): void
    {
        $corpus = [
            ['text' => 'Reply 1', 'attempts' => 1],
            ['text' => 'Fallback', 'attempts' => 3, 'fallback_used' => true],
        ];

        $result = $this->metric->compute($corpus);

        $this->assertSame(1, $result->sampleSize);
        $this->assertSame(1.0, $result->measuredValue);
    }

    public function test_avg_naturalness_score(): void
    {
        $corpus = [];

        for ($i = 0; $i < 12; ++$i) {
            $corpus[] = ['naturalness' => ($i % 3) + 3]; // 3, 4, 5 repeating
        }

        $result = $this->metric->computeAvgScore($corpus);

        $this->assertSame('avg_naturalness_score', $result->name);
        $this->assertSame(4.0, $result->measuredValue);
        $this->assertSame('PASS', $result->verdict);
    }

    public function test_avg_naturalness_below_threshold(): void
    {
        $corpus = [
            ['naturalness' => 2],
            ['naturalness' => 2],
            ['naturalness' => 1],
            ['naturalness' => 2],
            ['naturalness' => 3],
            ['naturalness' => 2],
            ['naturalness' => 1],
            ['naturalness' => 2],
            ['naturalness' => 3],
            ['naturalness' => 2],
        ];

        $result = $this->metric->computeAvgScore($corpus);

        $this->assertLessThan(3.0, $result->measuredValue);
        $this->assertSame('FAIL', $result->verdict);
    }

    public function test_zero_naturalness_excluded(): void
    {
        $corpus = [
            ['naturalness' => 0],
            ['naturalness' => 4],
        ];

        $result = $this->metric->computeAvgScore($corpus);

        $this->assertSame(1, $result->sampleSize);
        $this->assertSame(4.0, $result->measuredValue);
    }
}

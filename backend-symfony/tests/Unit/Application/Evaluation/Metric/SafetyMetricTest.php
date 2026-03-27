<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation\Metric;

use App\Application\Evaluation\Metric\SafetyMetric;
use PHPUnit\Framework\TestCase;

final class SafetyMetricTest extends TestCase
{
    private SafetyMetric $metric; // @phpstan-ignore-line

    protected function setUp(): void
    {
        $this->metric = new SafetyMetric();
    }

    public function test_all_secure(): void
    {
        $corpus = array_fill(0, 10, ['security_pass' => true]);

        $result = $this->metric->compute($corpus);

        $this->assertSame(1.0, $result->measuredValue);
        $this->assertSame('PASS', $result->verdict);
    }

    public function test_some_security_failures(): void
    {
        $corpus = array_merge(
            array_fill(0, 9, ['security_pass' => true]),
            [['security_pass' => false]],
        );

        $result = $this->metric->compute($corpus);

        $this->assertSame(0.9, $result->measuredValue);
        $this->assertSame('FAIL', $result->verdict);
    }

    public function test_no_fallbacks(): void
    {
        $corpus = array_fill(0, 10, ['fallback_used' => false]);

        $result = $this->metric->computeFallbackRate($corpus);

        $this->assertSame(0.0, $result->measuredValue);
        $this->assertSame('PASS', $result->verdict);
    }

    public function test_high_fallback_rate_fails(): void
    {
        $corpus = array_merge(
            array_fill(0, 5, ['fallback_used' => true]),
            array_fill(0, 5, ['fallback_used' => false]),
        );

        $result = $this->metric->computeFallbackRate($corpus);

        $this->assertSame(0.5, $result->measuredValue);
        $this->assertSame('FAIL', $result->verdict);
    }

    public function test_empty_corpus(): void
    {
        $result = $this->metric->compute([]);

        $this->assertSame(0, $result->sampleSize);
    }

    public function test_fallback_rate_comparison_is_lt(): void
    {
        $result = $this->metric->computeFallbackRate([['fallback_used' => false]]);

        $this->assertSame('lt', $result->comparison);
    }
}

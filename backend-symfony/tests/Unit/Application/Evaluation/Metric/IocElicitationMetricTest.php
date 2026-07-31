<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation\Metric;

use App\Application\Evaluation\Metric\IocElicitationMetric;
use PHPUnit\Framework\TestCase;

final class IocElicitationMetricTest extends TestCase
{
    private IocElicitationMetric $metric; // @phpstan-ignore-line

    protected function setUp(): void
    {
        $this->metric = new IocElicitationMetric();
    }

    public function test_high_ti_value_passes(): void
    {
        $corpus = [
            ['ti_value' => 4],
            ['ti_value' => 3],
            ['ti_value' => 5],
            ['ti_value' => 3],
            ['ti_value' => 4],
            ['ti_value' => 3],
            ['ti_value' => 4],
            ['ti_value' => 3],
            ['ti_value' => 5],
            ['ti_value' => 4],
        ];

        $result = $this->metric->compute($corpus);

        $this->assertGreaterThan(2.5, $result->measuredValue);
        $this->assertSame('PASS', $result->verdict);
    }

    public function test_low_ti_value_fails(): void
    {
        $corpus = [
            ['ti_value' => 1],
            ['ti_value' => 2],
            ['ti_value' => 1],
            ['ti_value' => 1],
            ['ti_value' => 2],
            ['ti_value' => 1],
            ['ti_value' => 2],
            ['ti_value' => 1],
            ['ti_value' => 1],
            ['ti_value' => 2],
        ];

        $result = $this->metric->compute($corpus);

        $this->assertLessThan(2.5, $result->measuredValue);
        $this->assertSame('FAIL', $result->verdict);
    }

    public function test_zero_scores_excluded(): void
    {
        $corpus = [
            ['ti_value' => 0],
            ['ti_value' => 4],
        ];

        $result = $this->metric->compute($corpus);

        $this->assertSame(1, $result->sampleSize);
        $this->assertSame(4.0, $result->measuredValue);
    }

    public function test_empty_corpus(): void
    {
        $result = $this->metric->compute([]);

        $this->assertSame(0, $result->sampleSize);
    }

    public function test_metric_name_and_dimension(): void
    {
        $corpus = array_fill(0, 10, ['ti_value' => 3]);
        $result = $this->metric->compute($corpus);

        $this->assertSame('ioc_elicitation', $result->name);
        $this->assertSame('ioc', $result->dimension);
        $this->assertSame('gt', $result->comparison);
    }
}

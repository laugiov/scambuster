<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation\Metric;

use App\Application\Evaluation\Metric\MetricResult;
use PHPUnit\Framework\TestCase;

final class MetricResultTest extends TestCase
{
    public function test_pass_when_value_below_lt_threshold(): void
    {
        $result = new MetricResult('jaccard', 'diversity', 0.20, 0.30, 'lt', 100, 'Low overlap');

        $this->assertSame('PASS', $result->verdict);
    }

    public function test_fail_when_value_above_lt_threshold(): void
    {
        $result = new MetricResult('jaccard', 'diversity', 0.50, 0.30, 'lt', 100, 'High overlap');

        $this->assertSame('FAIL', $result->verdict);
    }

    public function test_pass_when_value_above_gt_threshold(): void
    {
        $result = new MetricResult('opening_diversity', 'diversity', 0.90, 0.80, 'gt', 100, 'Good variety');

        $this->assertSame('PASS', $result->verdict);
    }

    public function test_fail_when_value_below_gt_threshold(): void
    {
        $result = new MetricResult('opening_diversity', 'diversity', 0.50, 0.80, 'gt', 100, 'Poor variety');

        $this->assertSame('FAIL', $result->verdict);
    }

    public function test_insufficient_data_when_small_sample(): void
    {
        $result = new MetricResult('jaccard', 'diversity', 0.50, 0.30, 'lt', 5, 'Too few samples');

        $this->assertSame('INSUFFICIENT_DATA', $result->verdict);
    }

    public function test_to_array_returns_complete_structure(): void
    {
        $result = new MetricResult('test', 'dim', 1.5, 2.0, 'gt', 50, 'Details');
        $array = $result->toArray();

        $this->assertSame('test', $array['name']);
        $this->assertSame('dim', $array['dimension']);
        $this->assertSame(1.5, $array['measured_value']);
        $this->assertSame(2.0, $array['target_threshold']);
        $this->assertSame('gt', $array['comparison']);
        $this->assertSame('FAIL', $array['verdict']);
        $this->assertSame(50, $array['sample_size']);
        $this->assertSame('Details', $array['details']);
    }

    public function test_exact_threshold_fails_lt(): void
    {
        $result = new MetricResult('test', 'dim', 0.30, 0.30, 'lt', 20, '');

        $this->assertSame('FAIL', $result->verdict);
    }

    public function test_exact_threshold_fails_gt(): void
    {
        $result = new MetricResult('test', 'dim', 0.80, 0.80, 'gt', 20, '');

        $this->assertSame('FAIL', $result->verdict);
    }
}

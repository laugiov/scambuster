<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Validation;

use App\Domain\Validation\LeakageDetectionResult;
use PHPUnit\Framework\TestCase;

/**
 * Spec 065d — Phase 1 — Tests for the LeakageDetectionResult value object.
 */
final class LeakageDetectionResultTest extends TestCase
{
    public function test_it_carries_leak_detected_flag(): void
    {
        $result = new LeakageDetectionResult(true, 'mentions n8n', ['n8n']);
        $this->assertTrue($result->leakDetected);
    }

    public function test_it_carries_reason_when_leak(): void
    {
        $result = new LeakageDetectionResult(true, 'paraphrased orchestrator mention', []);
        $this->assertSame('paraphrased orchestrator mention', $result->reason);
    }

    public function test_it_provides_empty_signals_array_by_default(): void
    {
        $result = new LeakageDetectionResult(false);
        $this->assertSame([], $result->signals);
        $this->assertNull($result->reason);
    }

    public function test_no_leak_with_null_reason(): void
    {
        $result = new LeakageDetectionResult(false, null, []);
        $this->assertFalse($result->leakDetected);
        $this->assertNull($result->reason);
    }
}

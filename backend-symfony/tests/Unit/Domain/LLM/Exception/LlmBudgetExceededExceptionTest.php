<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\LLM\Exception;

use App\Domain\LLM\Exception\LlmBudgetExceededException;
use PHPUnit\Framework\TestCase;

/**
 * LLM cost guard
 *
 * Tests for the LlmBudgetExceededException value object.
 */
final class LlmBudgetExceededExceptionTest extends TestCase
{
    public function test_it_carries_current_usage_limit_and_reset(): void
    {
        $resetAt = new \DateTimeImmutable('2026-05-01 00:00:00', new \DateTimeZone('UTC'));
        $exception = new LlmBudgetExceededException(52.34, 50.0, $resetAt);

        $this->assertSame(52.34, $exception->currentUsdSpent);
        $this->assertSame(50.0, $exception->monthlyLimitUsd);
        $this->assertSame($resetAt, $exception->resetAt);
    }

    public function test_it_extends_runtime_exception(): void
    {
        $exception = new LlmBudgetExceededException(60.0, 50.0);

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function test_reset_at_defaults_to_first_of_next_month_at_midnight_utc(): void
    {
        // Freeze "now" via a known reference: we cannot easily freeze in PHP,
        // so we assert the default reset is in the future and is the 1st of
        // some month at 00:00:00 UTC.
        $exception = new LlmBudgetExceededException(60.0, 50.0);

        $this->assertGreaterThan(new \DateTimeImmutable(), $exception->resetAt);
        $this->assertSame('00:00:00', $exception->resetAt->format('H:i:s'));
        $this->assertSame('01', $exception->resetAt->format('d'));
        $this->assertSame('UTC', $exception->resetAt->getTimezone()->getName());
    }

    public function test_message_is_human_readable(): void
    {
        $exception = new LlmBudgetExceededException(52.34, 50.0);

        $this->assertStringContainsString('budget', strtolower($exception->getMessage()));
        $this->assertStringContainsString('50', $exception->getMessage());
    }
}

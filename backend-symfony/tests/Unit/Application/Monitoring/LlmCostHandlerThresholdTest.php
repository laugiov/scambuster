<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Monitoring;

use App\Application\Monitoring\LlmCostHandler;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Spec 065b — LLM cost guard
 *
 * Unit tests for `LlmCostHandler::isThresholdReached()` — the soft warning
 * threshold method that returns true when the current month spend has
 * crossed a configurable percentage of the monthly limit.
 */
final class LlmCostHandlerThresholdTest extends TestCase
{
    private function makeHandler(float $monthlySum, float $limit): LlmCostHandler
    {
        /** @var Connection&MockObject $connection */
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchOne')
            ->willReturn((string) $monthlySum);

        return new LlmCostHandler($connection, $limit);
    }

    public function test_threshold_not_reached_when_no_usage(): void
    {
        $handler = $this->makeHandler(0.0, 50.0);

        $this->assertFalse($handler->isThresholdReached(0.8));
    }

    public function test_threshold_reached_at_exactly_80_percent(): void
    {
        $handler = $this->makeHandler(40.0, 50.0); // 40 / 50 = 80%

        $this->assertTrue($handler->isThresholdReached(0.8));
    }

    public function test_threshold_reached_above_80_percent(): void
    {
        $handler = $this->makeHandler(45.0, 50.0); // 45 / 50 = 90%

        $this->assertTrue($handler->isThresholdReached(0.8));
    }

    public function test_threshold_not_reached_at_79_percent(): void
    {
        $handler = $this->makeHandler(39.0, 50.0); // 39 / 50 = 78%

        $this->assertFalse($handler->isThresholdReached(0.8));
    }

    public function test_threshold_disabled_when_limit_zero(): void
    {
        $handler = $this->makeHandler(1000.0, 0.0);

        $this->assertFalse($handler->isThresholdReached(0.8));
    }

    public function test_threshold_disabled_when_limit_negative(): void
    {
        $handler = $this->makeHandler(1000.0, -1.0);

        $this->assertFalse($handler->isThresholdReached(0.8));
    }

    public function test_custom_threshold_pct_works(): void
    {
        $handler = $this->makeHandler(25.0, 50.0); // 50%

        $this->assertTrue($handler->isThresholdReached(0.5));
        $this->assertFalse($handler->isThresholdReached(0.8));
    }
}

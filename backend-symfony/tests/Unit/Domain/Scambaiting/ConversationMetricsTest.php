<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Scambaiting;

use App\Domain\Scambaiting\ConversationMetrics;
use PHPUnit\Framework\TestCase;

final class ConversationMetricsTest extends TestCase
{
    public function testCalculateRewardWithMaxValues(): void
    {
        $metrics = new ConversationMetrics(
            durationSec: 86400,       // Max 24h (aligné avec specs)
            iocsTotal: 50,            // Max IOCs
            iocsSensibles: 10,        // Max sensibles
            isCompleted: true         // Completed
        );

        $this->assertEqualsWithDelta(1.0, $metrics->calculateReward(), 0.001);
    }

    public function testCalculateRewardWithMinValues(): void
    {
        $metrics = new ConversationMetrics(
            durationSec: 0,
            iocsTotal: 0,
            iocsSensibles: 0,
            isCompleted: false
        );

        $this->assertEquals(0.0, $metrics->calculateReward());
    }

    public function testCalculateRewardWithMediumValues(): void
    {
        $metrics = new ConversationMetrics(
            durationSec: 7200,        // 2 hours
            iocsTotal: 10,            // 20% of max
            iocsSensibles: 2,         // 20% of max
            isCompleted: true
        );

        $reward = $metrics->calculateReward();
        $this->assertGreaterThan(0.0, $reward);
        $this->assertLessThanOrEqual(1.0, $reward);
    }

    public function testThrowsExceptionWhenDurationIsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duration must be >= 0');

        new ConversationMetrics(
            durationSec: -100,
            iocsTotal: 5,
            iocsSensibles: 2,
            isCompleted: true
        );
    }

    public function testThrowsExceptionWhenIocsTotalIsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('IOCs total must be >= 0');

        new ConversationMetrics(
            durationSec: 3600,
            iocsTotal: -5,
            iocsSensibles: 0,
            isCompleted: true
        );
    }

    public function testThrowsExceptionWhenIocsSensiblesIsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('IOCs sensibles must be >= 0');

        new ConversationMetrics(
            durationSec: 3600,
            iocsTotal: 5,
            iocsSensibles: -2,
            isCompleted: true
        );
    }

    public function testThrowsExceptionWhenSensiblesExceedTotal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('IOCs sensibles (10) cannot exceed IOCs total (5)');

        new ConversationMetrics(
            durationSec: 3600,
            iocsTotal: 5,
            iocsSensibles: 10,  // 10 > 5 → INVALID
            isCompleted: true
        );
    }

    public function testGettersReturnCorrectValues(): void
    {
        $metrics = new ConversationMetrics(
            durationSec: 7200,
            iocsTotal: 15,
            iocsSensibles: 5,
            isCompleted: true
        );

        $this->assertEquals(7200, $metrics->getDurationSec());
        $this->assertEquals(15, $metrics->getIocsTotal());
        $this->assertEquals(5, $metrics->getIocsSensibles());
        $this->assertTrue($metrics->isCompleted());
    }

    public function testToStringReturnsFormattedString(): void
    {
        $metrics = new ConversationMetrics(
            durationSec: 3600,
            iocsTotal: 10,
            iocsSensibles: 3,
            isCompleted: true
        );

        $string = (string) $metrics;
        $this->assertStringContainsString('ConversationMetrics', $string);
        $this->assertStringContainsString('duration=3600s', $string);
        $this->assertStringContainsString('iocs=3/10', $string);
        $this->assertStringContainsString('completed=yes', $string);
    }

    public function testRewardFormulaWeights(): void
    {
        // Test avec seulement la durée au max
        $metricsOnlyDuration = new ConversationMetrics(
            durationSec: 86400,       // 24h (aligné avec specs)
            iocsTotal: 0,
            iocsSensibles: 0,
            isCompleted: false
        );
        $this->assertEqualsWithDelta(0.40, $metricsOnlyDuration->calculateReward(), 0.001);

        // Test avec seulement IOCs total au max
        $metricsOnlyIocs = new ConversationMetrics(
            durationSec: 0,
            iocsTotal: 50,
            iocsSensibles: 0,
            isCompleted: false
        );
        $this->assertEqualsWithDelta(0.25, $metricsOnlyIocs->calculateReward(), 0.001);

        // Test avec seulement IOCs sensibles au max (mais aussi iocsTotal=10 qui est 20% du max)
        $metricsOnlySensibles = new ConversationMetrics(
            durationSec: 0,
            iocsTotal: 10,
            iocsSensibles: 10,
            isCompleted: false
        );
        // iocsTotal=10/50=0.2 → 0.25*0.2=0.05, iocsSensibles=10/10=1.0 → 0.25*1.0=0.25 → total=0.30
        $this->assertEqualsWithDelta(0.30, $metricsOnlySensibles->calculateReward(), 0.001);

        // Test avec seulement completion
        $metricsOnlyCompletion = new ConversationMetrics(
            durationSec: 0,
            iocsTotal: 0,
            iocsSensibles: 0,
            isCompleted: true
        );
        $this->assertEqualsWithDelta(0.10, $metricsOnlyCompletion->calculateReward(), 0.001);
    }
}

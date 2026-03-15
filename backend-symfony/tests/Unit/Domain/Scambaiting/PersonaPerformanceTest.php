<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Scambaiting;

use App\Domain\Scambaiting\PersonaPerformance;
use PHPUnit\Framework\TestCase;

final class PersonaPerformanceTest extends TestCase
{
    public function testIsInColdStartWhenSessionsLessThanThree(): void
    {
        $perf = new PersonaPerformance('ELDERLY', 'PHISHING', 2, 0.5);
        $this->assertTrue($perf->isInColdStart());
    }

    public function testIsInColdStartWhenSessionsZero(): void
    {
        $perf = new PersonaPerformance('ELDERLY', 'PHISHING', 0, 0.0);
        $this->assertTrue($perf->isInColdStart());
    }

    public function testIsNotInColdStartWhenSessionsThreeOrMore(): void
    {
        $perf = new PersonaPerformance('ELDERLY', 'PHISHING', 3, 0.5);
        $this->assertFalse($perf->isInColdStart());
    }

    public function testIsNotInColdStartWhenSessionsGreaterThanThree(): void
    {
        $perf = new PersonaPerformance('ELDERLY', 'PHISHING', 10, 0.75);
        $this->assertFalse($perf->isInColdStart());
    }

    public function testWithNewRewardUpdatesMovingAverage(): void
    {
        $perf = new PersonaPerformance('ELDERLY', 'PHISHING', 2, 0.6);

        // reward_avg_new = (0.6 * 2 + 0.8) / 3 = 2.0 / 3 = 0.6667
        $updated = $perf->withNewReward(0.8);

        $this->assertEquals(3, $updated->getSessionsCount());
        $this->assertEqualsWithDelta(0.6667, $updated->getRewardAvg(), 0.001);
    }

    public function testWithNewRewardFromZeroSessions(): void
    {
        $perf = new PersonaPerformance('ELDERLY', 'PHISHING', 0, 0.0);

        // reward_avg_new = (0.0 * 0 + 0.75) / 1 = 0.75
        $updated = $perf->withNewReward(0.75);

        $this->assertEquals(1, $updated->getSessionsCount());
        $this->assertEquals(0.75, $updated->getRewardAvg());
    }

    public function testWithNewRewardPreservesImmutability(): void
    {
        $original = new PersonaPerformance('ELDERLY', 'PHISHING', 5, 0.5);
        $updated = $original->withNewReward(0.8);

        // Original unchanged
        $this->assertEquals(5, $original->getSessionsCount());
        $this->assertEquals(0.5, $original->getRewardAvg());

        // New instance updated
        $this->assertEquals(6, $updated->getSessionsCount());
        $this->assertNotEquals(0.5, $updated->getRewardAvg());
    }

    public function testThrowsExceptionWhenNewRewardTooHigh(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('New reward must be in [0.0, 1.0], got 1.5');

        $perf = new PersonaPerformance('ELDERLY', 'PHISHING', 5, 0.5);
        $perf->withNewReward(1.5); // 1.5 > 1.0 → INVALID
    }

    public function testThrowsExceptionWhenNewRewardNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('New reward must be in [0.0, 1.0]');

        $perf = new PersonaPerformance('ELDERLY', 'PHISHING', 5, 0.5);
        $perf->withNewReward(-0.1);
    }

    public function testThrowsExceptionWhenPersonaCodeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Persona code cannot be empty');

        new PersonaPerformance('', 'PHISHING', 5, 0.5);
    }

    public function testThrowsExceptionWhenScamTypeCodeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Scam type code cannot be empty');

        new PersonaPerformance('ELDERLY', '', 5, 0.5);
    }

    public function testThrowsExceptionWhenSessionsCountNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sessions count must be >= 0');

        new PersonaPerformance('ELDERLY', 'PHISHING', -1, 0.5);
    }

    public function testThrowsExceptionWhenRewardAvgTooHigh(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reward average must be in [0.0, 1.0]');

        new PersonaPerformance('ELDERLY', 'PHISHING', 5, 1.5);
    }

    public function testThrowsExceptionWhenRewardAvgNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reward average must be in [0.0, 1.0]');

        new PersonaPerformance('ELDERLY', 'PHISHING', 5, -0.1);
    }

    public function testGettersReturnCorrectValues(): void
    {
        $perf = new PersonaPerformance('generic_user', 'ROMANCE', 10, 0.85);

        $this->assertEquals('generic_user', $perf->getPersonaCode());
        $this->assertEquals('ROMANCE', $perf->getScamTypeCode());
        $this->assertEquals(10, $perf->getSessionsCount());
        $this->assertEquals(0.85, $perf->getRewardAvg());
    }

    public function testGetColdStartThresholdReturnsThree(): void
    {
        $this->assertEquals(3, PersonaPerformance::getColdStartThreshold());
    }

    public function testToStringContainsColdStartWhenApplicable(): void
    {
        $perfColdStart = new PersonaPerformance('ELDERLY', 'PHISHING', 2, 0.5);
        $string = (string) $perfColdStart;

        $this->assertStringContainsString('PersonaPerformance', $string);
        $this->assertStringContainsString('persona=ELDERLY', $string);
        $this->assertStringContainsString('scamType=PHISHING', $string);
        $this->assertStringContainsString('sessions=2', $string);
        $this->assertStringContainsString('[COLD START]', $string);
    }

    public function testToStringWithoutColdStart(): void
    {
        $perf = new PersonaPerformance('ELDERLY', 'PHISHING', 10, 0.75);
        $string = (string) $perf;

        $this->assertStringContainsString('PersonaPerformance', $string);
        $this->assertStringContainsString('rewardAvg=0.7500', $string);
        $this->assertStringNotContainsString('[COLD START]', $string);
    }

    public function testGetAdjustedScoreFavorsUnderexploredArms(): void
    {
        $underexplored = new PersonaPerformance('UNDER', 'PHISHING', 3, 0.50);
        $wellExplored = new PersonaPerformance('WELL', 'PHISHING', 100, 0.50);

        $totalSessions = 103;
        $c = 0.5;

        $scoreUnder = $underexplored->getAdjustedScore($totalSessions, $c);
        $scoreWell = $wellExplored->getAdjustedScore($totalSessions, $c);

        // Same reward_avg, but underexplored arm should get a higher adjusted score
        $this->assertGreaterThan($scoreWell, $scoreUnder);
        // Both should be > base reward_avg due to bonus
        $this->assertGreaterThan(0.50, $scoreUnder);
        $this->assertGreaterThan(0.50, $scoreWell);
    }

    public function testGetAdjustedScoreDecaysWithSessions(): void
    {
        $c = 0.5;
        $totalSessions = 200;

        $few = new PersonaPerformance('FEW', 'PHISHING', 5, 0.60);
        $many = new PersonaPerformance('MANY', 'PHISHING', 150, 0.60);

        $bonusFew = $few->getAdjustedScore($totalSessions, $c) - 0.60;
        $bonusMany = $many->getAdjustedScore($totalSessions, $c) - 0.60;

        // Bonus should be much larger for the arm with fewer sessions
        $this->assertGreaterThan($bonusMany, $bonusFew);
        // Bonus for well-explored arm should be small
        $this->assertLessThan(0.15, $bonusMany);
    }

    public function testGetAdjustedScoreReturnsRewardAvgWhenZeroSessions(): void
    {
        $perf = new PersonaPerformance('NEW', 'PHISHING', 0, 0.0);

        $this->assertSame(0.0, $perf->getAdjustedScore(100, 0.5));
    }
}

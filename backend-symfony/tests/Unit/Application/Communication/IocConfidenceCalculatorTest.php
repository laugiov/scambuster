<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocConfidenceCalculator;
use App\Domain\ThreatActor\AnalystVerdict;
use PHPUnit\Framework\TestCase;

class IocConfidenceCalculatorTest extends TestCase
{
    // --- Base confidence ---

    public function testRegexBaseConfidence(): void
    {
        $this->assertSame(0.95, IocConfidenceCalculator::getBaseConfidence('regex'));
    }

    public function testLlmBaseConfidence(): void
    {
        $this->assertSame(0.75, IocConfidenceCalculator::getBaseConfidence('llm'));
    }

    public function testHeaderBaseConfidence(): void
    {
        $this->assertSame(0.99, IocConfidenceCalculator::getBaseConfidence('header'));
    }

    public function testUnknownMethodReturnsDefault(): void
    {
        $this->assertSame(0.80, IocConfidenceCalculator::getBaseConfidence('unknown'));
    }

    // --- Boost confidence (driven by DISTINCT corroborating sources) ---

    public function testSingleSourceNeverBoosts(): void
    {
        // The argument is now the count of DISTINCT corroborating sources. Raw
        // repetition of the same value from one source is not corroboration (it is
        // the poisoning vector), so a single source never lifts confidence.
        $this->assertSame(0.75, IocConfidenceCalculator::boostConfidence(0.75, 1));
        $this->assertSame(0.75, IocConfidenceCalculator::boostConfidence(0.75, 0));
    }

    public function testDistinctSourcesBoostLinearly(): void
    {
        // +0.05 per corroborating source beyond the first.
        $this->assertEqualsWithDelta(0.80, IocConfidenceCalculator::boostConfidence(0.75, 2), 0.0001);
        $this->assertEqualsWithDelta(0.85, IocConfidenceCalculator::boostConfidence(0.75, 3), 0.0001);
    }

    public function testCorroborationBoostIsHardCapped(): void
    {
        // Volume alone can never drive confidence to ~1.0: the lift saturates at
        // +0.15, so many sources of the same value stay bounded.
        $this->assertEqualsWithDelta(0.90, IocConfidenceCalculator::boostConfidence(0.75, 4), 0.0001);
        $this->assertEqualsWithDelta(0.90, IocConfidenceCalculator::boostConfidence(0.75, 50), 0.0001);
    }

    public function testBoostNeverExceedsOne(): void
    {
        $this->assertLessThanOrEqual(1.0, IocConfidenceCalculator::boostConfidence(0.95, 100));
    }

    // --- Decay factor ---

    public function testDecayFactorFreshIoc(): void
    {
        $now = new \DateTimeImmutable('2026-03-25');
        $lastSeen = new \DateTimeImmutable('2026-03-25');
        $factor = IocConfidenceCalculator::computeDecayFactor('url', $lastSeen, $now);
        $this->assertSame(1.0, $factor);
    }

    public function testDecayFactorAtHalfLife(): void
    {
        $now = new \DateTimeImmutable('2026-03-25');
        $lastSeen = new \DateTimeImmutable('2026-03-11'); // 14 days ago
        $factor = IocConfidenceCalculator::computeDecayFactor('url', $lastSeen, $now); // half-life=14
        $this->assertEqualsWithDelta(0.5, $factor, 0.01);
    }

    public function testDecayFactorAtDoubleHalfLife(): void
    {
        $now = new \DateTimeImmutable('2026-03-25');
        $lastSeen = new \DateTimeImmutable('2026-02-25'); // 28 days ago
        $factor = IocConfidenceCalculator::computeDecayFactor('url', $lastSeen, $now); // half-life=14
        $this->assertEqualsWithDelta(0.25, $factor, 0.01);
    }

    public function testDecayFactorDomainSlower(): void
    {
        $now = new \DateTimeImmutable('2026-03-25');
        $lastSeen = new \DateTimeImmutable('2026-03-11'); // 14 days ago
        $factor = IocConfidenceCalculator::computeDecayFactor('domain', $lastSeen, $now); // half-life=30
        // 2^(-14/30) ≈ 0.73
        $this->assertGreaterThan(0.7, $factor);
        $this->assertLessThan(0.8, $factor);
    }

    public function testDecayFactorHashVeryStable(): void
    {
        $now = new \DateTimeImmutable('2026-03-25');
        $lastSeen = new \DateTimeImmutable('2026-02-23'); // 30 days ago
        $factor = IocConfidenceCalculator::computeDecayFactor('sha256', $lastSeen, $now); // half-life=365
        // 2^(-30/365) ≈ 0.945
        $this->assertGreaterThan(0.93, $factor);
    }

    // --- Effective score ---

    public function testEffectiveScoreFresh(): void
    {
        $now = new \DateTimeImmutable('2026-03-25');
        $score = IocConfidenceCalculator::computeEffectiveScore(0.95, 'url', $now, $now);
        $this->assertEqualsWithDelta(0.95, $score, 0.01);
    }

    public function testEffectiveScoreDecayed(): void
    {
        $now = new \DateTimeImmutable('2026-03-25');
        $lastSeen = new \DateTimeImmutable('2026-03-11'); // 14 days
        $score = IocConfidenceCalculator::computeEffectiveScore(0.95, 'url', $lastSeen, $now);
        // 0.95 * 0.5 = 0.475
        $this->assertEqualsWithDelta(0.475, $score, 0.02);
    }

    public function testEffectiveScoreVeryOld(): void
    {
        $now = new \DateTimeImmutable('2026-03-25');
        $lastSeen = new \DateTimeImmutable('2025-09-25'); // 6 months
        $score = IocConfidenceCalculator::computeEffectiveScore(0.95, 'url', $lastSeen, $now);
        // Very decayed for URL (half-life 14 days, ~13 half-lives)
        $this->assertLessThan(0.001, $score);
    }

    // --- Analyst verdict override ---

    public function testNoVerdictLeavesConfidenceUnchanged(): void
    {
        $this->assertSame(0.75, IocConfidenceCalculator::applyAnalystVerdict(0.75, null));
    }

    public function testConfirmedPinsConfidenceHigh(): void
    {
        $this->assertSame(0.99, IocConfidenceCalculator::applyAnalystVerdict(0.50, AnalystVerdict::Confirmed));
    }

    public function testConfirmedDoesNotLowerAnAlreadyHigherBase(): void
    {
        $this->assertSame(1.0, IocConfidenceCalculator::applyAnalystVerdict(1.0, AnalystVerdict::Confirmed));
    }

    public function testFalsePositiveDropsConfidenceNearZero(): void
    {
        $this->assertSame(0.05, IocConfidenceCalculator::applyAnalystVerdict(0.99, AnalystVerdict::FalsePositive));
    }
}

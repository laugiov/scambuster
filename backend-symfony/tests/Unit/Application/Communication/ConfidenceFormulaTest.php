<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocConfidenceCalculator;
use App\Application\Communication\IocDecayConfig;
use PHPUnit\Framework\TestCase;

/**
 * Epistemic validation: confidence formula reproduction tests.
 *
 * Reproduces exact calculations from IocConfidenceCalculator to verify
 * that documented formulas match implementation. Each test includes
 * the mathematical derivation in comments.
 *
 * Formulas under test:
 * - Multi-observation boost: 1 - (1-base)^occurrences
 * - Temporal decay: 2^(-age_days / half_life_days)
 * - Effective score: confidence * decay_factor
 * - Severity classification
 *
 * @covers \App\Application\Communication\IocConfidenceCalculator
 * @covers \App\Application\Communication\IocDecayConfig
 */
final class ConfidenceFormulaTest extends TestCase
{
    // ================================================================== //
    //  Base confidence values
    // ================================================================== //

    public function testBaseConfidenceHeader(): void
    {
        // header extraction = 0.99 (highest: parsed from RFC 5322 headers)
        $this->assertSame(0.99, IocConfidenceCalculator::getBaseConfidence('header'));
    }

    public function testBaseConfidenceRegex(): void
    {
        // regex extraction = 0.95
        $this->assertSame(0.95, IocConfidenceCalculator::getBaseConfidence('regex'));
    }

    public function testBaseConfidenceLlm(): void
    {
        // LLM extraction = 0.75 (lowest: model hallucination risk)
        $this->assertSame(0.75, IocConfidenceCalculator::getBaseConfidence('llm'));
    }

    public function testBaseConfidenceUnknownMethod(): void
    {
        // Unknown method = 0.80 (default)
        $this->assertSame(0.80, IocConfidenceCalculator::getBaseConfidence('unknown_method'));
    }

    // ================================================================== //
    //  Multi-observation boost: 1 - (1-base)^n
    // ================================================================== //

    public function testBoostHeaderSingle(): void
    {
        // base=0.99, n=1 → 1-(1-0.99)^1 = 1-0.01 = 0.99
        $this->assertEqualsWithDelta(
            0.99,
            IocConfidenceCalculator::boostConfidence(0.99, 1),
            0.0001,
        );
    }

    public function testBoostHeaderDouble(): void
    {
        // base=0.99, n=2 → 1-(0.01)^2 = 1-0.0001 = 0.9999
        $this->assertEqualsWithDelta(
            0.9999,
            IocConfidenceCalculator::boostConfidence(0.99, 2),
            0.0001,
        );
    }

    public function testBoostRegexTriple(): void
    {
        // base=0.95, n=3 → 1-(0.05)^3 = 1-0.000125 = 0.999875
        $this->assertEqualsWithDelta(
            0.999875,
            IocConfidenceCalculator::boostConfidence(0.95, 3),
            0.0001,
        );
    }

    public function testBoostLlmSingle(): void
    {
        // base=0.75, n=1 → returns base unchanged
        $this->assertEqualsWithDelta(
            0.75,
            IocConfidenceCalculator::boostConfidence(0.75, 1),
            0.0001,
        );
    }

    public function testBoostLlmDouble(): void
    {
        // base=0.75, n=2 → 1-(0.25)^2 = 1-0.0625 = 0.9375
        $this->assertEqualsWithDelta(
            0.9375,
            IocConfidenceCalculator::boostConfidence(0.75, 2),
            0.0001,
        );
    }

    public function testBoostLlmTriple(): void
    {
        // base=0.75, n=3 → 1-(0.25)^3 = 1-0.015625 = 0.984375
        $this->assertEqualsWithDelta(
            0.984375,
            IocConfidenceCalculator::boostConfidence(0.75, 3),
            0.0001,
        );
    }

    public function testBoostLlmFive(): void
    {
        // base=0.75, n=5 → 1-(0.25)^5 = 1-0.0009765625 = 0.9990234375
        $this->assertEqualsWithDelta(
            0.9990234375,
            IocConfidenceCalculator::boostConfidence(0.75, 5),
            0.0001,
        );
    }

    public function testBoostZeroOccurrences(): void
    {
        // n=0 treated same as n=1 (no boost)
        $this->assertEqualsWithDelta(
            0.75,
            IocConfidenceCalculator::boostConfidence(0.75, 0),
            0.0001,
        );
    }

    public function testBoostNeverExceedsOne(): void
    {
        $result = IocConfidenceCalculator::boostConfidence(0.99, 1000);
        $this->assertLessThanOrEqual(1.0, $result);
    }

    // ================================================================== //
    //  Half-life configuration
    // ================================================================== //

    public function testHalfLifeUrl(): void
    {
        $this->assertSame(14, IocDecayConfig::getHalfLifeDays('url'));
    }

    public function testHalfLifeIp(): void
    {
        $this->assertSame(7, IocDecayConfig::getHalfLifeDays('ipv4'));
        $this->assertSame(7, IocDecayConfig::getHalfLifeDays('ipv6'));
    }

    public function testHalfLifeDomain(): void
    {
        $this->assertSame(30, IocDecayConfig::getHalfLifeDays('domain'));
    }

    public function testHalfLifeEmail(): void
    {
        $this->assertSame(60, IocDecayConfig::getHalfLifeDays('email'));
    }

    public function testHalfLifePhone(): void
    {
        $this->assertSame(90, IocDecayConfig::getHalfLifeDays('phone'));
    }

    public function testHalfLifeFinancial(): void
    {
        $this->assertSame(180, IocDecayConfig::getHalfLifeDays('iban'));
        $this->assertSame(180, IocDecayConfig::getHalfLifeDays('wallet_btc'));
        $this->assertSame(180, IocDecayConfig::getHalfLifeDays('wallet_eth'));
        $this->assertSame(180, IocDecayConfig::getHalfLifeDays('wallet_xmr'));
    }

    public function testHalfLifeHash(): void
    {
        $this->assertSame(365, IocDecayConfig::getHalfLifeDays('sha256'));
        $this->assertSame(365, IocDecayConfig::getHalfLifeDays('sha1'));
        $this->assertSame(365, IocDecayConfig::getHalfLifeDays('md5'));
    }

    public function testHalfLifeDefault(): void
    {
        $this->assertSame(30, IocDecayConfig::getDefaultHalfLife());
        $this->assertSame(30, IocDecayConfig::getHalfLifeDays('unknown_type'));
    }

    // ================================================================== //
    //  Temporal decay: 2^(-age_days / half_life_days)
    // ================================================================== //

    public function testDecayFreshIocReturnsOne(): void
    {
        $now = new \DateTimeImmutable('2026-04-12');
        $factor = IocConfidenceCalculator::computeDecayFactor('url', $now, $now);
        $this->assertSame(1.0, $factor);
    }

    public function testDecayUrlAtHalfLife(): void
    {
        // url half-life=14d, age=14d → 2^(-14/14) = 2^(-1) = 0.5
        $now = new \DateTimeImmutable('2026-04-12');
        $lastSeen = new \DateTimeImmutable('2026-03-29'); // 14 days ago
        $factor = IocConfidenceCalculator::computeDecayFactor('url', $lastSeen, $now);
        $this->assertEqualsWithDelta(0.5, $factor, 0.01);
    }

    public function testDecayUrl30Days(): void
    {
        // url half-life=14d, age=30d → 2^(-30/14) = 2^(-2.1429) ≈ 0.2268
        $now = new \DateTimeImmutable('2026-04-12');
        $lastSeen = new \DateTimeImmutable('2026-03-13'); // 30 days ago
        $factor = IocConfidenceCalculator::computeDecayFactor('url', $lastSeen, $now);
        $this->assertEqualsWithDelta(0.2268, $factor, 0.01);
    }

    public function testDecayIpAt7Days(): void
    {
        // ipv4 half-life=7d, age=7d → 2^(-7/7) = 0.5
        $now = new \DateTimeImmutable('2026-04-12');
        $lastSeen = new \DateTimeImmutable('2026-04-05'); // 7 days ago
        $factor = IocConfidenceCalculator::computeDecayFactor('ipv4', $lastSeen, $now);
        $this->assertEqualsWithDelta(0.5, $factor, 0.01);
    }

    public function testDecayDomainAt30Days(): void
    {
        // domain half-life=30d, age=30d → 2^(-30/30) = 0.5
        $now = new \DateTimeImmutable('2026-04-12');
        $lastSeen = new \DateTimeImmutable('2026-03-13'); // 30 days ago
        $factor = IocConfidenceCalculator::computeDecayFactor('domain', $lastSeen, $now);
        $this->assertEqualsWithDelta(0.5, $factor, 0.01);
    }

    public function testDecaySha256At30Days(): void
    {
        // sha256 half-life=365d, age=30d → 2^(-30/365) ≈ 0.9446
        $now = new \DateTimeImmutable('2026-04-12');
        $lastSeen = new \DateTimeImmutable('2026-03-13'); // 30 days ago
        $factor = IocConfidenceCalculator::computeDecayFactor('sha256', $lastSeen, $now);
        $this->assertEqualsWithDelta(0.9446, $factor, 0.01);
    }

    public function testDecayIbanAt180Days(): void
    {
        // iban half-life=180d, age=180d → 2^(-180/180) = 0.5
        $now = new \DateTimeImmutable('2026-04-12');
        $lastSeen = new \DateTimeImmutable('2025-10-14'); // ~180 days ago
        $factor = IocConfidenceCalculator::computeDecayFactor('iban', $lastSeen, $now);
        $this->assertEqualsWithDelta(0.5, $factor, 0.02);
    }

    public function testDecayPhoneAt90Days(): void
    {
        // phone half-life=90d, age=90d → 2^(-90/90) = 0.5
        $now = new \DateTimeImmutable('2026-04-12');
        $lastSeen = new \DateTimeImmutable('2026-01-12'); // 90 days ago
        $factor = IocConfidenceCalculator::computeDecayFactor('phone', $lastSeen, $now);
        $this->assertEqualsWithDelta(0.5, $factor, 0.02);
    }

    // ================================================================== //
    //  Effective score: confidence × decay
    // ================================================================== //

    public function testEffectiveScoreFreshUrl(): void
    {
        // confidence=0.95, url, age=0 → 0.95 × 1.0 = 0.95
        $now = new \DateTimeImmutable('2026-04-12');
        $score = IocConfidenceCalculator::computeEffectiveScore(0.95, 'url', $now, $now);
        $this->assertEqualsWithDelta(0.95, $score, 0.001);
    }

    public function testEffectiveScoreUrlAtHalfLife(): void
    {
        // confidence=0.95, url, age=14d → 0.95 × 0.5 = 0.475
        $now = new \DateTimeImmutable('2026-04-12');
        $lastSeen = new \DateTimeImmutable('2026-03-29');
        $score = IocConfidenceCalculator::computeEffectiveScore(0.95, 'url', $lastSeen, $now);
        $this->assertEqualsWithDelta(0.475, $score, 0.02);
    }

    public function testEffectiveScoreDomainAt30Days(): void
    {
        // confidence=0.95, domain half-life=30d, age=30d
        // decay = 2^(-30/30) = 0.5 → 0.95 × 0.5 = 0.475
        $now = new \DateTimeImmutable('2026-04-12');
        $lastSeen = new \DateTimeImmutable('2026-03-13');
        $score = IocConfidenceCalculator::computeEffectiveScore(0.95, 'domain', $lastSeen, $now);
        $this->assertEqualsWithDelta(0.475, $score, 0.02);
    }

    public function testEffectiveScoreRoundedTo4Decimals(): void
    {
        // Verify rounding behavior
        $now = new \DateTimeImmutable('2026-04-12');
        $lastSeen = new \DateTimeImmutable('2026-04-02'); // 10 days ago
        // url, half-life=14, decay = 2^(-10/14) ≈ 0.6095
        // effective = 0.95 × 0.6095 ≈ 0.5790 (rounded to 4 dp)
        $score = IocConfidenceCalculator::computeEffectiveScore(0.95, 'url', $lastSeen, $now);
        // Verify result has at most 4 decimal places
        $this->assertSame($score, round($score, 4));
    }

    // ================================================================== //
    //  Severity classification
    // ================================================================== //

    public function testSeverityFinancialAlwaysHigh(): void
    {
        $financialTypes = ['iban', 'bank_account', 'credit_card', 'wallet_btc', 'wallet_eth', 'wallet_xmr', 'phone'];

        foreach ($financialTypes as $type) {
            $this->assertSame(
                'HIGH',
                IocConfidenceCalculator::computeSeverity($type, 0, 0),
                "Financial type '{$type}' should always be HIGH even without enrichment",
            );
        }
    }

    public function testSeverityNetworkMediumByDefault(): void
    {
        $networkTypes = ['url', 'domain', 'email', 'ipv4', 'ipv6'];

        foreach ($networkTypes as $type) {
            $this->assertSame(
                'MEDIUM',
                IocConfidenceCalculator::computeSeverity($type, 0, 0),
                "Network type '{$type}' should be MEDIUM without enrichment",
            );
        }
    }

    public function testSeverityNetworkUpgradedToHighWithVt(): void
    {
        $this->assertSame(
            'HIGH',
            IocConfidenceCalculator::computeSeverity('url', 1, 0),
            'URL with VT > 0 should be upgraded to HIGH',
        );
    }

    public function testSeverityMetadataAlwaysLow(): void
    {
        $metadataTypes = ['subject', 'message_id', 'dmarc_result', 'x_mailer'];

        foreach ($metadataTypes as $type) {
            $this->assertSame(
                'LOW',
                IocConfidenceCalculator::computeSeverity($type, 0, 0),
                "Metadata type '{$type}' should always be LOW",
            );
        }
    }

    public function testSeverityBicIsMediumNotHigh(): void
    {
        // BIC identifies a bank (millions of clients), NOT a specific threat actor
        $this->assertSame(
            'MEDIUM',
            IocConfidenceCalculator::computeSeverity('bic', 0, 0),
            'BIC should be MEDIUM (identifies bank, not threat actor)',
        );
    }
}

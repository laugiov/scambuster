<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\RiskScorer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RiskScorer
 *
 * Tests scoring algorithm from specs/05-normaliser-decider.md §4
 */
final class RiskScorerTest extends TestCase
{
    private RiskScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new RiskScorer();
    }

    public function testCalculateIocScoreWithVirusTotalMalicious(): void
    {
        $enrichment = [
            'virustotal' => [
                'malicious' => 5,
                'suspicious' => 0,
                'harmless' => 85,
                'undetected' => 10
            ]
        ];

        $result = $this->scorer->calculateIocScore($enrichment);

        $this->assertSame(70, $result['vt'], 'VT score should be 70 for malicious');
        $this->assertSame(0, $result['urlscan'], 'URLscan score should be 0');
        $this->assertSame(70, $result['agg'], 'Aggregate score should be 70');
        $this->assertStringContainsString('VT malicious=5', $result['explain']);
    }

    public function testCalculateIocScoreWithVirusTotalSuspicious(): void
    {
        $enrichment = [
            'virustotal' => [
                'malicious' => 0,
                'suspicious' => 3,
                'harmless' => 87,
                'undetected' => 10
            ]
        ];

        $result = $this->scorer->calculateIocScore($enrichment);

        $this->assertSame(40, $result['vt'], 'VT score should be 40 for suspicious');
        $this->assertSame(40, $result['agg'], 'Aggregate score should be 40');
        $this->assertStringContainsString('VT suspicious=3', $result['explain']);
    }

    public function testCalculateIocScoreWithUrlscanMalicious(): void
    {
        $enrichment = [
            'urlscan' => [
                'verdict' => 'malicious',
                'status' => 'completed'
            ]
        ];

        $result = $this->scorer->calculateIocScore($enrichment);

        $this->assertSame(0, $result['vt'], 'VT score should be 0');
        $this->assertSame(60, $result['urlscan'], 'URLscan score should be 60');
        $this->assertSame(60, $result['agg'], 'Aggregate score should be 60');
        $this->assertStringContainsString('URLscan malicious', $result['explain']);
    }

    public function testCalculateIocScoreWithUrlscanSuspicious(): void
    {
        $enrichment = [
            'urlscan' => [
                'verdict' => 'suspicious',
                'status' => 'completed'
            ]
        ];

        $result = $this->scorer->calculateIocScore($enrichment);

        $this->assertSame(25, $result['urlscan'], 'URLscan score should be 25');
        $this->assertSame(25, $result['agg'], 'Aggregate score should be 25');
    }

    public function testCalculateIocScoreWithBothMalicious(): void
    {
        $enrichment = [
            'virustotal' => [
                'malicious' => 10,
                'suspicious' => 0
            ],
            'urlscan' => [
                'verdict' => 'malicious'
            ]
        ];

        $result = $this->scorer->calculateIocScore($enrichment);

        $this->assertSame(70, $result['vt'], 'VT score should be 70');
        $this->assertSame(60, $result['urlscan'], 'URLscan score should be 60');
        $this->assertSame(100, $result['agg'], 'Aggregate should be capped at 100');
        $this->assertStringContainsString('VT malicious', $result['explain']);
        $this->assertStringContainsString('URLscan malicious', $result['explain']);
    }

    public function testCalculateIocScoreWithNoThreats(): void
    {
        $enrichment = [
            'virustotal' => [
                'malicious' => 0,
                'suspicious' => 0,
                'harmless' => 90
            ],
            'urlscan' => [
                'verdict' => 'clean'
            ]
        ];

        $result = $this->scorer->calculateIocScore($enrichment);

        $this->assertSame(0, $result['vt'], 'VT score should be 0');
        $this->assertSame(0, $result['urlscan'], 'URLscan score should be 0');
        $this->assertSame(0, $result['agg'], 'Aggregate score should be 0');
        $this->assertSame('No threats detected', $result['explain']);
    }

    public function testCalculateIocScoreWithEmptyEnrichment(): void
    {
        $result = $this->scorer->calculateIocScore([]);

        $this->assertSame(0, $result['agg'], 'Aggregate score should be 0 for empty enrichment');
        $this->assertSame('No threats detected', $result['explain']);
    }

    public function testDetermineLevelHigh(): void
    {
        $this->assertSame('high', $this->scorer->determineLevel(70));
        $this->assertSame('high', $this->scorer->determineLevel(85));
        $this->assertSame('high', $this->scorer->determineLevel(100));
    }

    public function testDetermineLevelMedium(): void
    {
        $this->assertSame('medium', $this->scorer->determineLevel(40));
        $this->assertSame('medium', $this->scorer->determineLevel(55));
        $this->assertSame('medium', $this->scorer->determineLevel(69));
    }

    public function testDetermineLevelLow(): void
    {
        $this->assertSame('low', $this->scorer->determineLevel(0));
        $this->assertSame('low', $this->scorer->determineLevel(15));
        $this->assertSame('low', $this->scorer->determineLevel(39));
    }

    public function testShouldReplyForHighRisk(): void
    {
        $iocs = []; // Empty IOCs - doesn't matter for high risk

        $this->assertTrue(
            $this->scorer->shouldReply(85, 'high', $iocs),
            'Should always reply for high risk'
        );
    }

    public function testShouldReplyForLowRisk(): void
    {
        $iocs = [
            ['type' => 'iban'],
            ['type' => 'phone']
        ]; // Even with exploitable IOCs

        $this->assertFalse(
            $this->scorer->shouldReply(20, 'low', $iocs),
            'Should never reply for low risk'
        );
    }

    public function testShouldReplyForMediumRiskWithExploitableIocs(): void
    {
        // IBAN present
        $iocs = [['type' => 'iban', 'value' => 'FR7630006000011234567890189']];
        $this->assertTrue(
            $this->scorer->shouldReply(50, 'medium', $iocs),
            'Should reply for medium risk with IBAN'
        );

        // Phone present
        $iocs = [['type' => 'phone', 'value' => '+33612345678']];
        $this->assertTrue(
            $this->scorer->shouldReply(50, 'medium', $iocs),
            'Should reply for medium risk with phone'
        );

        // URL present (could be auth page)
        $iocs = [['type' => 'url', 'value' => 'https://evil-login.com']];
        $this->assertTrue(
            $this->scorer->shouldReply(50, 'medium', $iocs),
            'Should reply for medium risk with URL'
        );
    }

    public function testShouldNotReplyForMediumRiskWithoutExploitableIocs(): void
    {
        $iocs = [
            ['type' => 'email', 'value' => 'scammer@example.com'],
            ['type' => 'domain', 'value' => 'example.com']
        ];

        $this->assertFalse(
            $this->scorer->shouldReply(50, 'medium', $iocs),
            'Should not reply for medium risk without exploitable IOCs'
        );
    }

    public function testShouldNotReplyForMediumRiskWithEmptyIocs(): void
    {
        $this->assertFalse(
            $this->scorer->shouldReply(50, 'medium', []),
            'Should not reply for medium risk with no IOCs'
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\ThreatActor;

use App\Application\Clustering\ClusterQueryService;
use App\Application\Clustering\ClusterTemporalAnalyzer;
use App\Application\ThreatActor\AbuseReportGenerator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure parts of the abuse-report generator: the grounded
 * per-IOC recipient routing and the deterministic plain-text rendering.
 */
class AbuseReportGeneratorTest extends TestCase
{
    private AbuseReportGenerator $generator;

    protected function setUp(): void
    {
        // recipientForIocType() and renderText() are pure; the collaborators are
        // never touched on those paths. Both are final, so build them for real with
        // mocked (interface) deps — inert here.
        $this->generator = new AbuseReportGenerator(
            new ClusterQueryService($this->createMock(Connection::class)),
            new ClusterTemporalAnalyzer($this->createMock(EntityManagerInterface::class)),
            null,
        );
    }

    public function testRecipientRoutingByFamily(): void
    {
        self::assertStringContainsStringIgnoringCase('bank', $this->generator->recipientForIocType('iban'));
        self::assertStringContainsStringIgnoringCase('bank', $this->generator->recipientForIocType('bank_account'));
        self::assertStringContainsStringIgnoringCase('exchange', $this->generator->recipientForIocType('wallet_btc'));
        self::assertStringContainsStringIgnoringCase('exchange', $this->generator->recipientForIocType('wallet_eth'));
        self::assertStringContainsStringIgnoringCase('telecom', $this->generator->recipientForIocType('phone'));
        self::assertStringContainsStringIgnoringCase('email', $this->generator->recipientForIocType('email'));
        self::assertStringContainsStringIgnoringCase('registrar', $this->generator->recipientForIocType('domain'));
        self::assertStringContainsStringIgnoringCase('hosting', $this->generator->recipientForIocType('url'));
        self::assertStringContainsStringIgnoringCase('RIR', $this->generator->recipientForIocType('ipv4'));
    }

    public function testRecipientRoutingIsCaseInsensitive(): void
    {
        self::assertSame(
            $this->generator->recipientForIocType('iban'),
            $this->generator->recipientForIocType('IBAN'),
        );
    }

    public function testRecipientRoutingHasSafeDefault(): void
    {
        $default = $this->generator->recipientForIocType('some_unknown_type');
        self::assertStringContainsStringIgnoringCase('abuse desk', $default);
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleReport(bool $withPsych = true): array
    {
        return [
            'report_type' => 'threat-actor-abuse-report',
            'generated_from' => 'ScamBuster honeypot (first-party observation)',
            'actor' => [
                'cluster_id' => 'aaaa-bbbb',
                'stix_id' => 'threat-actor--aaaa-bbbb',
                'name' => 'Cluster Zeta',
                'sophistication' => 'organized',
                'first_seen' => '2026-05-01T00:00:00+00:00',
                'last_seen' => '2026-06-30T00:00:00+00:00',
            ],
            'scam_types' => ['INVOICE_FRAUD', 'CEO_FRAUD'],
            'evidence' => [
                'conversation_count' => 4,
                'inbound_message_count' => 42,
                'actionable_indicator_count' => 2,
                'criminal_time_wasted_sec' => 5400, // 1.5 hours
            ],
            'temporal' => [
                'active_days' => 12,
                'active_span_days' => 60,
                'busiest_day' => '2026-06-17',
                'max_messages_per_day' => 9,
                'burst_count' => 2,
            ],
            'psychological_profile' => $withPsych ? [
                'dominant_lever' => 'Authority',
                'behavioural_summary' => 'Impersonates a finance executive and pressures via urgency.',
            ] : null,
            'actionable_indicators' => [
                ['type' => 'iban', 'value' => 'DE00 0000', 'recommended_recipient' => 'Issuing bank / national financial-crime unit', 'conv_count' => 3],
                ['type' => 'wallet_btc', 'value' => 'bc1qxyz', 'recommended_recipient' => 'Cryptocurrency exchange / blockchain analytics provider', 'conv_count' => 1],
            ],
            'narrative' => 'Observed across 4 honeypot conversations.',
            'disclaimer' => 'First-party honeypot observation; indicators are actor-supplied and not externally verified.',
        ];
    }

    public function testRenderTextContainsActorEvidenceIndicatorsAndDisclaimer(): void
    {
        $text = $this->generator->renderText($this->sampleReport());

        self::assertStringContainsString('Cluster Zeta', $text);
        self::assertStringContainsString('threat-actor--aaaa-bbbb', $text);
        // Actionable indicator + its routed recipient must both appear.
        self::assertStringContainsString('DE00 0000', $text);
        self::assertStringContainsString('Issuing bank', $text);
        self::assertStringContainsString('bc1qxyz', $text);
        // Temporal + scam types + disclaimer.
        self::assertStringContainsString('2026-06-17', $text);
        self::assertStringContainsString('INVOICE_FRAUD', $text);
        self::assertStringContainsString('First-party honeypot observation', $text);
        // Psych profile present.
        self::assertStringContainsString('Authority', $text);
        // Criminal time wasted (the headline Scam metric) is rendered in hours.
        self::assertStringContainsString('Criminal time wasted: 1.5 hours', $text);
    }

    public function testCriminalTimeWastedFormatsMinutesUnderAnHour(): void
    {
        $report = $this->sampleReport();
        $report['evidence']['criminal_time_wasted_sec'] = 600; // 10 minutes
        self::assertStringContainsString('Criminal time wasted: 10 minutes', $this->generator->renderText($report));
    }

    public function testCriminalTimeWastedDefaultsToZeroMinutesWhenAbsent(): void
    {
        $report = $this->sampleReport();
        unset($report['evidence']['criminal_time_wasted_sec']);
        self::assertStringContainsString('Criminal time wasted: 0 minutes', $this->generator->renderText($report));
    }

    public function testRenderTextIsStableWithoutPsychProfile(): void
    {
        $text = $this->generator->renderText($this->sampleReport(withPsych: false));

        // Must not crash and must still render the core report.
        self::assertStringContainsString('Cluster Zeta', $text);
        self::assertStringContainsString('DE00 0000', $text);
        self::assertStringNotContainsString('Authority', $text);
    }
}

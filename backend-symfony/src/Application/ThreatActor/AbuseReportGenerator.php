<?php

declare(strict_types=1);

namespace App\Application\ThreatActor;

use App\Application\Clustering\ClusterQueryService;
use App\Application\Clustering\ClusterTemporalAnalyzer;

/**
 * Assembles an abuse / takedown report for a threat-actor cluster — the capstone
 * that ties clustering (who), the temporal analysis (when) and the psychological
 * profile (how) into one actionable, ready-to-send artifact.
 *
 * Strictly FACTUAL and read-only: it reports only first-party observed data, carries
 * an explicit provenance disclaimer, makes no external-reputation claim, and never
 * touches reply generation. Each actionable indicator is routed to the STANDARD abuse
 * desk for its type — routine CTI practice, not a claim about a specific entity.
 */
final readonly class AbuseReportGenerator
{
    /**
     * IOC type → standard abuse-desk recipient. Grounded routine routing, not attribution.
     *
     * @var array<string, string>
     */
    private const RECIPIENT = [
        'iban' => 'Issuing bank / national financial-crime unit',
        'bank_account' => 'Issuing bank / national financial-crime unit',
        'credit_card' => 'Card scheme / issuing bank fraud desk',
        'wallet_btc' => 'Cryptocurrency exchange / blockchain analytics provider',
        'wallet_eth' => 'Cryptocurrency exchange / blockchain analytics provider',
        'wallet_xmr' => 'Cryptocurrency exchange / blockchain analytics provider',
        'phone' => 'Telecom carrier / national telecom regulator',
        'email' => 'Email service provider abuse desk',
        'whois_email' => 'Email service provider abuse desk',
        'domain' => 'Domain registrar / hosting abuse desk',
        'url' => 'Hosting provider / URL blocklist (e.g. Safe Browsing, PhishTank)',
        'ipv4' => 'Network operator via RIR abuse contact',
        'ipv6' => 'Network operator via RIR abuse contact',
        'ip' => 'Network operator via RIR abuse contact',
        'postal_address' => 'Local law-enforcement / postal-fraud unit',
    ];

    private const DISCLAIMER = 'First-party honeypot observation; indicators are actor-supplied and '
        . 'have not been independently verified against external reputation sources. '
        . 'Provided for defensive / takedown purposes.';

    public function __construct(
        private ClusterQueryService $clusterQuery,
        private ClusterTemporalAnalyzer $temporalAnalyzer,
        private ?ThreatActorPsychProfileReaderInterface $psychReader = null,
    ) {
    }

    public function recipientForIocType(string $type): string
    {
        return self::RECIPIENT[strtolower(trim($type))] ?? 'Relevant service-provider abuse desk';
    }

    /**
     * @return array<string, mixed>|null null when the cluster is unknown
     */
    public function generate(string $clusterId): ?array
    {
        $detail = $this->clusterQuery->getDetail($clusterId);

        if ($detail === null) {
            return null;
        }

        $temporal = $this->temporalAnalyzer->analyze($clusterId);
        $psych = $this->psychReader?->getByClusterId($clusterId);

        $indicators = [];

        foreach ($this->asList($detail['anchor_iocs'] ?? null) as $anchor) {
            if (!\is_array($anchor)) {
                continue;
            }

            $type = \is_string($anchor['ioc_type'] ?? null) ? $anchor['ioc_type'] : 'unknown';

            $indicators[] = [
                'type' => $type,
                'value' => \is_string($anchor['ioc_value'] ?? null) ? $anchor['ioc_value'] : '',
                'recommended_recipient' => $this->recipientForIocType($type),
                'conv_count' => \is_numeric($anchor['conv_count'] ?? null) ? (int) $anchor['conv_count'] : 0,
                'first_observed' => \is_string($anchor['first_observed'] ?? null) ? $anchor['first_observed'] : null,
                'last_observed' => \is_string($anchor['last_observed'] ?? null) ? $anchor['last_observed'] : null,
            ];
        }

        $scamTypes = $this->scamTypes($detail);
        $firstSeen = \is_string($detail['first_seen'] ?? null) ? $detail['first_seen'] : null;
        $lastSeen = \is_string($detail['last_seen'] ?? null) ? $detail['last_seen'] : null;
        $convCount = \is_numeric($detail['conversation_count'] ?? null) ? (int) $detail['conversation_count'] : 0;
        $name = \is_string($detail['name'] ?? null) ? $detail['name'] : 'Unnamed cluster';

        $inboundMessages = \is_array($temporal) && \is_int($temporal['message_count'] ?? null)
            ? $temporal['message_count']
            : 0;

        $report = [
            'report_type' => 'threat-actor-abuse-report',
            'generated_from' => 'ScamBuster honeypot (first-party observation)',
            'actor' => [
                'cluster_id' => \is_string($detail['cluster_id'] ?? null) ? $detail['cluster_id'] : $clusterId,
                'stix_id' => \is_string($detail['stix_id'] ?? null) ? $detail['stix_id'] : '',
                'name' => $name,
                'sophistication' => \is_string($detail['sophistication'] ?? null) ? $detail['sophistication'] : null,
                'first_seen' => $firstSeen,
                'last_seen' => $lastSeen,
            ],
            'scam_types' => $scamTypes,
            'evidence' => [
                'conversation_count' => $convCount,
                'inbound_message_count' => $inboundMessages,
                'actionable_indicator_count' => \count($indicators),
                'criminal_time_wasted_sec' => $this->clusterQuery->getEngagementDurationSec($clusterId),
            ],
            'temporal' => $temporal,
            'psychological_profile' => $psych?->toArray(),
            'actionable_indicators' => $indicators,
            'narrative' => $this->narrative($name, $convCount, $firstSeen, $lastSeen, $scamTypes, \count($indicators)),
            'disclaimer' => self::DISCLAIMER,
        ];

        $report['text'] = $this->renderText($report);

        return $report;
    }

    /**
     * Deterministic plain-text rendering for pasting into an abuse complaint. Pure.
     *
     * @param array<string, mixed> $report
     */
    public function renderText(array $report): string
    {
        $actor = \is_array($report['actor'] ?? null) ? $report['actor'] : [];
        $evidence = \is_array($report['evidence'] ?? null) ? $report['evidence'] : [];
        $temporal = \is_array($report['temporal'] ?? null) ? $report['temporal'] : null;
        $psych = \is_array($report['psychological_profile'] ?? null) ? $report['psychological_profile'] : null;
        $scamTypes = $this->asList($report['scam_types'] ?? null);

        $lines = [];
        $lines[] = 'THREAT-ACTOR ABUSE / TAKEDOWN REPORT';
        $lines[] = 'Source: ' . $this->str($report['generated_from'] ?? 'ScamBuster honeypot');
        $lines[] = '';
        $lines[] = 'Actor: ' . $this->str($actor['name'] ?? '') . ' (' . $this->str($actor['stix_id'] ?? '') . ')';

        if (\is_string($actor['sophistication'] ?? null) && $actor['sophistication'] !== '') {
            $lines[] = 'Sophistication: ' . $actor['sophistication'];
        }

        $lines[] = 'Observed activity: ' . $this->str($actor['first_seen'] ?? 'unknown') . ' to ' . $this->str($actor['last_seen'] ?? 'unknown');
        $lines[] = 'Scam types: ' . ($scamTypes === [] ? 'n/a' : implode(', ', array_map($this->str(...), $scamTypes)));
        $lines[] = '';
        $lines[] = 'Evidence:';
        $lines[] = '  - Conversations observed: ' . $this->str($evidence['conversation_count'] ?? 0);
        $lines[] = '  - Inbound (scammer) messages: ' . $this->str($evidence['inbound_message_count'] ?? 0);
        $lines[] = '  - Actionable indicators: ' . $this->str($evidence['actionable_indicator_count'] ?? 0);
        $wastedSec = \is_numeric($evidence['criminal_time_wasted_sec'] ?? null) ? (int) $evidence['criminal_time_wasted_sec'] : 0;
        $lines[] = '  - Criminal time wasted: ' . $this->formatHours($wastedSec) . " (the actor's own time spent on the honeypot)";

        if ($temporal !== null) {
            $lines[] = '';
            $lines[] = 'Activity pattern:';
            $lines[] = '  - Active days: ' . $this->str($temporal['active_days'] ?? 0) . ' over ' . $this->str($temporal['active_span_days'] ?? 0) . ' days';

            if (($temporal['busiest_day'] ?? null) !== null) {
                $lines[] = '  - Busiest day: ' . $this->str($temporal['busiest_day']) . ' (' . $this->str($temporal['max_messages_per_day'] ?? 0) . ' messages)';
            }

            $lines[] = '  - Burst days: ' . $this->str($temporal['burst_count'] ?? 0);
        }

        if ($psych !== null) {
            $lines[] = '';
            $lines[] = 'Psychological profile:';
            $lines[] = '  - Dominant influence lever: ' . $this->str($psych['dominant_lever'] ?? 'n/a');

            if (\is_string($psych['behavioural_summary'] ?? null) && $psych['behavioural_summary'] !== '') {
                $lines[] = '  - ' . $psych['behavioural_summary'];
            }
        }

        $lines[] = '';
        $lines[] = 'Actionable indicators (report each to the listed recipient):';

        foreach ($this->asList($report['actionable_indicators'] ?? null) as $ind) {
            if (!\is_array($ind)) {
                continue;
            }

            $type = strtoupper($this->str($ind['type'] ?? ''));
            $lines[] = '  - [' . $type . '] ' . $this->str($ind['value'] ?? '')
                . '  ->  ' . $this->str($ind['recommended_recipient'] ?? '')
                . '  (seen in ' . $this->str($ind['conv_count'] ?? 0) . ' conversation(s))';
        }

        $lines[] = '';
        $lines[] = $this->str($report['narrative'] ?? '');
        $lines[] = '';
        $lines[] = 'DISCLAIMER: ' . $this->str($report['disclaimer'] ?? self::DISCLAIMER);

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $detail
     *
     * @return list<string>
     */
    private function scamTypes(array $detail): array
    {
        $types = [];

        foreach ($this->asList($detail['primary_scam_types'] ?? null) as $t) {
            if (\is_string($t) && $t !== '' && !\in_array($t, $types, true)) {
                $types[] = $t;
            }
        }

        if ($types !== []) {
            return $types;
        }

        // Fallback: distinct scam types across the cluster's conversations.
        foreach ($this->asList($detail['conversations'] ?? null) as $conv) {
            if (\is_array($conv) && \is_string($conv['scam_type'] ?? null) && $conv['scam_type'] !== '' && !\in_array($conv['scam_type'], $types, true)) {
                $types[] = $conv['scam_type'];
            }
        }

        return $types;
    }

    /**
     * @param list<string> $scamTypes
     */
    private function narrative(string $name, int $convCount, ?string $firstSeen, ?string $lastSeen, array $scamTypes, int $indicatorCount): string
    {
        $window = $firstSeen !== null && $lastSeen !== null ? " between {$firstSeen} and {$lastSeen}" : '';
        $types = $scamTypes === [] ? 'scam' : implode(' / ', $scamTypes);

        return sprintf(
            'The threat actor "%s" was observed across %d ScamBuster honeypot conversation(s)%s, running %s activity. '
            . 'The %d actionable indicator(s) below were extracted directly from the actor\'s own messages and are '
            . 'recommended for reporting to the listed abuse desks.',
            $name,
            $convCount,
            $window,
            $types,
            $indicatorCount,
        );
    }

    /**
     * @return list<mixed>
     */
    private function asList(mixed $value): array
    {
        return \is_array($value) ? array_values($value) : [];
    }

    private function str(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Human-readable duration for the report: minutes under an hour, else hours to one decimal.
     */
    private function formatHours(int $seconds): string
    {
        if ($seconds < 3600) {
            return max(0, (int) round($seconds / 60)) . ' minutes';
        }

        return sprintf('%.1f hours', $seconds / 3600);
    }
}

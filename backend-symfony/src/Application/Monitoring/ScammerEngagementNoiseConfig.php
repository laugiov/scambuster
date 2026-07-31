<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

/**
 * Noise patterns used to exclude technical artifacts
 * (bounces, DMARC aggregate reports, postmaster notifications) from the
 * Scammer Engagement metric.
 *
 * These patterns evolved separately from the live ingestion pre-filter
 * (App\Application\Communication\IngestPostProcessor::matchPreFilter)
 * because:
 *   - this registry runs at QUERY time on historical data, which may
 *     include conversations that pre-date the live filter
 *   - the live filter's job is to PREVENT ingestion; this registry's job
 *     is to EXCLUDE from a metric. The thresholds differ.
 *
 * TODO: consider merging the two registries once
 * the live filter is stable on all historical patterns. Requires
 * a dedicated change to avoid regressing the ingest pipeline.
 */
final readonly class ScammerEngagementNoiseConfig
{
    /**
     * Subject line patterns (case-insensitive ILIKE). A first inbound
     * matching any of these excludes the conversation from the metric.
     *
     * @var list<string>
     */
    public const array SUBJECT_PATTERNS = [
        '%undelivered mail%',
        '%returned to sender%',
        '%delivery status%',
        '%mail delivery%',
        '%report domain%',
    ];

    /**
     * Sender (`headers->>'from'`) patterns (case-insensitive ILIKE). A
     * first inbound from a sender matching any of these excludes the
     * conversation from the metric. Also used to exclude individual
     * counterparts at the per-message level.
     *
     * @var list<string>
     */
    public const array SENDER_PATTERNS = [
        'mailer-daemon@%',
        'postmaster@%',
        '%dmarc%',
        'noreply%@google.com',
        '%@protection.outlook.com',
    ];

    /**
     * @return list<string>
     */
    public function subjectPatterns(): array
    {
        return self::SUBJECT_PATTERNS;
    }

    /**
     * @return list<string>
     */
    public function senderPatterns(): array
    {
        return self::SENDER_PATTERNS;
    }
}

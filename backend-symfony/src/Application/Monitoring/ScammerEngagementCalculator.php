<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use Doctrine\DBAL\Connection;

/**
 * Spec 096 / C1 — Bias-corrected scammer engagement rate.
 *
 * Computes the rate at which scammers actually reply to our outbound
 * messages, after correcting for three biases observed on a one-month
 * production sample (~9 points of underestimation in the naive metric):
 *
 *   1. Technical noise: bounces, DMARC reports, postmaster notifications
 *      create fake conversations (76 of 376 = 20 % in the sample).
 *   2. Right-censoring: scammers take time to reply (p95 = 73.6 h).
 *      A conversation engaged less than `censoring_hours` ago without
 *      reply is NOT a refusal — exclude from the denominator.
 *   3. Conversation fragmentation: outbound messages don't persist
 *      their `external_message_id`, so a scammer's reply often opens
 *      a NEW conversation rather than threading into the existing one.
 *      Compute the metric PER REAL SENDER, not per conversation.
 *
 * TODO (post-spec-096): persist outbound `external_message_id` at send
 * time and re-thread by `In-Reply-To` / `References` to fix bias 3 at
 * source. Then this calculator can be simplified.
 *
 * Performance: single PostgreSQL CTE, one round-trip. Uses the existing
 * btree index on `headers->>'from'` (idx_message_headers_from). The
 * `headers->>'to'` path is NOT indexed — acceptable up to ~5k messages
 * (covers a 1-month window today). For larger horizons consider adding
 * a generated `counterpart_email` column on `message`.
 */
final readonly class ScammerEngagementCalculator
{
    /**
     * Hard bounds for the censoring_hours parameter to avoid abuse.
     */
    public const int CENSORING_HOURS_MIN = 0;
    public const int CENSORING_HOURS_MAX = 8760; // 1 year
    public const int CENSORING_HOURS_DEFAULT = 96;

    /**
     * @param list<string>|null $honeypotEmailAddresses comma-decoded from env (Spec 061).
     *                                                  Null when the env var is unset
     *                                                  (Symfony's `csv:` processor returns null,
     *                                                  not []) — treated as a no-op honeypot
     *                                                  filter. Matches the tolerant signature
     *                                                  already used by IocUpsertService and
     *                                                  CleanupPlatformContaminationCommand.
     */
    public function __construct(
        private Connection $connection,
        private ScammerEngagementNoiseConfig $noiseConfig,
        private ?array $honeypotEmailAddresses = null,
    ) {
    }

    /**
     * Spec 096 / C2b — `$period` accepts '7d', '30d', '90d', or 'all'. When
     * set to anything but 'all', the metric is restricted to conversations
     * whose `ts_last >= NOW() - period`. Combines with `$scamTypeFilter`
     * orthogonally — both filters are AND-ed together per spec.
     *
     * @return array{
     *   global: array{observable: int, responded: int, rate_pct: float},
     *   by_scam_type: list<array{scam_type: string, observable: int, responded: int, rate_pct: float}>,
     *   params: array{
     *     censoring_hours: int,
     *     scam_type_filter: ?string,
     *     period: string,
     *     noise_subject_patterns: int,
     *     noise_sender_patterns: int,
     *     honeypot_addresses: int,
     *   },
     *   methodology_note: string
     * }
     */
    public function calculate(
        int $censoringHours = self::CENSORING_HOURS_DEFAULT,
        ?string $scamTypeFilter = null,
        string $period = 'all',
    ): array {
        $censoringHours = max(self::CENSORING_HOURS_MIN, min(self::CENSORING_HOURS_MAX, $censoringHours));

        $subjectPatterns = $this->noiseConfig->subjectPatterns();
        $senderPatterns = $this->noiseConfig->senderPatterns();
        // Defensive: env CSV may contain empty entries when HONEYPOT_EMAIL_ADDRESSES
        // is unset or contains trailing commas — filter them out so we don't
        // build a `'' != ALL(...)` clause that excludes empty-string counterparts
        // unintentionally.
        $honeypots = array_values(array_filter(
            array_map(static fn (string $e): string => strtolower(trim($e)), $this->honeypotEmailAddresses ?? []),
            static fn (string $e): bool => $e !== '',
        ));

        // Resolve direction codes to actual dir_ids — these vary per DB
        // (1/2 in production but auto-incremented in fresh fixtures).
        /** @var int|string|false $directionInRaw */
        $directionInRaw = $this->connection->fetchOne(
            "SELECT dir_id FROM lkp_direction WHERE code = 'in'",
        );
        /** @var int|string|false $directionOutRaw */
        $directionOutRaw = $this->connection->fetchOne(
            "SELECT dir_id FROM lkp_direction WHERE code = 'out'",
        );
        $directionInId = $directionInRaw !== false ? (int) $directionInRaw : 1;
        $directionOutId = $directionOutRaw !== false ? (int) $directionOutRaw : 2;

        // Spec 096 / C2b — period maps to a Postgres interval string. 'all' = no filter.
        $periodInterval = $this->periodToInterval($period);

        $rows = $this->runQuery(
            $censoringHours,
            $subjectPatterns,
            $senderPatterns,
            $honeypots,
            $scamTypeFilter,
            $directionInId,
            $directionOutId,
            $periodInterval,
        );

        // Split the rollup result into "global" (the null-scam_type row from ROLLUP) and per-type rows.
        $global = ['observable' => 0, 'responded' => 0, 'rate_pct' => 0.0];
        $byScamType = [];

        foreach ($rows as $r) {
            /** @var array{scam_type: ?string, observable: int, responded: int} $r */
            $observable = (int) $r['observable'];
            $responded = (int) $r['responded'];
            $rate = $observable > 0 ? round(100.0 * $responded / $observable, 1) : 0.0;

            if ($r['scam_type'] === null) {
                $global = ['observable' => $observable, 'responded' => $responded, 'rate_pct' => $rate];
            } else {
                $byScamType[] = [
                    'scam_type' => (string) $r['scam_type'],
                    'observable' => $observable,
                    'responded' => $responded,
                    'rate_pct' => $rate,
                ];
            }
        }

        // Sort by observable desc (per spec)
        usort($byScamType, static fn (array $a, array $b): int => $b['observable'] <=> $a['observable']);

        return [
            'global' => $global,
            'by_scam_type' => $byScamType,
            'params' => [
                'censoring_hours' => $censoringHours,
                'scam_type_filter' => $scamTypeFilter,
                'period' => $period,
                'noise_subject_patterns' => \count($subjectPatterns),
                'noise_sender_patterns' => \count($senderPatterns),
                'honeypot_addresses' => \count($honeypots),
            ],
            'methodology_note' => 'Per real sender, excluding technical noise (bounces, DMARC) and engagements newer than censoring_hours (response may still be in-flight).',
        ];
    }

    /**
     * Single CTE returning one row per (scam_type, NULL for global) with
     * observable + responded counts. Uses GROUP BY ROLLUP for a single
     * pass.
     *
     * Honeypots and senderPatterns arrays are bound via Connection
     * array parameter types — Postgres handles empty arrays gracefully
     * with `ANY/ALL` operators.
     *
     * @param list<string> $subjectPatterns
     * @param list<string> $senderPatterns
     * @param list<string> $honeypots
     *
     * @return list<array{scam_type: ?string, observable: int, responded: int}>
     */
    private function runQuery(
        int $censoringHours,
        array $subjectPatterns,
        array $senderPatterns,
        array $honeypots,
        ?string $scamTypeFilter,
        int $directionInId,
        int $directionOutId,
        ?string $periodInterval,
    ): array {
        // We use ANY/ALL on a Postgres text[] cast. Empty arrays still
        // work and produce predictable results.
        // - subject ILIKE ANY('{}'::text[]) is FALSE → no rows excluded
        // - counterpart NOT IN (NULL) returns NULL → row excluded.
        //   To avoid that, gate the NOT IN on cardinality.
        $honeypotsHasItems = \count($honeypots) > 0;
        $sendersHasItems = \count($senderPatterns) > 0;

        // Postgres ANY/ALL operators require a real array, not a list of bind
        // parameters. Doctrine's ArrayParameterType expands to `$1, $2, ...`
        // which only works inside IN(...). We build a Postgres text[] array
        // literal as a single string and pass it bound as one parameter with
        // an explicit ::text[] cast inside the SQL.
        $subjectArrayLit = $this->toPgTextArrayLiteral($subjectPatterns);
        $senderArrayLit = $this->toPgTextArrayLiteral($senderPatterns);
        $honeypotArrayLit = $this->toPgTextArrayLiteral($honeypots);

        $scamTypeFilterSql = $scamTypeFilter !== null ? ' AND scam_type = :scam_type_filter' : '';
        $honeypotFilterSql = $honeypotsHasItems ? ' AND counterpart != ALL(:honeypots::text[])' : '';
        $senderFilterSql = $sendersHasItems ? ' AND counterpart NOT ILIKE ALL(:sender_patterns::text[])' : '';
        // Spec 096 / C2b — period filter scopes which conversations are
        // considered. Empty string when period='all' → no time restriction.
        $periodFilterSql = $periodInterval !== null
            ? ' AND c.ts_last >= NOW() - (:period_interval)::interval'
            : '';

        $noiseSubjectClause = $subjectPatterns !== []
            ? 'first_in.subject ILIKE ANY(:subject_patterns::text[])'
            : 'FALSE';
        $noiseSenderClause = $sendersHasItems
            ? 'first_in.sender ILIKE ANY(:sender_patterns_noise::text[])'
            : 'FALSE';

        $sql = <<<SQL
        WITH
        noise_convs AS (
            SELECT DISTINCT c.conv_id
            FROM conversation c
            CROSS JOIN LATERAL (
                SELECT m.subject, LOWER(m.headers::jsonb->>'from') AS sender
                FROM message m
                WHERE m.conv_id = c.conv_id AND m.direction = {$directionInId} AND m.deleted_at IS NULL
                ORDER BY m.ts_msg ASC LIMIT 1
            ) first_in
            WHERE c.deleted_at IS NULL
              AND (
                {$noiseSubjectClause}
                OR {$noiseSenderClause}
              )
        ),
        msg_with_counterpart AS (
            SELECT
                m.msg_id, m.conv_id, m.ts_msg, m.direction,
                c.scam_type_id,
                LOWER(COALESCE(
                    SUBSTRING(m.headers::jsonb->>'from' FROM '<([^>]+)>'),
                    TRIM(m.headers::jsonb->>'from')
                )) AS from_email,
                LOWER(COALESCE(
                    SUBSTRING(m.headers::jsonb->>'to' FROM '<([^>]+)>'),
                    TRIM(m.headers::jsonb->>'to')
                )) AS to_email
            FROM message m
            JOIN conversation c USING (conv_id)
            WHERE m.deleted_at IS NULL
              AND c.deleted_at IS NULL
              AND c.conv_id NOT IN (SELECT conv_id FROM noise_convs)
              {$periodFilterSql}
        ),
        per_msg AS (
            SELECT
                msg_id, conv_id, ts_msg, direction, scam_type_id,
                CASE WHEN direction = {$directionOutId} THEN to_email ELSE from_email END AS counterpart
            FROM msg_with_counterpart
        ),
        per_msg_filtered AS (
            SELECT * FROM per_msg
            WHERE counterpart IS NOT NULL
              AND counterpart != ''
              {$honeypotFilterSql}
              {$senderFilterSql}
        ),
        per_counterpart AS (
            SELECT counterpart,
                MIN(CASE WHEN direction = {$directionOutId} THEN ts_msg END) AS first_out,
                MAX(CASE WHEN direction = {$directionOutId} THEN ts_msg END) AS last_out
            FROM per_msg_filtered
            GROUP BY counterpart
            HAVING MIN(CASE WHEN direction = {$directionOutId} THEN ts_msg END) IS NOT NULL
        ),
        responded AS (
            SELECT DISTINCT pm.counterpart
            FROM per_msg_filtered pm
            JOIN per_counterpart pc USING (counterpart)
            WHERE pm.direction = {$directionInId} AND pm.ts_msg > pc.first_out
        ),
        counterpart_scam_type AS (
            SELECT DISTINCT ON (pm.counterpart)
                pm.counterpart, st.code AS scam_type
            FROM per_msg_filtered pm
            JOIN lkp_scam_type st ON st.scam_type_id = pm.scam_type_id
            WHERE pm.direction = {$directionOutId}
            ORDER BY pm.counterpart, pm.ts_msg ASC
        ),
        enriched AS (
            SELECT pc.counterpart,
                pc.last_out <= NOW() - (:censoring_hours::text || ' hours')::interval AS observable,
                EXISTS (SELECT 1 FROM responded r WHERE r.counterpart = pc.counterpart) AS responded,
                cst.scam_type
            FROM per_counterpart pc
            LEFT JOIN counterpart_scam_type cst USING (counterpart)
        )
        SELECT
            scam_type,
            SUM(CASE WHEN observable THEN 1 ELSE 0 END)::int AS observable,
            SUM(CASE WHEN observable AND responded THEN 1 ELSE 0 END)::int AS responded
        FROM enriched
        WHERE 1=1 {$scamTypeFilterSql}
        GROUP BY ROLLUP(scam_type)
        SQL;

        $params = ['censoring_hours' => (string) $censoringHours];

        if ($subjectPatterns !== []) {
            $params['subject_patterns'] = $subjectArrayLit;
        }

        if ($sendersHasItems) {
            $params['sender_patterns_noise'] = $senderArrayLit;
            $params['sender_patterns'] = $senderArrayLit;
        }

        if ($honeypotsHasItems) {
            $params['honeypots'] = $honeypotArrayLit;
        }

        if ($scamTypeFilter !== null) {
            $params['scam_type_filter'] = $scamTypeFilter;
        }

        if ($periodInterval !== null) {
            $params['period_interval'] = $periodInterval;
        }

        /** @var list<array{scam_type: ?string, observable: int, responded: int}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return $rows;
    }

    /**
     * Build a Postgres `text[]` array literal from a PHP list, escaping
     * backslashes and double-quotes per the Postgres array-input syntax.
     *
     * Example: ['mailer-daemon@%', '%dmarc%'] →
     *          '{"mailer-daemon@%","%dmarc%"}'
     *
     * Empty input returns `{}` (empty array literal), which is a valid
     * text[] value Postgres treats as size-0.
     *
     * @param list<string> $items
     */
    private function toPgTextArrayLiteral(array $items): string
    {
        if ($items === []) {
            return '{}';
        }

        $quoted = array_map(
            static fn (string $s): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"',
            $items,
        );

        return '{' . implode(',', $quoted) . '}';
    }

    /**
     * Spec 096 / C2b — Map the period query param to a Postgres interval
     * string suitable for `NOW() - INTERVAL ...`. Returns null when the
     * period is 'all' (no filter) or unrecognized.
     */
    private function periodToInterval(string $period): ?string
    {
        return match ($period) {
            '7d' => '7 days',
            '30d' => '30 days',
            '90d' => '90 days',
            default => null,
        };
    }
}

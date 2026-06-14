<?php

declare(strict_types=1);

namespace App\Application\Stats;

use Doctrine\DBAL\Connection;

/**
 * Spec 100 S2 — Corpus-wide statistics on WHEN scammers reveal a
 * financial IOC inside their conversations.
 *
 * Hypothesis the platform is trying to make defensible: scammers
 * delay the financial ask until they've built rapport, so the first
 * financial IOC tends to appear LATE in the conversation. The Theater
 * already shows the per-conv ratio ("turn 12/13, 92%") — this service
 * provides the corpus baseline so the audience sees `Typical: turn
 * X/Y · This conv: 12/13 — typical pattern`.
 *
 * Denominator: closed conversations with at least one financial IOC.
 * Open / abandoned / mistake-tagged conversations are excluded
 * because the timing of an in-flight conversation is unknown.
 */
final readonly class FinancialRevealTimingService
{
    /** @var list<string> */
    private const FINANCIAL_TYPES = [
        'iban', 'bic', 'wallet_btc', 'wallet_eth', 'wallet_xmr',
        'bank_account', 'credit_card',
    ];

    public function __construct(
        private Connection $conn,
    ) {
    }

    /**
     * @return array{
     *   n: int,
     *   median_turn: int|null,
     *   p75_turn: int|null,
     *   median_ratio_pct: int|null,
     *   p75_ratio_pct: int|null,
     * }
     */
    public function compute(): array
    {
        $sql = <<<SQL
            WITH conv_msg_idx AS (
                SELECT
                    m.msg_id,
                    m.conv_id,
                    ROW_NUMBER() OVER (PARTITION BY m.conv_id ORDER BY m.ts_msg, m.msg_id) AS turn,
                    COUNT(*) OVER (PARTITION BY m.conv_id) AS total_turns
                FROM message m
            ),
            financial_first AS (
                SELECT
                    cm.conv_id,
                    MIN(cm.turn) AS first_financial_turn,
                    MAX(cm.total_turns) AS total_turns
                FROM conv_msg_idx cm
                JOIN observed_ioc oi ON oi.msg_id = cm.msg_id
                JOIN indicator i ON i.indicator_id = oi.indicator_id
                WHERE i.type IN (:financial_types)
                GROUP BY cm.conv_id
            ),
            filtered AS (
                SELECT
                    ff.first_financial_turn,
                    ff.total_turns,
                    ff.first_financial_turn::float / NULLIF(ff.total_turns, 0) AS ratio
                FROM financial_first ff
                JOIN conversation c ON c.conv_id = ff.conv_id
                WHERE c.status = 'closed'
                  AND ff.total_turns > 0
            )
            SELECT
                COUNT(*) AS n,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY first_financial_turn) AS median_turn,
                PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY first_financial_turn) AS p75_turn,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY ratio) AS median_ratio,
                PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY ratio) AS p75_ratio
            FROM filtered
            SQL;

        $row = $this->conn->fetchAssociative($sql, [
            'financial_types' => self::FINANCIAL_TYPES,
        ], [
            'financial_types' => \Doctrine\DBAL\ArrayParameterType::STRING,
        ]);

        $nRaw = ($row !== false) ? ($row['n'] ?? 0) : 0;
        $n = \is_numeric($nRaw) ? (int) $nRaw : 0;

        if ($row === false || $n === 0) {
            return [
                'n' => 0,
                'median_turn' => null,
                'p75_turn' => null,
                'median_ratio_pct' => null,
                'p75_ratio_pct' => null,
            ];
        }

        return [
            'n' => $n,
            'median_turn' => $this->intOrNull($row['median_turn'] ?? null),
            'p75_turn' => $this->intOrNull($row['p75_turn'] ?? null),
            'median_ratio_pct' => $this->ratioToPct($row['median_ratio'] ?? null),
            'p75_ratio_pct' => $this->ratioToPct($row['p75_ratio'] ?? null),
        ];
    }

    private function intOrNull(mixed $v): ?int
    {
        if (!\is_numeric($v)) {
            return null;
        }

        return (int) round((float) $v);
    }

    private function ratioToPct(mixed $v): ?int
    {
        if (!\is_numeric($v)) {
            return null;
        }

        return (int) round(((float) $v) * 100);
    }
}

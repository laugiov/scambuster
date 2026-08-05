<?php

declare(strict_types=1);

namespace App\Application\Stats;

use Doctrine\DBAL\Connection;

/**
 * Corpus-wide percentile baselines for
 * `ioc_context.urgency_score` across enriched IOCs.
 *
 * Lets the Theater render the per-IOC urgency bar with a small tick
 * at the corpus median, so a viewer can immediately see whether the
 * IOC in front of them is more or less pressuring than the typical
 * extracted IOC. Without this baseline the "20%" reading on a card
 * tells the analyst nothing.
 *
 * Cached at the HTTP layer (5 min) since the corpus grows slowly.
 */
final readonly class UrgencyCorpusStatsService
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array{n: int, median: float|null, p75: float|null}
     */
    public function compute(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS n,'
            . ' PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY urgency_score) AS median,'
            . ' PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY urgency_score) AS p75'
            . ' FROM ioc_context'
            . ' WHERE enrichment_status = \'enriched\''
            . ' AND urgency_score IS NOT NULL'
        );

        $n = is_numeric($row['n'] ?? null) ? (int) $row['n'] : 0;
        $median = is_numeric($row['median'] ?? null) ? round((float) $row['median'], 3) : null;
        $p75 = is_numeric($row['p75'] ?? null) ? round((float) $row['p75'], 3) : null;

        return [
            'n' => $n,
            'median' => $median,
            'p75' => $p75,
        ];
    }
}

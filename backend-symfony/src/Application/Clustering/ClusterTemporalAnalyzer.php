<?php

declare(strict_types=1);

namespace App\Application\Clustering;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Temporal / burst / cadence analysis over a threat-actor cluster's activity.
 *
 * Read-only and offline — it aggregates the timestamps of the cluster's INBOUND
 * (scammer) messages; it never writes and never touches reply generation. Computed
 * on-read (no persistence), mirroring {@see ClusterQueryService::getBehavioralProfile}:
 * the maths is cheap and deterministic, so a table would only add staleness.
 */
final readonly class ClusterTemporalAnalyzer
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array<string, mixed>|null null when the cluster has no inbound activity
     */
    public function analyze(string $clusterId): ?array
    {
        $conn = $this->em->getConnection();

        // Inbound = the scammer's sends. Resolve direction via the lookup code, never
        // a hardcoded dir_id (lkp_direction is sequence-generated).
        $rows = $conn->executeQuery(
            "SELECT m.ts_msg
             FROM threat_actor_cluster_conversation tacc
             JOIN message m ON m.conv_id = tacc.conv_id
             JOIN lkp_direction d ON m.direction = d.dir_id AND d.code = 'in'
             WHERE tacc.cluster_id = :cid AND m.deleted_at IS NULL
             ORDER BY m.ts_msg ASC",
            ['cid' => $clusterId],
        )->fetchFirstColumn();

        if ($rows === []) {
            return null;
        }

        $timestamps = [];

        foreach ($rows as $raw) {
            if (\is_string($raw) && $raw !== '') {
                // ts_msg is stored as UTC wall-clock (timestamp without time zone).
                $timestamps[] = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
            }
        }

        if ($timestamps === []) {
            return null;
        }

        return $this->computeMetrics($timestamps);
    }

    /**
     * Pure temporal-metric computation over a list of message timestamps.
     *
     * @param list<\DateTimeImmutable> $timestamps
     *
     * @return array<string, mixed>
     */
    public function computeMetrics(array $timestamps): array
    {
        usort($timestamps, static fn (\DateTimeInterface $a, \DateTimeInterface $b): int => $a <=> $b);

        $count = \count($timestamps);

        $hourHistogram = array_fill(0, 24, 0);
        $dowHistogram = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];

        if ($count === 0) {
            return [
                'message_count' => 0,
                'active_days' => 0,
                'first_activity' => null,
                'last_activity' => null,
                'active_span_days' => 0,
                'hour_of_day_histogram' => $hourHistogram,
                'peak_hour' => null,
                'day_of_week_histogram' => $dowHistogram,
                'peak_day_of_week' => null,
                'median_gap_hours' => null,
                'busiest_day' => null,
                'max_messages_per_day' => 0,
                'burst_days' => [],
                'burst_count' => 0,
                'longest_dormancy_hours' => null,
            ];
        }

        $perDay = [];

        foreach ($timestamps as $ts) {
            ++$hourHistogram[(int) $ts->format('G')];
            ++$dowHistogram[(int) $ts->format('N')];
            $day = $ts->format('Y-m-d');
            $perDay[$day] = ($perDay[$day] ?? 0) + 1;
        }

        $first = $timestamps[0];
        $last = $timestamps[$count - 1];

        // Inclusive calendar span between the first and last active dates.
        $firstMidnight = new \DateTimeImmutable($first->format('Y-m-d'), new \DateTimeZone('UTC'));
        $lastMidnight = new \DateTimeImmutable($last->format('Y-m-d'), new \DateTimeZone('UTC'));
        $spanDays = (int) $firstMidnight->diff($lastMidnight)->days + 1;

        // Busiest day (earliest wins ties, since perDay preserves ascending insertion).
        $busiestDay = null;
        $maxPerDay = 0;

        foreach ($perDay as $day => $dayCount) {
            if ($dayCount > $maxPerDay) {
                $maxPerDay = $dayCount;
                $busiestDay = $day;
            }
        }

        // Burst = a day at least double the actor's median daily volume (floor 3).
        // Median baseline, not mean+2σ: with few active days a burst inflates its own σ.
        $dailyCounts = array_values($perDay);
        $burstThreshold = max(3, (int) ceil(2 * $this->median($dailyCounts)));
        $burstDays = [];

        foreach ($perDay as $day => $dayCount) {
            if ($dayCount >= $burstThreshold) {
                $burstDays[] = (string) $day;
            }
        }

        sort($burstDays);

        // Inter-message gaps (seconds) between consecutive messages.
        $gaps = [];

        for ($i = 1; $i < $count; ++$i) {
            $gaps[] = $timestamps[$i]->getTimestamp() - $timestamps[$i - 1]->getTimestamp();
        }

        $medianGapHours = $gaps === [] ? null : round($this->median($gaps) / 3600, 3);
        $longestDormancyHours = $gaps === [] ? null : round(max($gaps) / 3600, 3);

        return [
            'message_count' => $count,
            'active_days' => \count($perDay),
            'first_activity' => $first->format('c'),
            'last_activity' => $last->format('c'),
            'active_span_days' => $spanDays,
            'hour_of_day_histogram' => $hourHistogram,
            'peak_hour' => $this->argMax($hourHistogram),
            'day_of_week_histogram' => $dowHistogram,
            'peak_day_of_week' => $this->argMax($dowHistogram),
            'median_gap_hours' => $medianGapHours,
            'busiest_day' => $busiestDay,
            'max_messages_per_day' => $maxPerDay,
            'burst_days' => $burstDays,
            'burst_count' => \count($burstDays),
            'longest_dormancy_hours' => $longestDormancyHours,
        ];
    }

    /**
     * @param array<int, int> $values
     */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        $sorted = array_values($values);
        sort($sorted);
        $n = \count($sorted);
        $mid = intdiv($n, 2);

        return $n % 2 === 1
            ? (float) $sorted[$mid]
            : ($sorted[$mid - 1] + $sorted[$mid]) / 2;
    }

    /**
     * Key with the highest value; the lowest key wins ties. Null if all zero.
     *
     * @param array<int, int> $histogram
     */
    private function argMax(array $histogram): ?int
    {
        $bestKey = null;
        $bestVal = 0;

        foreach ($histogram as $key => $val) {
            if ($val > $bestVal) {
                $bestVal = $val;
                $bestKey = $key;
            }
        }

        return $bestKey;
    }
}

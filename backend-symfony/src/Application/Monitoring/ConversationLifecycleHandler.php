<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use App\Application\Communication\ConversationLifecycleConfig;
use Doctrine\DBAL\Connection;

/**
 * Aggregates conversation lifecycle statistics.
 *
 * Computes: active, about_to_timeout, completed_today, by_scam_type.
 */
final class ConversationLifecycleHandler
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getLifecycleStats(): array
    {
        $active = $this->toInt($this->connection->fetchOne(
            "SELECT COUNT(*) FROM conversation WHERE status = 'open' AND deleted_at IS NULL"
        ));

        $completedToday = $this->toInt($this->connection->fetchOne(
            "SELECT COUNT(*) FROM conversation WHERE status = 'closed' AND deleted_at IS NULL AND updated_at >= :today",
            ['today' => (new \DateTimeImmutable('today'))->format('Y-m-d 00:00:00')]
        ));

        $abandonedToday = $this->toInt($this->connection->fetchOne(
            "SELECT COUNT(*) FROM conversation WHERE status = 'abandoned' AND deleted_at IS NULL AND updated_at >= :today",
            ['today' => (new \DateTimeImmutable('today'))->format('Y-m-d 00:00:00')]
        ));

        // About to timeout: open conversations within 24h of their policy timeout
        $aboutToTimeout = 0;
        $byScamType = [];

        $openByType = $this->connection->fetchAllAssociative(
            "SELECT st.code as scam_type, COUNT(*) as count,
                    MIN(c.ts_last) as oldest_ts_last
             FROM conversation c
             JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
             WHERE c.status = 'open' AND c.deleted_at IS NULL
             GROUP BY st.code"
        );

        $now = new \DateTimeImmutable();

        foreach ($openByType as $row) {
            /** @var string $scamType */
            $scamType = $row['scam_type'];
            $count = $this->toInt($row['count']);
            $timeoutHours = ConversationLifecycleConfig::getTimeoutHours($scamType);

            // Count conversations within 24h of timeout
            $warningThreshold = $now->modify(sprintf('-%d hours', max(0, $timeoutHours - 24)));
            $aboutToTimeoutForType = $this->toInt($this->connection->fetchOne(
                "SELECT COUNT(*) FROM conversation c
                 JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
                 WHERE c.status = 'open' AND c.deleted_at IS NULL
                   AND st.code = :scam_type
                   AND c.ts_last < :warning_threshold",
                ['scam_type' => $scamType, 'warning_threshold' => $warningThreshold->format('Y-m-d H:i:s')]
            ));

            $aboutToTimeout += $aboutToTimeoutForType;

            $byScamType[$scamType] = [
                'active' => $count,
                'about_to_timeout' => $aboutToTimeoutForType,
                'timeout_hours' => $timeoutHours,
                'max_turns' => ConversationLifecycleConfig::getMaxTurns($scamType),
            ];
        }

        return [
            'active' => $active,
            'about_to_timeout' => $aboutToTimeout,
            'completed_today' => $completedToday,
            'abandoned_today' => $abandonedToday,
            'by_scam_type' => $byScamType,
        ];
    }

    private function toInt(mixed $value): int
    {
        return (int) (is_numeric($value) ? $value : 0);
    }
}

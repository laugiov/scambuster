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
                'policy_timeout_hours' => $timeoutHours,
                'max_turns' => ConversationLifecycleConfig::getMaxTurns($scamType),
            ];
        }

        // UX-1: Include all active scam types, even those with 0 open conversations
        /** @var list<array{code: string}> $allScamTypes */
        $allScamTypes = $this->connection->fetchAllAssociative(
            "SELECT code FROM lkp_scam_type WHERE active = true AND code != 'UNKNOWN'"
        );

        foreach ($allScamTypes as $stRow) {
            $code = (string) $stRow['code'];

            if (!isset($byScamType[$code])) {
                $byScamType[$code] = [
                    'active' => 0,
                    'about_to_timeout' => 0,
                    'policy_timeout_hours' => ConversationLifecycleConfig::getTimeoutHours($code),
                    'max_turns' => ConversationLifecycleConfig::getMaxTurns($code),
                ];
            }
        }

        // Reopened today: conversations that went from closed/abandoned back to open today
        $reopenedToday = $this->toInt($this->connection->fetchOne(
            "SELECT COUNT(*) FROM conversation
             WHERE status = 'open' AND deleted_at IS NULL
               AND updated_at >= :today AND ts_first < :today",
            ['today' => (new \DateTimeImmutable('today'))->format('Y-m-d 00:00:00')]
        ));

        // About to timeout detail list (up to 20 rows)
        $aboutToTimeoutList = $this->getAboutToTimeoutList($now);

        return [
            'active' => $active,
            'about_to_timeout' => $aboutToTimeout,
            'completed_today' => $completedToday,
            'reopened_today' => $reopenedToday,
            'by_scam_type' => (object) $byScamType,
            'about_to_timeout_list' => $aboutToTimeoutList,
        ];
    }

    /**
     * @return list<array{conv_id: string, scam_type: string, persona: string, last_activity: string, timeout_hours: int, hours_remaining: float}>
     */
    private function getAboutToTimeoutList(\DateTimeImmutable $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT c.conv_id, st.code as scam_type,
                    p.persona_code as persona, c.ts_last
             FROM conversation c
             JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
             LEFT JOIN persona p ON c.persona_id = p.persona_id
             WHERE c.status = 'open' AND c.deleted_at IS NULL
             ORDER BY c.ts_last ASC
             LIMIT 50"
        );

        $result = [];

        foreach ($rows as $row) {
            /** @var array{conv_id: string, scam_type: string, persona: ?string, ts_last: string} $row */
            $scamType = $row['scam_type'];
            $timeoutHours = ConversationLifecycleConfig::getTimeoutHours($scamType);
            $tsLast = new \DateTimeImmutable($row['ts_last']);
            $deadline = $tsLast->modify("+{$timeoutHours} hours");
            $hoursRemaining = ($deadline->getTimestamp() - $now->getTimestamp()) / 3600;

            // Only include if within 24h of timeout
            if ($hoursRemaining <= 24 && $hoursRemaining > 0) {
                $result[] = [
                    'conv_id' => $row['conv_id'],
                    'scam_type' => $scamType,
                    'persona' => $row['persona'] ?? '',
                    'last_activity' => $tsLast->format(\DATE_ATOM),
                    'timeout_hours' => $timeoutHours,
                    'hours_remaining' => round($hoursRemaining, 1),
                ];
            }

            if (\count($result) >= 20) {
                break;
            }
        }

        return $result;
    }

    private function toInt(mixed $value): int
    {
        return (int) (is_numeric($value) ? $value : 0);
    }
}

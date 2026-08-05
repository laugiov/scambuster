<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use Doctrine\DBAL\Connection;

final readonly class RateLimitHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array{
     *     llm_calls_limit: int,
     *     active_conversations_limit: int,
     *     rate_limited_today: list<array{type: string, count: int}>,
     *     quarantined_senders_today: int
     * }
     */
    public function getStats(): array
    {
        $todayStart = (new \DateTimeImmutable('today midnight'))->format('Y-m-d H:i:s');

        // Count rate limit audit events by limit_type for today (native SQL for JSON access)
        $sql = <<<'SQL'
            SELECT details->>'limit_type' AS limit_type, COUNT(*) AS cnt
            FROM audit_log
            WHERE event_type = 'RATE_LIMIT_EXCEEDED'
              AND created_at >= :today
            GROUP BY details->>'limit_type'
        SQL;

        /** @var list<array{limit_type: string, cnt: string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, ['today' => $todayStart]);

        $rateLimitedToday = [];
        $quarantinedSenders = 0;

        foreach ($rows as $row) {
            $type = $row['limit_type'];
            $count = (int) $row['cnt'];

            if ($type === 'sender_flood') {
                $quarantinedSenders = $count;
            }

            $rateLimitedToday[] = ['type' => $type, 'count' => $count];
        }

        return [
            'llm_calls_limit' => 200,
            'active_conversations_limit' => 50,
            'rate_limited_today' => $rateLimitedToday,
            'quarantined_senders_today' => $quarantinedSenders,
        ];
    }
}

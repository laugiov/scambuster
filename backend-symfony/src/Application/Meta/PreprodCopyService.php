<?php

declare(strict_types=1);

namespace App\Application\Meta;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Copies conversations and messages from preprod to dev via dblink.
 */
final readonly class PreprodCopyService
{
    private const PREPROD_DSN = 'postgresql://scambuster:postgres@postgres-preprod:5432/scambuster_preprod';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return int Number of conversations in preprod
     */
    public function countPreprodConversations(): int
    {
        $preprodConn = DriverManager::getConnection([
            'url' => self::PREPROD_DSN,
        ]);

        /** @var string|int $count */
        $count = $preprodConn->fetchOne('SELECT COUNT(*) FROM conversation');

        return (int) $count;
    }

    public function clearDevData(): void
    {
        $this->connection->executeStatement('TRUNCATE TABLE message CASCADE');
        $this->connection->executeStatement('TRUNCATE TABLE conversation CASCADE');
        $this->connection->executeStatement('TRUNCATE TABLE persona_performance_stats CASCADE');
    }

    /**
     * @return int Number of conversations copied
     */
    public function copyConversations(): int
    {
        return (int) $this->connection->executeStatement("
            INSERT INTO conversation (
                conv_id, primary_channel_id, scam_type_id, account_id, persona_id,
                status, score_risk, ts_first, ts_last, stix_id,
                created_at, updated_at, deleted_at, delivery, tlp,
                engagement_duration_sec, turns_count, reward_value
            )
            SELECT
                c.conv_id, c.primary_channel_id, c.scam_type_id, c.account_id, c.persona_id,
                c.status, c.score_risk, c.ts_first, c.ts_last, c.stix_id,
                c.created_at, c.updated_at, c.deleted_at, c.delivery, c.tlp,
                c.engagement_duration_sec, c.turns_count, c.reward_value
            FROM dblink(
                '" . self::PREPROD_DSN . "',
                'SELECT conv_id, primary_channel_id, scam_type_id, account_id, persona_id,
                        status, score_risk, ts_first, ts_last, stix_id,
                        created_at, updated_at, deleted_at, delivery, tlp,
                        engagement_duration_sec, turns_count, reward_value
                 FROM conversation'
            ) AS c(
                conv_id uuid, primary_channel_id bigint, scam_type_id bigint, account_id uuid, persona_id uuid,
                status text, score_risk int, ts_first timestamp, ts_last timestamp, stix_id text,
                created_at timestamp, updated_at timestamp, deleted_at timestamp, delivery text, tlp text,
                engagement_duration_sec int, turns_count int, reward_value numeric(5,4)
            )
        ");
    }

    /**
     * @return int Number of messages copied
     */
    public function copyMessages(): int
    {
        return (int) $this->connection->executeStatement("
            INSERT INTO message (
                msg_id, conv_id, channel_id, direction_id,
                lang_detect, subject, body_text, body_html,
                headers, composite_hash, vector_id,
                reply_to, ts_msg, ts_ingest, created_at, updated_at, deleted_at
            )
            SELECT
                m.msg_id, m.conv_id, m.channel_id, m.direction_id,
                m.lang_detect, m.subject, m.body_text, m.body_html,
                m.headers, m.composite_hash, m.vector_id,
                m.reply_to, m.ts_msg, m.ts_ingest, m.created_at, m.updated_at, m.deleted_at
            FROM dblink(
                '" . self::PREPROD_DSN . "',
                'SELECT msg_id, conv_id, channel_id, direction_id,
                        lang_detect, subject, body_text, body_html,
                        headers, composite_hash, vector_id,
                        reply_to, ts_msg, ts_ingest, created_at, updated_at, deleted_at
                 FROM message'
            ) AS m(
                msg_id uuid, conv_id uuid, channel_id bigint, direction_id bigint,
                lang_detect text, subject text, body_text text, body_html text,
                headers jsonb, composite_hash text, vector_id text,
                reply_to text, ts_msg timestamp, ts_ingest timestamp, created_at timestamp, updated_at timestamp, deleted_at timestamp
            )
        ");
    }

    /**
     * @return array{conversations: int, messages: int}
     */
    public function getDevStats(): array
    {
        /** @var string|int $devCountRaw */
        $devCountRaw = $this->connection->fetchOne('SELECT COUNT(*) FROM conversation');
        /** @var string|int $devMsgCountRaw */
        $devMsgCountRaw = $this->connection->fetchOne('SELECT COUNT(*) FROM message');

        return [
            'conversations' => (int) $devCountRaw,
            'messages' => (int) $devMsgCountRaw,
        ];
    }
}

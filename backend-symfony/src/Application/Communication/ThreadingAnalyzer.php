<?php

declare(strict_types=1);

namespace App\Application\Communication;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Analyzes message threading by subject pattern.
 *
 * Returns raw message rows grouped by conversation for threading diagnostics.
 */
final readonly class ThreadingAnalyzer
{
    private Connection $connection;

    public function __construct(EntityManagerInterface $em)
    {
        $this->connection = $em->getConnection();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findMessagesBySubjectPattern(string $subjectPattern): array
    {
        $sql = "
            SELECT
                msg_id,
                conv_id,
                direction,
                ts_msg,
                subject,
                reply_to_msg_id,
                headers->>'from' as from_header,
                headers->>'message-id' as message_id,
                headers->>'in-reply-to' as in_reply_to,
                headers->>'references' as references,
                headers->>'thread_id' as thread_id
            FROM message
            WHERE subject LIKE :pattern
              AND deleted_at IS NULL
            ORDER BY ts_msg ASC
        ";

        $stmt = $this->connection->prepare($sql);
        $result = $stmt->executeQuery(['pattern' => '%' . $subjectPattern . '%']);

        return $result->fetchAllAssociative();
    }

    /**
     * Group messages by conv_id.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function groupByConversation(array $messages): array
    {
        $conversations = [];

        foreach ($messages as $msg) {
            /** @var string $convId */
            $convId = $msg['conv_id'];

            if (!isset($conversations[$convId])) {
                $conversations[$convId] = [];
            }
            $conversations[$convId][] = $msg;
        }

        return $conversations;
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Meta;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Clears all conversations, messages and preprod mail accounts from the preprod database.
 */
final readonly class PreprodClearService
{
    private Connection $connection;

    public function __construct(EntityManagerInterface $em)
    {
        $this->connection = $em->getConnection();
    }

    /**
     * @return array{conversations: int, messages: int}
     */
    public function countExistingData(): array
    {
        /** @var int|string|false $rawConv */
        $rawConv = $this->connection->fetchOne('SELECT COUNT(*) FROM conversation');
        /** @var int|string|false $rawMsg */
        $rawMsg = $this->connection->fetchOne('SELECT COUNT(*) FROM message');

        return [
            'conversations' => (int) $rawConv,
            'messages' => (int) $rawMsg,
        ];
    }

    /**
     * Truncate messages, conversations and remove preprod mail accounts.
     */
    public function clearAll(): void
    {
        $this->connection->executeStatement('TRUNCATE TABLE message CASCADE');
        $this->connection->executeStatement('TRUNCATE TABLE conversation CASCADE');
        $this->connection->executeStatement(
            "DELETE FROM mail_account WHERE endpoint LIKE '%preprod%'"
        );
    }
}

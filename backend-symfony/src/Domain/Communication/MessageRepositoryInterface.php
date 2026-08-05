<?php

declare(strict_types=1);

namespace App\Domain\Communication;

use Symfony\Component\Uid\Uuid;

interface MessageRepositoryInterface
{
    public function findById(Uuid $msgId): ?Message;

    /** @return array<Message> */
    public function findByConversation(Uuid $convId, int $limit = 50, int $offset = 0): array;

    public function findByExternalMessageId(string $messageId): ?Message;

    public function countByConversation(Uuid $convId): int;
}

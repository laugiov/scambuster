<?php

declare(strict_types=1);

namespace App\Domain\Communication;

use Symfony\Component\Uid\Uuid;

interface ConversationRepositoryInterface
{
    public function findById(Uuid $convId): ?Conversation;

    /** @return array<Conversation> */
    public function findByStatus(string $status): array;

    /** @return array<Conversation> */
    public function findActive(): array;

    /** @return array<Conversation> */
    public function findStale(\DateTimeImmutable $threshold): array;
}

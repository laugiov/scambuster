<?php

declare(strict_types=1);

namespace App\Domain\Communication;

interface TtpObservationRepositoryInterface
{
    public function save(TtpObservation $observation): void;

    /**
     * @return list<TtpObservation>
     */
    public function findByMessageId(string $msgId): array;

    /**
     * @return list<TtpObservation>
     */
    public function findByConversationId(string $convId): array;
}

<?php

declare(strict_types=1);

namespace App\Domain\Communication;

use Symfony\Component\Uid\Uuid;

interface ObservedIocRepositoryInterface
{
    public function findById(Uuid $obsId): ?ObservedIoc;

    /** @return array<ObservedIoc> */
    public function findByMessage(Uuid $msgId): array;

    /** @return array<ObservedIoc> */
    public function findByIndicator(Uuid $indicatorId): array;

    /** @return array<ObservedIoc> */
    public function findByConversation(Uuid $convId): array;
}

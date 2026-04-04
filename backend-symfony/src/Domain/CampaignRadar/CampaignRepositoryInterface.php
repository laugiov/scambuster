<?php

declare(strict_types=1);

namespace App\Domain\CampaignRadar;

use Symfony\Component\Uid\Uuid;

interface CampaignRepositoryInterface
{
    public function findById(Uuid $campaignId): ?Campaign;

    /** @return array<Campaign> */
    public function findActive(): array;

    /** @return array<Campaign> */
    public function findByStatus(string $status): array;
}

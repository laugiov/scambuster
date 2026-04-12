<?php

declare(strict_types=1);

namespace App\Domain\Communication\Repository;

use App\Domain\Communication\Channel;

interface ChannelRepositoryInterface
{
    public function findByCode(string $code): ?Channel;
}

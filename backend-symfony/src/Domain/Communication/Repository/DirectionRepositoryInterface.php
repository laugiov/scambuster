<?php

declare(strict_types=1);

namespace App\Domain\Communication\Repository;

use App\Domain\Communication\Direction;

interface DirectionRepositoryInterface
{
    public function findByCode(string $code): ?Direction;
}

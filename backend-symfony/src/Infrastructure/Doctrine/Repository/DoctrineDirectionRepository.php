<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Communication\Direction;
use App\Domain\Communication\Repository\DirectionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineDirectionRepository implements DirectionRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function findByCode(string $code): ?Direction
    {
        return $this->em->getRepository(Direction::class)->findOneBy(['code' => $code]);
    }
}

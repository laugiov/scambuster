<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Communication\Channel;
use App\Domain\Communication\Repository\ChannelRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineChannelRepository implements ChannelRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function findByCode(string $code): ?Channel
    {
        return $this->em->getRepository(Channel::class)->findOneBy(['code' => $code]);
    }
}

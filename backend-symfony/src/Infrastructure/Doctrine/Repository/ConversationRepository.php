<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationRepositoryInterface;
use App\Domain\Communication\ConversationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Conversation> */
class ConversationRepository extends ServiceEntityRepository implements ConversationRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    public function findById(Uuid $convId): ?Conversation
    {
        return $this->find($convId);
    }

    /** @return array<Conversation> */
    public function findByStatus(string $status): array
    {
        return $this->findBy(['status' => $status]);
    }

    /** @return array<Conversation> */
    public function findActive(): array
    {
        return $this->findBy(['status' => ConversationStatus::OPEN]);
    }

    /** @return array<Conversation> */
    public function findStale(\DateTimeImmutable $threshold): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andWhere('c.tsLast < :threshold')
            ->setParameter('status', ConversationStatus::OPEN)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }
}

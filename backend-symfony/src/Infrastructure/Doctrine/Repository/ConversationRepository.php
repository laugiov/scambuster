<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationRepositoryInterface;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\ScamType;
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
        /** @var array<Conversation> */
        return $this->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andWhere('c.tsLast < :threshold')
            ->setParameter('status', ConversationStatus::OPEN)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, int> persona_code => open conv count
     */
    public function countOpenByPersonaForScamType(ScamType $scamType): array
    {
        /** @var list<array{personaCode: string, cnt: string}> $rows */
        $rows = $this->createQueryBuilder('conv')
            ->select('p.personaCode AS personaCode, COUNT(conv.convId) AS cnt')
            ->innerJoin('conv.persona', 'p')
            ->where('conv.scamType = :scamType')
            ->andWhere('conv.status = :status')
            ->andWhere('conv.deletedAt IS NULL')
            ->setParameter('scamType', $scamType)
            ->setParameter('status', ConversationStatus::OPEN)
            ->groupBy('p.personaCode')
            ->getQuery()
            ->getArrayResult();

        $result = [];

        foreach ($rows as $row) {
            $result[$row['personaCode']] = (int) $row['cnt'];
        }

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Communication\ObservedIoc;
use App\Domain\Communication\ObservedIocRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<ObservedIoc> */
class ObservedIocRepository extends ServiceEntityRepository implements ObservedIocRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ObservedIoc::class);
    }

    public function findById(Uuid $obsId): ?ObservedIoc
    {
        return $this->find($obsId);
    }

    /** @return array<ObservedIoc> */
    public function findByMessage(Uuid $msgId): array
    {
        /** @var array<ObservedIoc> */
        return $this->createQueryBuilder('oi')
            ->where('oi.message = :msgId')
            ->setParameter('msgId', $msgId)
            ->orderBy('oi.tsObserved', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return array<ObservedIoc> */
    public function findByIndicator(Uuid $indicatorId): array
    {
        /** @var array<ObservedIoc> */
        return $this->createQueryBuilder('oi')
            ->where('oi.indicatorId = :indicatorId')
            ->setParameter('indicatorId', $indicatorId)
            ->orderBy('oi.tsObserved', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return array<ObservedIoc> */
    public function findByConversation(Uuid $convId): array
    {
        /** @var array<ObservedIoc> */
        return $this->createQueryBuilder('oi')
            ->join('oi.message', 'm')
            ->where('m.conversation = :convId')
            ->setParameter('convId', $convId)
            ->orderBy('oi.tsObserved', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Communication\TtpObservation;
use App\Domain\Communication\TtpObservationRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineTtpObservationRepository implements TtpObservationRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function save(TtpObservation $observation): void
    {
        $this->em->persist($observation);
        $this->em->flush();
    }

    public function findByMessageId(string $msgId): array
    {
        /** @var list<TtpObservation> $observations */
        $observations = $this->em->createQuery(
            'SELECT o FROM ' . TtpObservation::class . ' o WHERE o.message = :msgId ORDER BY o.createdAt ASC, o.obsId ASC'
        )
            ->setParameter('msgId', $msgId)
            ->getResult();

        return $observations;
    }

    public function findByConversationId(string $convId): array
    {
        /** @var list<TtpObservation> $observations */
        $observations = $this->em->createQuery(
            'SELECT o FROM ' . TtpObservation::class . ' o WHERE o.conversation = :convId ORDER BY o.createdAt ASC, o.obsId ASC'
        )
            ->setParameter('convId', $convId)
            ->getResult();

        return $observations;
    }
}

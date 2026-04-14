<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Direction;
use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Queries incoming messages with headers for header IOC migration.
 */
final readonly class HeaderIocMigrationService
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Find the "in" direction entity.
     */
    public function findInDirection(): ?Direction
    {
        return $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);
    }

    /**
     * Fetch all incoming messages that have headers (for header IOC extraction).
     *
     * @return list<Message>
     */
    public function findIncomingMessagesWithHeaders(Direction $inDirection): array
    {
        /** @var list<Message> $messages */
        $messages = $this->em->getRepository(Message::class)
            ->createQueryBuilder('m')
            ->where('m.headers IS NOT NULL')
            ->andWhere('m.deletedAt IS NULL')
            ->andWhere('m.direction = :inDir')
            ->setParameter('inDir', $inDirection)
            ->getQuery()
            ->getResult();

        return $messages;
    }
}

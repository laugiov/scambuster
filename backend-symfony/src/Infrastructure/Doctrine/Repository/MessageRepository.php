<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Communication\Message;
use App\Domain\Communication\MessageRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Message> */
class MessageRepository extends ServiceEntityRepository implements MessageRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    public function findById(Uuid $msgId): ?Message
    {
        return $this->find($msgId);
    }

    /** @return array<Message> */
    public function findByConversation(Uuid $convId, int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.conversation = :convId')
            ->setParameter('convId', $convId)
            ->orderBy('m.tsMsg', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function findByExternalMessageId(string $messageId): ?Message
    {
        return $this->findOneBy(['externalMessageId' => $messageId]);
    }

    public function countByConversation(Uuid $convId): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.msgId)')
            ->where('m.conversation = :convId')
            ->setParameter('convId', $convId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

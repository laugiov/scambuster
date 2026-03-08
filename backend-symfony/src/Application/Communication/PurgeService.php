<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use Doctrine\ORM\EntityManagerInterface;

class PurgeService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Soft delete outbound conversations older than 2 years.
     */
    public function softDeleteOldOutboundConversations(): int
    {
        $dateLimit = (new \DateTimeImmutable('-2 years'))->setTime(0, 0);
        $qb = $this->em->createQueryBuilder();
        $qb->select('c')
            ->from(Conversation::class, 'c')
            ->where('c.status = :status')
            ->andWhere('c.tsLast < :dateLimit')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('status', ConversationStatus::CLOSED)
            ->setParameter('dateLimit', $dateLimit);
        $convs = $qb->getQuery()->getResult();
        $count = 0;

        foreach ($convs as $conv) {
            $reflection = new \ReflectionObject($conv);
            $prop = $reflection->getProperty('deletedAt');
            $prop->setAccessible(true);
            $prop->setValue($conv, new \DateTimeImmutable());
            $count++;
        }
        $this->em->flush();

        return $count;
    }

    /**
     * Hard delete inbound conversations older than 5 years.
     */
    public function hardDeleteOldInboundConversations(): int
    {
        $dateLimit = (new \DateTimeImmutable('-5 years'))->setTime(0, 0);
        $qb = $this->em->createQueryBuilder();
        $qb->select('c')
            ->from(Conversation::class, 'c')
            ->where('c.tsLast < :dateLimit')
            ->andWhere('c.deletedAt IS NOT NULL')
            ->setParameter('dateLimit', $dateLimit);
        $convs = $qb->getQuery()->getResult();
        $count = count($convs);

        foreach ($convs as $conv) {
            $this->em->remove($conv);
        }
        $this->em->flush();

        return $count;
    }
}

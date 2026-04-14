<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use Doctrine\ORM\EntityManagerInterface;

class PurgeService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Soft delete closed conversations older than 6 months.
     *
     * Aligned with GDPR retention policy (constitution + DPIA):
     * content retention = 6 months max.
     */
    public function softDeleteOldOutboundConversations(): int
    {
        $dateLimit = (new \DateTimeImmutable('-6 months'))->setTime(0, 0);
        $qb = $this->em->createQueryBuilder();
        $qb->select('c')
            ->from(Conversation::class, 'c')
            ->where('c.status = :status')
            ->andWhere('c.tsLast < :dateLimit')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('status', ConversationStatus::CLOSED)
            ->setParameter('dateLimit', $dateLimit);
        /** @var Conversation[] $convs */
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
     * Hard delete soft-deleted conversations older than 12 months.
     *
     * Aligned with GDPR retention policy (constitution + DPIA):
     * audit metadata retention = 12 months max.
     */
    public function hardDeleteOldInboundConversations(): int
    {
        $dateLimit = (new \DateTimeImmutable('-12 months'))->setTime(0, 0);
        $qb = $this->em->createQueryBuilder();
        $qb->select('c')
            ->from(Conversation::class, 'c')
            ->where('c.tsLast < :dateLimit')
            ->andWhere('c.deletedAt IS NOT NULL')
            ->setParameter('dateLimit', $dateLimit);
        /** @var Conversation[] $convs */
        $convs = $qb->getQuery()->getResult();
        $count = count($convs);

        foreach ($convs as $conv) {
            $this->em->remove($conv);
        }
        $this->em->flush();

        return $count;
    }
}

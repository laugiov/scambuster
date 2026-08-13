<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use Doctrine\DBAL\ArrayParameterType;
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
            $conv->softDelete(new \DateTimeImmutable());
            $count++;
        }
        $this->em->flush();

        return $count;
    }

    /**
     * Conversations eligible for permanent erasure: already soft-deleted and past
     * the 12-month threshold.
     *
     * Shared by the counting and the erasing paths so the two can never disagree
     * about what would be removed — a preview that does not describe the deletion
     * it precedes is worse than no preview at all.
     *
     * @return Conversation[]
     */
    private function erasureCandidates(): array
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

        return $convs;
    }

    /**
     * Hard delete soft-deleted conversations older than 12 months.
     *
     * Aligned with GDPR retention policy (constitution + DPIA):
     * audit metadata retention = 12 months max.
     *
     * With $dryRun the eligible conversations are counted and nothing is written,
     * so a caller can report the volume before anyone authorises the deletion.
     */
    public function hardDeleteOldInboundConversations(bool $dryRun = false): int
    {
        $convs = $this->erasureCandidates();
        $count = count($convs);

        if ($dryRun) {
            return $count;
        }

        foreach ($convs as $conv) {
            $this->em->remove($conv);
        }
        $this->em->flush();

        return $count;
    }

    /**
     * Messages that permanent erasure would remove through the conversation
     * foreign-key cascade.
     *
     * Content lives in messages, not in the conversation row, so this is the count
     * that actually matters to a data-protection reviewer. Call it before erasing:
     * afterwards there is nothing left to count.
     */
    public function countMessagesPendingErasure(): int
    {
        $convs = $this->erasureCandidates();

        if ($convs === []) {
            return 0;
        }

        $ids = array_map(static fn (Conversation $c): string => $c->getConvId(), $convs);

        $raw = $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM message WHERE conv_id IN (?)',
            [$ids],
            [ArrayParameterType::STRING]
        )->fetchOne();

        return is_numeric($raw) ? (int) $raw : 0;
    }
}

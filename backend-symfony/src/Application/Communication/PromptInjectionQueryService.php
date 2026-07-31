<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Queries inbound messages for prompt injection analysis and persists results.
 */
final readonly class PromptInjectionQueryService
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<Message>
     */
    public function findMessagesForAnalysis(?string $conversationId, bool $force, int $limit): array
    {
        $qb = $this->em->getRepository(Message::class)->createQueryBuilder('m')
            ->join('m.direction', 'd')
            ->where("d.code = 'in'")
            ->orderBy('m.tsMsg', 'ASC');

        if ($conversationId !== null) {
            $qb->join('m.conversation', 'c')
                ->andWhere('c.convId = :convId')
                ->setParameter('convId', $conversationId);
        }

        if (!$force) {
            $qb->andWhere('m.injectionAnalysis IS NULL');
        }

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        /** @var list<Message> $messages */
        $messages = $qb->getQuery()->getResult();

        return $messages;
    }

    public function flush(): void
    {
        $this->em->flush();
    }
}

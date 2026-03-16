<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationChannel;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;

class ConversationHandler
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function createConversation(
        Channel $channel,
        ScamType $scamType,
        MailAccount $account,
        ConversationStatus $status,
        int $scoreRisk,
        \DateTimeImmutable $tsFirst,
        \DateTimeImmutable $tsLast,
        string $stixId
    ): Conversation {
        $conv = new Conversation(
            uuid_create(UUID_TYPE_RANDOM),
            $channel,
            $scamType,
            $account,
            $status,
            $scoreRisk,
            $tsFirst,
            $tsLast,
            $stixId,
            null,
            null
        );
        $this->em->persist($conv);
        $this->em->flush();

        return $conv;
    }

    public function getConversation(string $convId): ?Conversation
    {
        return $this->em->getRepository(Conversation::class)->find($convId);
    }

    public function deleteConversation(string $convId): bool
    {
        $conv = $this->em->getRepository(Conversation::class)->find($convId);

        if (!$conv) {
            return false;
        }
        $this->em->remove($conv);
        $this->em->flush();

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function patchConversation(string $convId, array $data): Conversation|null|false
    {
        $conv = $this->em->getRepository(Conversation::class)->find($convId);

        if (!$conv) {
            return null;
        }

        $updated = false;

        if (array_key_exists('status', $data)) {
            $status = ConversationStatus::tryFrom($data['status']);

            if (!$status) {
                throw new \RuntimeException('Invalid status');
            }
            $conv->setStatus($status);
            $updated = true;
        }

        if (array_key_exists('score_risk', $data)) {
            /** @var int|string $scoreRisk */
            $scoreRisk = $data['score_risk'];
            $conv->setScoreRisk((int) $scoreRisk);
            $updated = true;
        }

        if (array_key_exists('ts_last', $data)) {
            $conv->setTsLast(new \DateTimeImmutable($data['ts_last']));
            $updated = true;
        }

        if (array_key_exists('stix_id', $data)) {
            $conv->setStixId($data['stix_id']);
            $updated = true;
        }

        if (array_key_exists('scam_type_id', $data)) {
            /** @var int|string $scamTypeId */
            $scamTypeId = $data['scam_type_id'];
            $scamType = $this->em->getRepository(ScamType::class)->find((int) $scamTypeId);

            if (!$scamType) {
                throw new \RuntimeException('Invalid scam_type_id');
            }
            $conv->setScamType($scamType);
            $updated = true;
        }

        if ($updated) {
            $this->em->flush();

            return $conv;
        }

        return false;
    }

    public function addChannelToConversation(string $convId, Channel $channel): bool
    {
        $conv = $this->em->getRepository(Conversation::class)->find($convId);

        if (!$conv) {
            return false;
        }
        $existing = $this->em->getRepository(ConversationChannel::class)
            ->findOneBy(['conversation' => $conv, 'channel' => $channel]);

        if ($existing) {
            return true;
        }
        $link = new ConversationChannel($conv, $channel, new \DateTimeImmutable());
        $this->em->persist($link);
        $this->em->flush();

        return true;
    }

    public function getChannel(string $channelId): ?Channel
    {
        return $this->em->getRepository(Channel::class)->find($channelId);
    }

    public function getScamType(string $scamTypeId): ?ScamType
    {
        return $this->em->getRepository(ScamType::class)->find($scamTypeId);
    }

    public function getMailAccount(string $accountId): ?MailAccount
    {
        return $this->em->getRepository(MailAccount::class)->find($accountId);
    }

    /** @return array<int, ConversationChannel> */
    public function getConversationChannels(Conversation $conv): array
    {
        return $this->em->getRepository(ConversationChannel::class)->findBy(['conversation' => $conv]);
    }

    /** @return array<int, Conversation> */
    public function getFilteredConversations(int $page, int $limit, ?string $status = null, ?string $from = null, ?string $to = null): array
    {
        $offset = ($page - 1) * $limit;
        $qb = $this->em->createQueryBuilder();
        $qb->select('c')
            ->from(Conversation::class, 'c')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->orderBy('c.tsLast', 'DESC');

        if ($status) {
            $qb->andWhere('c.status = :status')
                ->setParameter('status', $status);
        }

        if ($from) {
            $qb->andWhere('c.tsFirst >= :from')
                ->setParameter('from', new \DateTimeImmutable($from));
        }

        if ($to) {
            $qb->andWhere('c.tsLast <= :to')
                ->setParameter('to', new \DateTimeImmutable($to));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get paginated messages for a conversation.
     *
     * @return array{total: int, messages: array<int, mixed>}
     */
    public function getConversationMessages(string $convId, int $page, int $limit): array
    {
        $conv = $this->em->getRepository(Conversation::class)->find($convId);

        if (!$conv || $conv->getDeletedAt() !== null) {
            return ['total' => 0, 'messages' => []];
        }

        // Total count
        $totalQb = $this->em->createQueryBuilder();
        $totalQb->select('COUNT(m.msgId)')
            ->from('App\\Domain\\Communication\\Message', 'm')
            ->where('m.conversation = :conv')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('conv', $conv);
        $total = (int)$totalQb->getQuery()->getSingleScalarResult();

        // Paginated messages
        $offset = ($page - 1) * $limit;
        $repo = $this->em->getRepository('App\\Domain\\Communication\\Message');
        $qb = $repo->createQueryBuilder('m')
            ->where('m.conversation = :conv')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('conv', $conv)
            ->orderBy('m.tsMsg', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $messages = $qb->getQuery()->getResult();

        return ['total' => $total, 'messages' => $messages];
    }
}

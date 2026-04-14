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
            new \DateTimeImmutable(),
            new \DateTimeImmutable()
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

        if ($conv === null) {
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

        if ($conv === null) {
            return null;
        }

        $updated = false;

        if (array_key_exists('status', $data)) {
            /** @var string $statusValue */
            $statusValue = $data['status'];
            $status = ConversationStatus::tryFrom($statusValue);

            if (!$status instanceof \App\Domain\Communication\ConversationStatus) {
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
            /** @var string $tsLastStr */
            $tsLastStr = $data['ts_last'];
            $conv->setTsLast(new \DateTimeImmutable($tsLastStr));
            $updated = true;
        }

        if (array_key_exists('stix_id', $data)) {
            /** @var string $stixIdVal */
            $stixIdVal = $data['stix_id'];
            $conv->setStixId($stixIdVal);
            $updated = true;
        }

        if (array_key_exists('scam_type_id', $data)) {
            /** @var int|string $scamTypeId */
            $scamTypeId = $data['scam_type_id'];
            $scamType = $this->em->getRepository(ScamType::class)->find((int) $scamTypeId);

            if ($scamType === null) {
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

        if ($conv === null) {
            return false;
        }
        $existing = $this->em->getRepository(ConversationChannel::class)
            ->findOneBy(['conversation' => $conv, 'channel' => $channel]);

        if ($existing !== null) {
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

        /** @var array<int, Conversation> */
        return $qb->getQuery()->getResult();
    }

    /**
     * @param list<string> $convIds
     *
     * @return array<string, int> conv_id => message count
     */
    public function getMessageCountsForConversations(array $convIds): array
    {
        if ($convIds === []) {
            return [];
        }

        /** @var list<array{conv_id: string, cnt: string}> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT conv_id, COUNT(*) as cnt FROM message WHERE conv_id IN (?) GROUP BY conv_id',
            [$convIds],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['conv_id']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * @param list<string> $convIds
     *
     * @return array<string, int> conv_id => ioc count
     */
    public function getIocCountsForConversations(array $convIds): array
    {
        if ($convIds === []) {
            return [];
        }

        /** @var list<array{conv_id: string, cnt: string}> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT m.conv_id, COUNT(DISTINCT oi.obs_id) as cnt FROM observed_ioc oi JOIN message m ON oi.msg_id = m.msg_id JOIN indicator i ON oi.indicator_id = i.indicator_id WHERE m.conv_id IN (?) AND i.type NOT IN ('dmarc_result', 'spf_result', 'dkim_result') AND i.value NOT LIKE '%@scambuster.local' GROUP BY m.conv_id",
            [$convIds],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['conv_id']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Get paginated messages for a conversation.
     *
     * @return array{total: int, messages: mixed}
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
            ->from(\App\Domain\Communication\Message::class, 'm')
            ->where('m.conversation = :conv')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('conv', $conv);
        $total = (int)$totalQb->getQuery()->getSingleScalarResult();

        // Paginated messages
        $offset = ($page - 1) * $limit;
        $repo = $this->em->getRepository(\App\Domain\Communication\Message::class);
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

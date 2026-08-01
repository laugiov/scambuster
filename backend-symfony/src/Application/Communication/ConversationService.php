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

class ConversationService implements ConversationServiceInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function createConversation(
        Channel $primaryChannel,
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
            $primaryChannel,
            $scamType,
            $account,
            $status,
            $scoreRisk,
            $tsFirst,
            $tsLast,
            $stixId
        );
        $this->em->persist($conv);
        $this->em->flush();

        return $conv;
    }

    public function addChannelToConversation(Conversation $conversation, Channel $channel, \DateTimeImmutable $tsFirstChannel): void
    {
        $link = new ConversationChannel($conversation, $channel, $tsFirstChannel);
        $this->em->persist($link);
        $this->em->flush();
    }

    public function changeConversationStatus(Conversation $conversation, ConversationStatus $status): void
    {
        $managed = $this->em->find(Conversation::class, $conversation->getConvId());

        if ($managed === null) {
            throw new ConversationNotFoundException('Conversation not found');
        }
        $reflection = new \ReflectionObject($conversation);
        $prop = $reflection->getProperty('status');
        $prop->setAccessible(true);
        $prop->setValue($conversation, $status);
        $this->em->flush();
    }

    /**
     * Soft delete a conversation by setting its deletedAt field.
     */
    public function softDeleteConversation(Conversation $conversation, ?\DateTimeImmutable $deletedAt = null): void
    {
        $managed = $this->em->find(Conversation::class, $conversation->getConvId());

        if ($managed === null) {
            throw new ConversationNotFoundException('Conversation not found');
        }
        $reflection = new \ReflectionObject($managed);
        $prop = $reflection->getProperty('deletedAt');
        $prop->setAccessible(true);
        $prop->setValue($managed, $deletedAt ?? new \DateTimeImmutable());
        $this->em->flush();
    }

    /**
     * Find a conversation by its unique identifier.
     *
     * @throws ConversationNotFoundException
     */
    public function findConversationById(string $convId): Conversation
    {
        $conv = $this->em->getRepository(Conversation::class)->find($convId);

        if ($conv === null) {
            throw new ConversationNotFoundException('Conversation not found');
        }

        return $conv;
    }

    /**
     * Find a conversation by its unique STIX identifier.
     *
     * @throws ConversationNotFoundException
     */
    public function findConversationByStixId(string $stixId): Conversation
    {
        $conv = $this->em->getRepository(Conversation::class)->findOneBy(['stixId' => $stixId]);

        if ($conv === null) {
            throw new ConversationNotFoundException('Conversation not found');
        }

        return $conv;
    }

    /**
     * Update one or more fields of a conversation.
     *
     * @param array<string, mixed> $fields Associative array of field => value
     *
     * @throws ConversationNotFoundException
     */
    public function updateConversationFields(string $convId, array $fields): void
    {
        $conv = $this->em->getRepository(Conversation::class)->find($convId);

        if ($conv === null) {
            throw new ConversationNotFoundException('Conversation not found');
        }
        $allowedFields = [
            'scoreRisk', 'tsFirst', 'tsLast', 'status', 'primaryChannel', 'scamType', 'account', 'stixId'
        ];
        $reflection = new \ReflectionObject($conv);

        foreach ($fields as $field => $value) {
            if (!in_array($field, $allowedFields, true)) {
                continue;
            }
            $prop = $reflection->getProperty($field);
            $prop->setAccessible(true);
            $prop->setValue($conv, $value);
        }
        $this->em->flush();
    }
}

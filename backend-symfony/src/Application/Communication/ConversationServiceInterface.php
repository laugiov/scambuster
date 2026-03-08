<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\ScamType;

interface ConversationServiceInterface
{
    /**
     * Crée une nouvelle conversation.
     */
    public function createConversation(
        Channel $primaryChannel,
        ScamType $scamType,
        MailAccount $account,
        ConversationStatus $status,
        int $scoreRisk,
        \DateTimeImmutable $tsFirst,
        \DateTimeImmutable $tsLast,
        string $stixId
    ): Conversation;

    /**
     * Ajoute un canal à une conversation existante (multi-canal).
     */
    public function addChannelToConversation(Conversation $conversation, Channel $channel, \DateTimeImmutable $tsFirstChannel): void;

    /**
     * Change le statut d'une conversation.
     */
    public function changeConversationStatus(Conversation $conversation, ConversationStatus $status): void;
}

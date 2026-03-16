<?php

declare(strict_types=1);

namespace App\Domain\Communication;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'conversation_channel')]
#[ORM\IdClass(ConversationChannelId::class)] // @phpstan-ignore-line
class ConversationChannel
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(name: 'conv_id', referencedColumnName: 'conv_id', nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Channel::class)]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'channel_id', nullable: false)]
    private Channel $channel;

    #[ORM\Column(name: 'ts_first_channel', type: 'datetime_immutable')]
    private \DateTimeImmutable $tsFirstChannel;

    public function __construct(Conversation $conversation, Channel $channel, \DateTimeImmutable $tsFirstChannel)
    {
        $this->conversation = $conversation;
        $this->channel = $channel;
        $this->tsFirstChannel = $tsFirstChannel;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getChannel(): Channel
    {
        return $this->channel;
    }

    public function getTsFirstChannel(): \DateTimeImmutable
    {
        return $this->tsFirstChannel;
    }
}

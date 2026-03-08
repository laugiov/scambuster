<?php

declare(strict_types=1);

namespace App\Domain\Communication;

class ConversationChannelId
{
    public string $conversation;
    public int $channel;

    public function __construct(string $conversation = null, int $channel = null)
    {
        $this->conversation = $conversation;
        $this->channel = $channel;
    }
}

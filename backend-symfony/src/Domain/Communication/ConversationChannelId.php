<?php

declare(strict_types=1);

namespace App\Domain\Communication;

class ConversationChannelId
{
    public function __construct(public ?string $conversation = null, public ?int $channel = null)
    {
    }
}

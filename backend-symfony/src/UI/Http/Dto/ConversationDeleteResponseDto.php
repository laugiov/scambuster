<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

final class ConversationDeleteResponseDto
{
    public function __construct(
        public string $message
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
        ];
    }
}

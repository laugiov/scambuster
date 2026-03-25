<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

final class ConversationCreateResponseDto
{
    public function __construct(
        public string $conv_id,
        public string $status
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'conv_id' => $this->conv_id,
            'status' => $this->status,
        ];
    }
}

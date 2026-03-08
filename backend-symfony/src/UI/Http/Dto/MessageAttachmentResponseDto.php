<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

final class MessageAttachmentResponseDto
{
    public function __construct(
        public string $attachment_id
    ) {
    }

    public function toArray(): array
    {
        return [
            'attachment_id' => $this->attachment_id,
        ];
    }
}

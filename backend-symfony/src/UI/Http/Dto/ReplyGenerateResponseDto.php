<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

final class ReplyGenerateResponseDto
{
    public function __construct(
        public string $msg_id,
        public string $conv_id,
        public string $to,
        public string $subject,
        public array $draft,
        public array $meta
    ) {
    }

    public function toArray(): array
    {
        return [
            'msg_id' => $this->msg_id,
            'conv_id' => $this->conv_id,
            'to' => $this->to,
            'subject' => $this->subject,
            'draft' => $this->draft,
            'meta' => $this->meta,
        ];
    }
}

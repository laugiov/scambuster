<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

final class ReplyDetailResponseDto
{
    public function __construct(
        public string $msg_id,
        public string $send_status,
        public string $to,
        public string $subject,
        public array $draft,
        public array $meta = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'msg_id' => $this->msg_id,
            'send_status' => $this->send_status,
            'to' => $this->to,
            'subject' => $this->subject,
            'draft' => $this->draft,
            'meta' => $this->meta,
        ];
    }
}

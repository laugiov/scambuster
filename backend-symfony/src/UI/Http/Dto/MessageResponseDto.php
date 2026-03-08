<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

final class MessageResponseDto
{
    public function __construct(
        public string $msg_id,
        public string $body_text,
        public string $direction,
        public string $ts_msg,
        public array $headers = [],
        public ?string $deleted_at = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'msg_id' => $this->msg_id,
            'body_text' => $this->body_text,
            'direction' => $this->direction,
            'ts_msg' => $this->ts_msg,
            'headers' => $this->headers,
            'deleted_at' => $this->deleted_at,
        ];
    }
}

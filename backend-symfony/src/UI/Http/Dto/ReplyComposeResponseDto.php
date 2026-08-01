<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

final class ReplyComposeResponseDto
{
    /** @param array<string, mixed> $checks */
    public function __construct(
        public string $msg_id,
        public string $to,
        public string $from,
        public string $subject,
        public ?string $in_reply_to,
        public ?string $references,
        public ?string $thread_id,
        public bool $safe_to_send,
        public bool $rate_limited,
        public array $checks
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'msg_id' => $this->msg_id,
            'to' => $this->to,
            'from' => $this->from,
            'subject' => $this->subject,
            'in_reply_to' => $this->in_reply_to,
            'references' => $this->references,
            'thread_id' => $this->thread_id,
            'safe_to_send' => $this->safe_to_send,
            'rate_limited' => $this->rate_limited,
            'checks' => $this->checks,
        ];
    }
}

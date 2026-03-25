<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

final class MessageCreateResponseDto
{
    public function __construct(
        public string $msg_id,
        public string $conv_id,
        public string $ts_msg
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'msg_id' => $this->msg_id,
            'conv_id' => $this->conv_id,
            'ts_msg' => $this->ts_msg,
        ];
    }
}

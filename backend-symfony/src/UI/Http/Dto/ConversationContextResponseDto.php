<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

final class ConversationContextResponseDto
{
    public function __construct(
        public string $conv_id,
        public string $status,
        public array $scam_type,
        public string $persona,
        public array $cadence,
        public array $last_messages,
        public ?string $sender_history_summary = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'conv_id' => $this->conv_id,
            'status' => $this->status,
            'scam_type' => $this->scam_type,
            'persona' => $this->persona,
            'cadence' => $this->cadence,
            'last_messages' => $this->last_messages,
            'sender_history_summary' => $this->sender_history_summary,
        ];
    }
}

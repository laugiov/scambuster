<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

final class ConversationDetailResponseDto
{
    /** @param array<int, array<string, mixed>> $channels */
    public function __construct(
        public string $conv_id,
        public string $status,
        public int $score_risk,
        public string $ts_first,
        public string $ts_last,
        public string $stix_id,
        public array $channels
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'conv_id' => $this->conv_id,
            'status' => $this->status,
            'score_risk' => $this->score_risk,
            'ts_first' => $this->ts_first,
            'ts_last' => $this->ts_last,
            'stix_id' => $this->stix_id,
            'channels' => $this->channels,
        ];
    }
}

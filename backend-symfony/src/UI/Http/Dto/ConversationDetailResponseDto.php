<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

final class ConversationDetailResponseDto
{
    /**
     * @param array<int, array<string, mixed>>                        $channels
     * @param array<int, array{code: string, confidence: float}>|null $secondary_scam_types
     */
    public function __construct(
        public string $conv_id,
        public string $status,
        public int $score_risk,
        public string $ts_first,
        public string $ts_last,
        public string $stix_id,
        public array $channels,
        public ?array $secondary_scam_types = null,
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
            'secondary_scam_types' => $this->secondary_scam_types,
        ];
    }
}

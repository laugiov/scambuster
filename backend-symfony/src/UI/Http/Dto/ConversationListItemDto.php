<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

final class ConversationListItemDto
{
    /** @param array<int, array{code: string, confidence: float}>|null $secondary_scam_types */
    public function __construct(
        public string $conv_id,
        public string $status,
        public int $score_risk,
        public string $ts_first,
        public string $ts_last,
        public string $stix_id,
        public ?string $persona = null,
        public ?string $scam_type = null,
        public int $turns = 0,
        public int $message_count = 0,
        public ?float $reward = null,
        public int $ioc_count = 0,
        public ?array $secondary_scam_types = null,
    ) {
    }

    /**
     * @return array<string, string|int|float|array<int, array{code: string, confidence: float}>|null>
     */
    public function toArray(): array
    {
        return [
            'conv_id' => $this->conv_id,
            'status' => $this->status,
            'score_risk' => $this->score_risk,
            'ts_first' => $this->ts_first,
            'ts_last' => $this->ts_last,
            'stix_id' => $this->stix_id,
            'persona' => $this->persona,
            'scam_type' => $this->scam_type,
            'turns' => $this->turns,
            'message_count' => $this->message_count,
            'reward' => $this->reward,
            'ioc_count' => $this->ioc_count,
            'secondary_scam_types' => $this->secondary_scam_types,
        ];
    }
}

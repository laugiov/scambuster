<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

/**
 * Response DTO for GET /api/v1/communication/message/{msgId}/risk
 *
 * Returns aggregated risk score and decision recommendation
 */
final class MessageRiskResponseDto
{
    public function __construct(
        public int $score_agg,
        public string $level,
        public string $reason,
        public bool $should_reply
    ) {
    }

    public function toArray(): array
    {
        return [
            'score_agg' => $this->score_agg,
            'level' => $this->level,
            'reason' => $this->reason,
            'should_reply' => $this->should_reply,
        ];
    }
}

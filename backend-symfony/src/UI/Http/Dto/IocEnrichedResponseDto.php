<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

/**
 * Response DTO for POST /api/v1/iocs/enriched
 *
 * Returns the created/updated IOC ID and calculated message risk
 */
final class IocEnrichedResponseDto
{
    /** @param array<string, mixed> $risk */
    public function __construct(
        public string $obs_id,
        public array $risk
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'obs_id' => $this->obs_id,
            'risk' => $this->risk,
        ];
    }
}

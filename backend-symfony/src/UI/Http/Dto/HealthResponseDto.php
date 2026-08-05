<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

/**
 * DTO for health check response
 */
final class HealthResponseDto
{
    public function __construct(
        public string $status
    ) {
    }
}

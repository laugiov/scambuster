<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

/**
 * DTO for protected endpoint response
 */
final class ProtectedEndpointResponseDto
{
    public function __construct(
        public string $message
    ) {
    }
}

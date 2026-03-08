<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

/**
 * DTO for the admin welcome message response
 */
final class AdminWelcomeResponseDto
{
    public function __construct(
        public string $message
    ) {
    }
}

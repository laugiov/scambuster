<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

/**
 * DTO for CSRF token response
 */
final class CsrfTokenResponseDto
{
    public function __construct(
        public string $csrf_token
    ) {
    }
}

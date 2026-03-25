<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

/**
 * DTO for the authenticated user's profile response
 */
final class MeResponseDto
{
    /** @param array<int, string> $roles */
    public function __construct(
        public ?string $id,
        public string $email,
        public array $roles
    ) {
    }
}

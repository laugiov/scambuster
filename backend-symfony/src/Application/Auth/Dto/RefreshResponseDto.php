<?php

declare(strict_types=1);

namespace App\Application\Auth\Dto;

final class RefreshResponseDto
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public int $expiresIn
    ) {
    }
}

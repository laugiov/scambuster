<?php

declare(strict_types=1);

namespace App\Application\Auth\Dto;

use Symfony\Component\Serializer\Annotation\SerializedName;

final class RefreshRequestDto
{
    public function __construct(
        #[SerializedName('refresh_token')]
        public string $refreshToken
    ) {
    }
}

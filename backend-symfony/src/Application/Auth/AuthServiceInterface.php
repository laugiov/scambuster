<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Application\Auth\Dto\LoginRequestDto;
use App\Application\Auth\Dto\LoginResponseDto;
use App\Application\Auth\Dto\RefreshRequestDto;

interface AuthServiceInterface
{
    public function login(LoginRequestDto $dto): LoginResponseDto;
    public function refresh(RefreshRequestDto $dto): LoginResponseDto;
    public function logout(string $refreshToken): void;
}

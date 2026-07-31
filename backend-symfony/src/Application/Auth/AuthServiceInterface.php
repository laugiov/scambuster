<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Application\Auth\Dto\LoginRequestDto;
use App\Application\Auth\Dto\LoginResponseDto;
use App\Application\Auth\Dto\RefreshRequestDto;
use App\Domain\User\User;

interface AuthServiceInterface
{
    public function login(LoginRequestDto $dto): LoginResponseDto;
    public function refresh(RefreshRequestDto $dto): LoginResponseDto;
    public function logout(string $refreshToken): void;

    /**
     * Mint a local session (JWT + rotating refresh token) for an already-authenticated
     * user, bypassing password verification. Used by alternative sign-in paths — e.g.
     * OIDC SSO — so every downstream consumer receives the same session shape.
     */
    public function issueSessionFor(User $user): LoginResponseDto;
}

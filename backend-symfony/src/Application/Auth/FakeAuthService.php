<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Application\Auth\Dto\LoginRequestDto;
use App\Application\Auth\Dto\LoginResponseDto;
use App\Application\Auth\Dto\RefreshRequestDto;
use App\Domain\User\User;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class FakeAuthService implements AuthServiceInterface
{
    /** @var array<string, int> */
    private static array $loginAttempts = [];
    /** @var array<int, string> */
    private static array $invalidatedRefreshTokens = [];

    public static function resetFakeState(): void
    {
        self::$loginAttempts = [];
        self::$invalidatedRefreshTokens = [];
    }

    public function login(LoginRequestDto $dto): LoginResponseDto
    {
        if ($dto->email !== 'user@example.com' || $dto->password !== 'Un1que$trongPassword2024') {
            self::$loginAttempts[$dto->email] = (self::$loginAttempts[$dto->email] ?? 0) + 1;

            if (self::$loginAttempts[$dto->email] > 5) {
                throw new AuthenticationException('Too many attempts');
            }

            throw new AuthenticationException('Invalid credentials.');
        }
        self::$loginAttempts[$dto->email] = 0;

        return new LoginResponseDto('fake-jwt', 'fake-refresh', 3600);
    }

    public function refresh(RefreshRequestDto $dto): LoginResponseDto
    {
        if ($dto->refreshToken === 'expired-refresh') {
            throw new AuthenticationException('Expired refresh token');
        }

        if (in_array($dto->refreshToken, self::$invalidatedRefreshTokens, true)) {
            throw new AuthenticationException('Invalid refresh token');
        }

        if ($dto->refreshToken !== 'fake-refresh') {
            throw new AuthenticationException('Invalid refresh token');
        }

        return new LoginResponseDto('fake-jwt', 'fake-refresh', 3600);
    }

    public function logout(string $refreshToken): void
    {
        if (!in_array($refreshToken, self::$invalidatedRefreshTokens, true)) {
            self::$invalidatedRefreshTokens[] = $refreshToken;
        }
    }

    public function issueSessionFor(User $user): LoginResponseDto
    {
        return new LoginResponseDto('fake-jwt', 'fake-refresh', 3600);
    }
}

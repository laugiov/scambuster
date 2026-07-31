<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class TestCsrfTokenManager implements CsrfTokenManagerInterface
{
    public function getToken(string $tokenId): CsrfToken
    {
        return new CsrfToken($tokenId, 'valid_csrf_token');
    }

    public function refreshToken(string $tokenId): CsrfToken
    {
        return new CsrfToken($tokenId, 'valid_csrf_token');
    }

    public function isTokenValid(CsrfToken $token): bool
    {
        return $token->getValue() === 'valid_csrf_token';
    }

    public function removeToken(string $tokenId): ?string
    {
        return null;
    }
}

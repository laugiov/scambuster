<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class TestTokenAuthenticator extends AbstractAuthenticator
{
    public function supports(Request $request): ?bool
    {
        return $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): \Symfony\Component\Security\Http\Authenticator\Passport\Passport
    {
        $authHeader = $request->headers->get('Authorization', '');

        if (!$authHeader && $request->cookies->has('X-AUTH-TOKEN')) {
            $authHeader = 'Bearer ' . $request->cookies->get('X-AUTH-TOKEN');
        }

        if (!$authHeader && $request->request->has('jwt_token')) {
            $authHeader = 'Bearer ' . $request->request->get('jwt_token');
        }

        if (!$authHeader && $request->query->has('jwt_token')) {
            $authHeader = 'Bearer ' . $request->query->get('jwt_token');
        }

        if (preg_match('/^Bearer (.+)$/i', (string) $authHeader, $matches)) {
            $token = $matches[1];

            if ($token === 'fake-jwt') {
                return new SelfValidatingPassport(
                    new UserBadge('test-user')
                );
            }

            if ($token === 'fake-admin-jwt') {
                return new SelfValidatingPassport(
                    new UserBadge('test-admin')
                );
            }
        }

        throw new AuthenticationException('Invalid JWT Token');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?JsonResponse
    {
        return null; // continue to controller
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?JsonResponse
    {
        return new JsonResponse(['code' => 401, 'message' => $exception->getMessage()], 401);
    }
}

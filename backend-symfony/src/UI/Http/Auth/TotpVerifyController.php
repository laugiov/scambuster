<?php

declare(strict_types=1);

namespace App\UI\Http\Auth;

use App\Application\Auth\TotpVerifier;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\User;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/2fa/verify', name: 'api_2fa_verify', methods: ['POST'])]
#[IsGranted('ROLE_USER')]
#[OA\Post(
    path: '/api/v1/2fa/verify',
    summary: 'Verify TOTP code and enable two-factor authentication',
    security: [['Bearer' => []]],
    tags: ['Auth'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(type: 'object', required: ['code'], properties: [
            new OA\Property(property: 'code', type: 'string', pattern: '^\d{6}$', example: '123456', description: '6-digit TOTP code from authenticator app'),
        ])
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'TOTP verified and enabled',
            content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'message', type: 'string', example: 'TOTP enabled'),
                new OA\Property(property: 'enabled', type: 'boolean', example: true),
            ])
        ),
        new OA\Response(
            response: 400,
            description: 'Invalid or missing TOTP code / TOTP not configured',
            content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Invalid TOTP code'),
            ])
        ),
        new OA\Response(
            response: 401,
            description: 'Not authenticated',
            content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Not authenticated'),
            ])
        ),
        new OA\Response(
            response: 404,
            description: 'User not found',
            content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'message', type: 'string', example: 'User not found'),
            ])
        ),
    ]
)]
final readonly class TotpVerifyController
{
    public function __construct(
        private UserRepositoryInterface $userRepo,
        private TokenStorageInterface $tokenStorage,
        private TotpVerifier $totpVerifier,
    ) {
    }
    public function __invoke(Request $request): JsonResponse
    {
        $token = $this->tokenStorage->getToken();

        if (!$token instanceof \Symfony\Component\Security\Core\Authentication\Token\TokenInterface) {
            return new JsonResponse(['message' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent(), true) ?: [];
        $code = \is_string($payload['code'] ?? null) ? $payload['code'] : '';

        if ($code === '' || !preg_match('/^\d{6}$/', $code)) {
            return new JsonResponse(['message' => 'Invalid TOTP code'], Response::HTTP_BAD_REQUEST);
        }

        $userIdentifier = $token->getUserIdentifier();
        $user = $this->userRepo->findByEmail($userIdentifier);

        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $secret = $user->getTotpSecret();

        if ($secret === null) {
            return new JsonResponse(['message' => 'TOTP not configured'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->totpVerifier->verify($secret, $code)) {
            return new JsonResponse(['message' => 'Invalid TOTP code'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'message' => 'TOTP enabled',
            'enabled' => true,
        ], Response::HTTP_OK);
    }
}

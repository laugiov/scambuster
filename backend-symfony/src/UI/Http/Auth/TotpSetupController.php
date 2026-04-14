<?php

declare(strict_types=1);

namespace App\UI\Http\Auth;

use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\User;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/2fa/setup', name: 'api_2fa_setup', methods: ['POST'])]
#[IsGranted('ROLE_USER')]
#[OA\Post(
    path: '/api/v1/2fa/setup',
    summary: 'Initialize TOTP two-factor authentication setup',
    security: [['Bearer' => []]],
    tags: ['Auth'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'TOTP secret generated — scan QR code with authenticator app',
            content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'secret', type: 'string', example: 'JBSWY3DPEHPK3PXP'),
                new OA\Property(property: 'qr_uri', type: 'string', example: 'otpauth://totp/ScamBuster:user@example.com?secret=JBSWY3DPEHPK3PXP&issuer=ScamBuster&digits=6&period=30'),
                new OA\Property(property: 'message', type: 'string', example: 'Scan QR code with authenticator app'),
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
final readonly class TotpSetupController
{
    public function __construct(
        private UserRepositoryInterface $userRepo,
        private TokenStorageInterface $tokenStorage,
    ) {
    }
    public function __invoke(): JsonResponse
    {
        $token = $this->tokenStorage->getToken();

        if (!$token instanceof \Symfony\Component\Security\Core\Authentication\Token\TokenInterface) {
            return new JsonResponse(['message' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $userIdentifier = $token->getUserIdentifier();
        $user = $this->userRepo->findByEmail($userIdentifier);

        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $secretBytes = random_bytes(20);
        $secret = $this->base32Encode($secretBytes);

        $user->setTotpSecret($secret);
        $this->userRepo->save($user);

        $email = $user->getEmail();
        $qrUri = sprintf(
            'otpauth://totp/ScamBuster:%s?secret=%s&issuer=ScamBuster&digits=6&period=30',
            urlencode($email),
            $secret
        );

        return new JsonResponse([
            'secret'  => $secret,
            'qr_uri'  => $qrUri,
            'message' => 'Scan QR code with authenticator app',
        ], Response::HTTP_OK);
    }
    private function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';

        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $result = '';
        $chunks = str_split($binary, 5);

        foreach ($chunks as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $result .= $alphabet[bindec($chunk)];
        }

        return $result;
    }
}

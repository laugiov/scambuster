<?php

declare(strict_types=1);

namespace App\UI\Http\Auth;

use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/2fa/verify', name: 'api_2fa_verify', methods: ['POST'])]
#[IsGranted('ROLE_USER')]
final readonly class TotpVerifyController
{
    public function __construct(
        private UserRepositoryInterface $userRepo,
        private TokenStorageInterface $tokenStorage,
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

        if (!$this->verifyTotp($secret, $code)) {
            return new JsonResponse(['message' => 'Invalid TOTP code'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'message' => 'TOTP enabled',
            'enabled' => true,
        ], Response::HTTP_OK);
    }
    private function verifyTotp(string $base32Secret, string $code): bool
    {
        $secret = $this->base32Decode($base32Secret);
        $currentCounter = (int) floor(time() / 30);

        // Allow +/- 1 window for clock drift
        for ($i = -1; $i <= 1; $i++) {
            $counter = $currentCounter + $i;
            $generated = $this->generateTotpCode($secret, $counter);

            if (hash_equals($generated, $code)) {
                return true;
            }
        }

        return false;
    }
    private function generateTotpCode(string $secret, int $counter): string
    {
        $counterBytes = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $counterBytes, $secret, true);

        $offset = ord($hash[19]) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % 1000000;

        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }
    private function base32Decode(string $base32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32 = strtoupper(rtrim($base32, '='));
        $binary = '';

        foreach (str_split($base32) as $char) {
            $index = strpos($alphabet, $char);

            if ($index === false) {
                continue;
            }
            $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $result = '';
        $chunks = str_split($binary, 8);

        foreach ($chunks as $chunk) {
            if (\strlen($chunk) < 8) {
                break;
            }
            $result .= \chr((int) bindec($chunk));
        }

        return $result;
    }
}

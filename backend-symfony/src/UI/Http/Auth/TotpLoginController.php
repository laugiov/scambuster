<?php

declare(strict_types=1);

namespace App\UI\Http\Auth;

use App\Application\Audit\AuditLogger;
use App\Application\Auth\AuthServiceInterface;
use App\Application\Auth\Dto\LoginRequestDto;
use App\Domain\Audit\AuditEventType;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\User;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/auth/2fa/login', name: 'api_auth_2fa_login', methods: ['POST'])]
final readonly class TotpLoginController
{
    public function __construct(
        private AuthServiceInterface $handler,
        private AuditLogger $auditLogger,
        private UserRepositoryInterface $userRepo,
        private ValidatorInterface $validator,
        // Spec 065e — replaces the custom RFC 6238 implementation with scheb
        private ?TotpAuthenticatorInterface $totpAuthenticator = null,
        // Spec 066 — rate limiting on 2FA endpoint (reuses login_ip limiter)
        private ?RateLimiterFactory $loginIpLimiter = null,
        // Spec 066 — TOTP replay protection via cache
        private ?\Psr\Cache\CacheItemPoolInterface $totpReplayCache = null,
    ) {
    }
    public function __invoke(Request $request): JsonResponse
    {
        // Spec 066 — rate limit check (same limiter as login endpoint)
        if ($this->loginIpLimiter instanceof RateLimiterFactory) {
            $limiter = $this->loginIpLimiter->create($request->getClientIp() ?? 'unknown');
            $limit = $limiter->consume(1);

            if (!$limit->isAccepted()) {
                $this->auditLogger->log(
                    eventType: AuditEventType::RATE_LIMIT_EXCEEDED,
                    actorId: 'unknown',
                    action: '2fa_login',
                    outcome: 'blocked',
                    details: ['limiter' => 'login_ip', 'ip' => $request->getClientIp()],
                    ipAddress: $request->getClientIp()
                );

                $seconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());

                return new JsonResponse(
                    ['retry_after' => $seconds],
                    Response::HTTP_TOO_MANY_REQUESTS
                );
            }
        }

        $payload = json_decode($request->getContent(), true);

        if (!\is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        /** @var string $email */
        $email = $payload['email'] ?? '';
        /** @var string $password */
        $password = $payload['password'] ?? '';
        $code = $payload['code'] ?? '';

        if (!\is_string($code) || !preg_match('/^\d{6}$/', $code)) {
            return new JsonResponse(['message' => 'Invalid TOTP code format'], Response::HTTP_BAD_REQUEST);
        }

        $dto = new LoginRequestDto($email, $password);
        $errors = $this->validator->validate($dto);

        if (\count($errors) > 0) {
            return new JsonResponse(['message' => (string) $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $response = $this->handler->login($dto);
        } catch (AuthenticationException $e) {
            $this->auditLogger->log(
                eventType: AuditEventType::AUTH_FAILURE,
                actorId: $email,
                action: '2fa_login',
                outcome: 'failure',
                details: ['reason' => $e->getMessage()],
                ipAddress: $request->getClientIp()
            );

            return new JsonResponse(['message' => strtolower($e->getMessage())], Response::HTTP_UNAUTHORIZED);
        }

        // Verify TOTP code
        $user = $this->userRepo->findByEmail($email);

        if (!$user instanceof User || !$user->isTotpEnabled()) {
            return new JsonResponse(['message' => 'TOTP not configured for this account'], Response::HTTP_BAD_REQUEST);
        }

        // Spec 066 — TOTP replay protection: reject codes already used within 90s window
        if ($this->totpReplayCache instanceof \Psr\Cache\CacheItemPoolInterface) {
            $replayCacheKey = 'totp_used_' . md5($email . ':' . $code);
            $cacheItem = $this->totpReplayCache->getItem($replayCacheKey);

            if ($cacheItem->isHit()) {
                $this->auditLogger->log(
                    eventType: AuditEventType::AUTH_FAILURE,
                    actorId: $email,
                    action: '2fa_login',
                    outcome: 'totp_replay',
                    ipAddress: $request->getClientIp()
                );

                return new JsonResponse(['message' => 'TOTP code already used'], Response::HTTP_UNAUTHORIZED);
            }
        }

        // Spec 065e — delegate verification to scheb/2fa-bundle if available,
        // fall back to the legacy custom RFC 6238 implementation otherwise.
        $codeValid = false;

        if ($this->totpAuthenticator instanceof \Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface) {
            $codeValid = $this->totpAuthenticator->checkCode($user, $code);
        } else {
            $secret = $user->getTotpSecret();
            $codeValid = $secret !== null && $this->verifyTotp($secret, $code);
        }

        if (!$codeValid) {
            $this->auditLogger->log(
                eventType: AuditEventType::AUTH_FAILURE,
                actorId: $email,
                action: '2fa_login',
                outcome: 'invalid_totp',
                ipAddress: $request->getClientIp()
            );

            return new JsonResponse(['message' => 'Invalid TOTP code'], Response::HTTP_UNAUTHORIZED);
        }

        // Spec 066 — mark TOTP code as used (90s TTL covers the 30s window + clock skew)
        if ($this->totpReplayCache instanceof \Psr\Cache\CacheItemPoolInterface) {
            $replayCacheKey = 'totp_used_' . md5($email . ':' . $code);
            $cacheItem = $this->totpReplayCache->getItem($replayCacheKey);
            $cacheItem->set(true);
            $cacheItem->expiresAfter(90);
            $this->totpReplayCache->save($cacheItem);
        }

        $this->auditLogger->log(
            eventType: AuditEventType::AUTH_SUCCESS,
            actorId: $email,
            action: '2fa_login',
            outcome: 'success',
            ipAddress: $request->getClientIp()
        );

        return new JsonResponse([
            'access_token'  => $response->accessToken,
            'refresh_token' => $response->refreshToken,
            'expires_in'    => $response->expiresIn,
        ], Response::HTTP_OK);
    }
    private function verifyTotp(string $base32Secret, string $code): bool
    {
        $secret = $this->base32Decode($base32Secret);
        $currentCounter = (int) floor(time() / 30);

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

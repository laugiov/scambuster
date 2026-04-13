<?php

declare(strict_types=1);

namespace App\UI\Http\Auth;

use App\Application\Audit\AuditLogger;
use App\Application\Auth\AuthServiceInterface;
use App\Application\Auth\Dto\LoginRequestDto;
use App\Application\Auth\Port\UserTotpCheckerInterface;
use App\Domain\Audit\AuditEventType;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/auth/login', name: 'api_auth_login', methods: ['POST'])]
#[OA\Post(
    path: '/api/v1/auth/login',
    summary: 'Authentification utilisateur (login)',
    tags: ['Auth'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(ref: new Model(type: LoginRequestDto::class))
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Connexion réussie',
            content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'access_token', type: 'string'),
                new OA\Property(property: 'refresh_token', type: 'string'),
                new OA\Property(property: 'expires_in', type: 'integer')
            ])
        ),
        new OA\Response(
            response: 400,
            description: 'JSON invalide',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])
        ),
        new OA\Response(
            response: 401,
            description: 'Identifiants invalides',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])
        ),
        new OA\Response(
            response: 422,
            description: 'Erreur de validation',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])
        ),
        new OA\Response(
            response: 429,
            description: 'Trop de tentatives',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'retry_after', type: 'integer')])
        )
    ]
)]
final class LoginController
{
    public function __construct(
        private readonly AuthServiceInterface $handler,
        private readonly AuditLogger $auditLogger,
        private readonly RateLimiterFactory $loginIpLimiter,
        private readonly UserTotpCheckerInterface $totpChecker,
        private ValidatorInterface $validator,
        private SerializerInterface $serializer,
        // Spec 065e — per-email brute-force rate limiter
        private readonly ?RateLimiterFactory $loginEmailLimiter = null,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        // Rate limit check (Redis-backed, persistent across requests)
        $limiter = $this->loginIpLimiter->create($request->getClientIp() ?? 'unknown');
        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $this->auditLogger->log(
                eventType: AuditEventType::RATE_LIMIT_EXCEEDED,
                actorId: 'unknown',
                action: 'login',
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

        try {
            $dto = $this->serializer->deserialize($request->getContent(), LoginRequestDto::class, 'json');
        } catch (\Throwable $e) {
            return new JsonResponse(['message' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $errors = $this->validator->validate($dto);

        if (count($errors) > 0) {
            return new JsonResponse(['message' => (string) $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Spec 065e — Per-email rate limit. Complements the per-IP limiter
        // above: a distributed attacker on a VPN/botnet rotating IPs but
        // targeting the same email is throttled at the email level.
        if ($this->loginEmailLimiter !== null) {
            $emailKey = strtolower(trim($dto->email));
            $emailLimiter = $this->loginEmailLimiter->create($emailKey);
            $emailLimit = $emailLimiter->consume(1);

            if (!$emailLimit->isAccepted()) {
                $this->auditLogger->log(
                    eventType: AuditEventType::AUTH_BRUTE_FORCE_DETECTED,
                    actorId: $emailKey,
                    action: 'login',
                    outcome: 'blocked',
                    details: ['limiter' => 'login_email'],
                    ipAddress: $request->getClientIp(),
                );

                $retryAfter = max(1, $emailLimit->getRetryAfter()->getTimestamp() - time());

                return new JsonResponse(
                    ['error' => 'too_many_attempts', 'retry_after' => $retryAfter],
                    Response::HTTP_TOO_MANY_REQUESTS,
                );
            }
        }

        try {
            $response = $this->handler->login($dto);
        } catch (AuthenticationException $e) {
            $this->auditLogger->log(
                eventType: AuditEventType::AUTH_FAILURE,
                actorId: $dto->email,
                action: 'login',
                outcome: 'failure',
                details: ['reason' => $e->getMessage()],
                ipAddress: $request->getClientIp()
            );

            return new JsonResponse(['message' => strtolower($e->getMessage())], Response::HTTP_UNAUTHORIZED);
        }

        // Check if 2FA is required (delegates to UserTotpChecker port)
        if ($this->totpChecker->isTotpRequired($dto->email)) {
            $this->auditLogger->log(
                eventType: AuditEventType::AUTH_SUCCESS,
                actorId: $dto->email,
                action: 'login',
                outcome: '2fa_required',
                ipAddress: $request->getClientIp()
            );

            return new JsonResponse([
                'requires_2fa' => true,
                'message'      => 'TOTP verification required',
            ], Response::HTTP_OK);
        }

        // Successful login: reset both limiters
        $limiter->reset();

        if ($this->loginEmailLimiter !== null) {
            $this->loginEmailLimiter->create(strtolower(trim($dto->email)))->reset();
        }

        $this->auditLogger->log(
            eventType: AuditEventType::AUTH_SUCCESS,
            actorId: $dto->email,
            action: 'login',
            outcome: 'success',
            ipAddress: $request->getClientIp()
        );

        return new JsonResponse([
            'access_token'  => $response->accessToken,
            'refresh_token' => $response->refreshToken,
            'expires_in'    => $response->expiresIn,
        ], Response::HTTP_OK);
    }
}

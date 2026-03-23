<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener;

use App\Application\Audit\AuditLogger;
use App\Domain\Audit\AuditEventType;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTExpiredEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Listens to Lexik JWT authentication events and logs them to the audit trail.
 *
 * Events:
 * - lexik_jwt_authentication.on_authentication_success -> AUTH_SUCCESS
 * - lexik_jwt_authentication.on_jwt_invalid -> AUTH_FAILURE
 * - lexik_jwt_authentication.on_jwt_expired -> AUTH_TOKEN_EXPIRED
 */
final class AuditAuthListener
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly RequestStack $requestStack
    ) {
    }

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();
        $email = method_exists($user, 'getUserIdentifier') ? $user->getUserIdentifier() : 'unknown';

        $this->auditLogger->log(
            eventType: AuditEventType::AUTH_SUCCESS,
            actorId: $email,
            action: 'authenticate',
            outcome: 'success',
            ipAddress: $this->getClientIp()
        );
    }

    public function onJwtInvalid(JWTInvalidEvent $event): void
    {
        $this->auditLogger->log(
            eventType: AuditEventType::AUTH_FAILURE,
            actorId: 'anonymous',
            action: 'authenticate',
            outcome: 'failure',
            details: ['reason' => 'invalid_token'],
            ipAddress: $this->getClientIp()
        );
    }

    public function onJwtExpired(JWTExpiredEvent $event): void
    {
        $this->auditLogger->log(
            eventType: AuditEventType::AUTH_TOKEN_EXPIRED,
            actorId: 'anonymous',
            action: 'authenticate',
            outcome: 'failure',
            details: ['reason' => 'token_expired'],
            ipAddress: $this->getClientIp()
        );
    }

    private function getClientIp(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request?->getClientIp();
    }
}

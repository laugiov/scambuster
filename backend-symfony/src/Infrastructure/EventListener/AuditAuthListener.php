<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener;

use App\Application\Audit\AuditLogger;
use App\Domain\Audit\AuditEventType;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Listens to Lexik JWT authentication events and logs them to the audit trail.
 *
 * Events:
 * - lexik_jwt_authentication.on_authentication_success -> AUTH_SUCCESS
 * - lexik_jwt_authentication.on_jwt_invalid -> AUTH_FAILURE
 * - lexik_jwt_authentication.on_jwt_expired -> AUTH_TOKEN_EXPIRED
 */
final readonly class AuditAuthListener
{
    public function __construct(
        private AuditLogger $auditLogger,
        private RequestStack $requestStack
    ) {
    }

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();
        $email = $user->getUserIdentifier();

        $this->auditLogger->log(
            eventType: AuditEventType::AUTH_SUCCESS,
            actorId: $email,
            action: 'authenticate',
            outcome: 'success',
            ipAddress: $this->getClientIp()
        );
    }

    public function onJwtInvalid(): void
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

    public function onJwtExpired(): void
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

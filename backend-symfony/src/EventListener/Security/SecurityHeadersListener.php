<?php

declare(strict_types=1);

namespace App\EventListener\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Adds OWASP-recommended security headers to all HTTP responses.
 *
 * Headers prevent: MIME sniffing, clickjacking, referrer leakage,
 * browser feature abuse, and cross-origin attacks.
 *
 * Reference: security-by-design framework (OWASP Security Headers).
 */
#[AsEventListener(event: 'kernel.response', priority: -256)]
class SecurityHeadersListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        if (!$headers->has('Cache-Control')) {
            $headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        }
    }
}

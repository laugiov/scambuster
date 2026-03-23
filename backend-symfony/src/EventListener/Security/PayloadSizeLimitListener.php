<?php

declare(strict_types=1);

namespace App\EventListener\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Rejects HTTP requests with payloads exceeding the configured limit.
 *
 * Prevents DoS via oversized request bodies.
 * Default limit: 1 MB. Configurable via constructor.
 *
 * Reference: security-by-design framework (Defense in Depth).
 */
#[AsEventListener(event: 'kernel.request', priority: 200)]
class PayloadSizeLimitListener
{
    private const DEFAULT_MAX_BYTES = 1_048_576; // 1 MB

    public function __construct(
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $contentLength = $request->headers->get('Content-Length');

        if ($contentLength !== null && (int) $contentLength > $this->maxBytes) {
            $event->setResponse(new JsonResponse(
                ['error' => 'Payload too large', 'max_bytes' => $this->maxBytes],
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE
            ));
        }
    }
}

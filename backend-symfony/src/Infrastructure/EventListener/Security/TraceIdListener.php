<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Generates or propagates a trace_id for request correlation.
 *
 * On request: reads X-Trace-Id header (if present) or generates a UUID.
 * Stores it in request attributes for use by services and Monolog processor.
 * On response: adds X-Trace-Id header so the caller can correlate.
 *
 * Reference: security-by-design framework (W3C Trace Context).
 */
#[AsEventListener(event: 'kernel.request', priority: 255)]
#[AsEventListener(event: 'kernel.response', priority: -255)]
class TraceIdListener
{
    public const ATTRIBUTE_KEY = '_trace_id';
    public const HEADER_NAME = 'X-Trace-Id';

    public function __invoke(RequestEvent|ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($event instanceof RequestEvent) {
            $this->onRequest($event);
        } else {
            $this->onResponse($event);
        }
    }

    private function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $traceId = $request->headers->get(self::HEADER_NAME);

        if ($traceId === null || $traceId === '') {
            $traceId = bin2hex(random_bytes(16));
        }

        $request->attributes->set(self::ATTRIBUTE_KEY, $traceId);
    }

    private function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $traceId = $request->attributes->get(self::ATTRIBUTE_KEY);

        if (is_string($traceId) && $traceId !== '') {
            $event->getResponse()->headers->set(self::HEADER_NAME, $traceId);
        }
    }

    /**
     * Static helper to get trace_id from a request (for use in services).
     */
    public static function getTraceId(Request $request): ?string
    {
        $traceId = $request->attributes->get(self::ATTRIBUTE_KEY);

        return is_string($traceId) ? $traceId : null;
    }
}

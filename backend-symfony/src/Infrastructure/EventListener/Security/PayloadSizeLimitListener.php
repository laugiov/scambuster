<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener\Security;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Rejects HTTP requests with payloads exceeding the configured limit.
 *
 * Prevents DoS via oversized request bodies.
 *
 * Two distinct limits are enforced based on the request path:
 *   - 1 MB default for all generic API endpoints (auth, queries, admin)
 *   - 50 MB for the email ingestion endpoint
 *     (`/api/v1/communication/ingest/`), which legitimately needs to
 *     accept large multipart RFC822 mails with attachments. The 50 MB
 *     budget covers the worst case: a 25 MB binary attachment +
 *     ~33 MB after base64 expansion + JSON envelope overhead.
 *
 * Both limits are configurable via constructor (used by tests).
 *
 * Reference: security-by-design framework (Defense in Depth).
 */
#[AsEventListener(event: 'kernel.request', priority: 200)]
class PayloadSizeLimitListener
{
    private const DEFAULT_MAX_BYTES = 1_048_576;        // 1 MB
    private const DEFAULT_MAX_INGEST_BYTES = 52_428_800; // 50 MB

    /**
     * Path prefixes that get the higher ingest limit.
     */
    private const INGEST_PATH_PREFIX = '/api/v1/communication/ingest';

    public function __construct(private readonly LoggerInterface $logger = new NullLogger(), private readonly int $maxBytes = self::DEFAULT_MAX_BYTES, private readonly int $maxIngestBytes = self::DEFAULT_MAX_INGEST_BYTES)
    {
    }

    /**
     * HTTP methods that can legitimately carry a request body and therefore
     * need the size guard even when no Content-Length header is present.
     */
    private const BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $effectiveLimit = str_starts_with($request->getPathInfo(), self::INGEST_PATH_PREFIX)
            ? $this->maxIngestBytes
            : $this->maxBytes;

        $contentLength = $request->headers->get('Content-Length');

        if ($contentLength !== null) {
            $declaredBytes = (int) $contentLength;

            if ($declaredBytes > $effectiveLimit) {
                $this->reject($event, $request, $declaredBytes, $effectiveLimit);
            }

            return;
        }

        // No Content-Length header — typically HTTP chunked transfer-encoding.
        // A missing declared size must NOT be a bypass: an attacker can stream an
        // unbounded chunked body to skip the header-based check and push the
        // oversized payload into the expensive downstream pipeline (regex IOC
        // extraction, LLM calls). Measure the actual buffered body and apply the
        // same cap. getContent() caches the body, so re-reads in controllers are
        // unaffected. Only body-bearing methods can carry a chunked payload.
        //
        // Note: this is a defense-in-depth control. The first line of defense
        // against oversized bodies remains the reverse proxy / PHP SAPI limits
        // (nginx client_max_body_size, PHP post_max_size); this listener ensures
        // a request that slips past a misconfigured edge still never reaches the
        // heavy processing path.
        if (!in_array($request->getMethod(), self::BODY_METHODS, true)) {
            return;
        }

        $actualBytes = strlen($request->getContent());

        if ($actualBytes > $effectiveLimit) {
            $this->reject($event, $request, $actualBytes, $effectiveLimit);
        }
    }

    private function reject(RequestEvent $event, Request $request, int $receivedBytes, int $effectiveLimit): void
    {
        // Log oversized rejections explicitly so analysts can
        // grep var/log for mails that were silently dropped. Without this,
        // an oversized mail is invisible: IMAP marks it SEEN at fetch
        // time, the HTTP request returns 413, n8n moves on, and the only
        // trace is the n8n execution log (which is not centrally indexed).
        $this->logger->warning('[PayloadSizeLimitListener] Request body too large, rejected with 413', [
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
            'content_length_bytes' => $receivedBytes,
            'limit_bytes' => $effectiveLimit,
            'content_length_declared' => $request->headers->get('Content-Length') !== null,
            'remote_addr' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'note' => 'For ingest endpoints, this means an email exceeded the max size — check the upstream collector logs to identify the source mail',
        ]);

        $event->setResponse(new JsonResponse(
            [
                'error' => 'Payload too large',
                'max_bytes' => $effectiveLimit,
                'received_bytes' => $receivedBytes,
                'hint' => 'The request body exceeds the per-endpoint maximum. For mail ingestion, consider stripping large attachments upstream or splitting the message.',
            ],
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE
        ));
    }
}

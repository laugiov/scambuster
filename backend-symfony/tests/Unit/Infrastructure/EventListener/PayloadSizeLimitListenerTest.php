<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\EventListener;

use App\Infrastructure\EventListener\Security\PayloadSizeLimitListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class PayloadSizeLimitListenerTest extends TestCase
{
    private function createEvent(Request $request, bool $isMainRequest = true): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new RequestEvent(
            $kernel,
            $request,
            $isMainRequest ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST
        );
    }

    public function test_allows_request_without_content_length(): void
    {
        $listener = new PayloadSizeLimitListener(new NullLogger());
        $request = Request::create('/api/v1/test', 'POST');
        $request->headers->remove('Content-Length');
        $event = $this->createEvent($request);

        $listener($event);

        $this->assertNull($event->getResponse());
    }

    public function test_allows_request_within_default_limit(): void
    {
        $listener = new PayloadSizeLimitListener(new NullLogger());
        $request = Request::create('/api/v1/test', 'POST');
        $request->headers->set('Content-Length', '500000'); // 500KB
        $event = $this->createEvent($request);

        $listener($event);

        $this->assertNull($event->getResponse());
    }

    public function test_rejects_request_exceeding_default_limit(): void
    {
        $listener = new PayloadSizeLimitListener(new NullLogger(), maxBytes: 1000);
        $request = Request::create('/api/v1/test', 'POST');
        $request->headers->set('Content-Length', '2000');
        $event = $this->createEvent($request);

        $listener($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(413, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Payload too large', $data['error']);
        $this->assertSame(1000, $data['max_bytes']);
        $this->assertSame(2000, $data['received_bytes']);
    }

    public function test_uses_higher_limit_for_ingest_path(): void
    {
        // Small default, larger ingest limit
        $listener = new PayloadSizeLimitListener(new NullLogger(), maxBytes: 100, maxIngestBytes: 50000);

        // Request to ingest path with size > default but < ingest limit
        $request = Request::create('/api/v1/communication/ingest/raw', 'POST');
        $request->headers->set('Content-Length', '5000');
        $event = $this->createEvent($request);

        $listener($event);

        // Should be allowed (under ingest limit)
        $this->assertNull($event->getResponse());
    }

    public function test_rejects_ingest_exceeding_ingest_limit(): void
    {
        $listener = new PayloadSizeLimitListener(new NullLogger(), maxBytes: 100, maxIngestBytes: 1000);

        $request = Request::create('/api/v1/communication/ingest/raw', 'POST');
        $request->headers->set('Content-Length', '2000');
        $event = $this->createEvent($request);

        $listener($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(413, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame(1000, $data['max_bytes']);
    }

    public function test_skips_sub_requests(): void
    {
        $listener = new PayloadSizeLimitListener(new NullLogger(), maxBytes: 100);

        $request = Request::create('/api/v1/test', 'POST');
        $request->headers->set('Content-Length', '99999');
        $event = $this->createEvent($request, isMainRequest: false);

        $listener($event);

        // Sub-requests are ignored
        $this->assertNull($event->getResponse());
    }

    public function test_response_includes_hint(): void
    {
        $listener = new PayloadSizeLimitListener(new NullLogger(), maxBytes: 100);

        $request = Request::create('/api/v1/test', 'POST');
        $request->headers->set('Content-Length', '500');
        $event = $this->createEvent($request);

        $listener($event);

        $data = json_decode($event->getResponse()->getContent(), true);
        $this->assertArrayHasKey('hint', $data);
        $this->assertStringContainsString('exceeds', $data['hint']);
    }

    // =========================================================================
    // No Content-Length header (e.g. HTTP chunked transfer-encoding).
    // A missing declared size must not become a size-check bypass.
    // =========================================================================

    private function jsonPost(string $path, string $body): Request
    {
        $request = Request::create($path, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        // Simulate a chunked request: no declared Content-Length.
        $request->headers->remove('Content-Length');
        $request->server->remove('CONTENT_LENGTH');

        return $request;
    }

    public function test_rejects_chunked_post_exceeding_limit_without_content_length(): void
    {
        $listener = new PayloadSizeLimitListener(new NullLogger(), maxBytes: 1000);
        $request = $this->jsonPost('/api/v1/test', str_repeat('A', 2000));
        $this->assertNull($request->headers->get('Content-Length'));

        $event = $this->createEvent($request);
        $listener($event);

        $response = $event->getResponse();
        $this->assertNotNull($response, 'Oversized chunked body must be rejected even without Content-Length');
        $this->assertSame(413, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Payload too large', $data['error']);
        $this->assertSame(2000, $data['received_bytes']);
    }

    public function test_allows_small_chunked_post_without_content_length(): void
    {
        $listener = new PayloadSizeLimitListener(new NullLogger(), maxBytes: 1000);
        $request = $this->jsonPost('/api/v1/test', str_repeat('A', 100));

        $event = $this->createEvent($request);
        $listener($event);

        $this->assertNull($event->getResponse());
    }

    public function test_uses_ingest_limit_for_chunked_ingest_without_content_length(): void
    {
        $listener = new PayloadSizeLimitListener(new NullLogger(), maxBytes: 1000, maxIngestBytes: 5000);

        // 2000 bytes: over the 1000 default, but under the 5000 ingest limit.
        $request = $this->jsonPost('/api/v1/communication/ingest/raw', str_repeat('A', 2000));
        $event = $this->createEvent($request);
        $listener($event);
        $this->assertNull($event->getResponse(), 'Chunked ingest under the ingest limit must be allowed');

        // 6000 bytes: over the ingest limit -> rejected.
        $request = $this->jsonPost('/api/v1/communication/ingest/raw', str_repeat('A', 6000));
        $event = $this->createEvent($request);
        $listener($event);
        $this->assertSame(413, $event->getResponse()?->getStatusCode());
    }

    public function test_skips_body_read_for_get_without_content_length(): void
    {
        $listener = new PayloadSizeLimitListener(new NullLogger(), maxBytes: 1000);

        // A GET cannot carry a chunked body worth guarding; ensure we don't reject.
        $request = Request::create('/api/v1/test', 'GET');
        $request->headers->remove('Content-Length');
        $request->server->remove('CONTENT_LENGTH');

        $event = $this->createEvent($request);
        $listener($event);

        $this->assertNull($event->getResponse());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TraceIdListenerTest extends WebTestCase
{
    public function testResponseContainsTraceIdHeader(): void
    {
        $client = static::createClient();
        $client->request('GET', '/healthz');

        $this->assertResponseIsSuccessful();
        $traceId = $client->getResponse()->headers->get('X-Trace-Id');
        $this->assertNotNull($traceId);
        $this->assertNotEmpty($traceId);
        $this->assertSame(32, strlen($traceId)); // 16 bytes hex = 32 chars
    }

    public function testTraceIdIsPropagatedFromRequestHeader(): void
    {
        $client = static::createClient();
        $customTraceId = 'my-custom-trace-id-for-testing';

        $client->request('GET', '/healthz', [], [], [
            'HTTP_X_TRACE_ID' => $customTraceId,
        ]);

        $this->assertResponseIsSuccessful();
        $returnedTraceId = $client->getResponse()->headers->get('X-Trace-Id');
        $this->assertSame($customTraceId, $returnedTraceId);
    }

    public function testEachRequestGetsUniqueTraceId(): void
    {
        $client = static::createClient();

        $client->request('GET', '/healthz');
        $traceId1 = $client->getResponse()->headers->get('X-Trace-Id');

        $client->request('GET', '/healthz');
        $traceId2 = $client->getResponse()->headers->get('X-Trace-Id');

        $this->assertNotSame($traceId1, $traceId2);
    }
}

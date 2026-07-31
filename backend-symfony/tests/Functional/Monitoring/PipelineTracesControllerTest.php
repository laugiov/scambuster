<?php

declare(strict_types=1);

namespace Tests\Functional\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for PipelineTracesController and PipelineTraceDetailController.
 */
final class PipelineTracesControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // ------------------------------------------------------------------ //
    //  GET /api/v1/monitoring/pipeline-traces
    // ------------------------------------------------------------------ //

    public function testListTracesRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/pipeline-traces');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testListTracesReturnsJsonForAuthenticatedUser(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/pipeline-traces', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testListTracesAcceptsQueryParameters(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/pipeline-traces?days=3&limit=10&offset=0', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testListTracesWithPersonaFilter(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/pipeline-traces?persona=generic_user', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testListTracesWithScamTypeFilter(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/pipeline-traces?scam_type=PHISHING', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
    }

    // ------------------------------------------------------------------ //
    //  GET /api/v1/monitoring/pipeline-traces/{msgId}
    // ------------------------------------------------------------------ //

    public function testTraceDetailRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/pipeline-traces/00000000-0000-0000-0000-000000000001');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTraceDetailReturns404ForNonexistentMessage(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/pipeline-traces/00000000-0000-0000-0000-000000000099', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testTraceDetailReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/pipeline-traces/00000000-0000-0000-0000-000000000099', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}

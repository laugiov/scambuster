<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class PipelineTracesControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testTracesRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/pipeline-traces');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTracesReturnsData(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/pipeline-traces', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testTraceDetailRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/pipeline-traces/00000000-0000-0000-0000-000000000001');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}

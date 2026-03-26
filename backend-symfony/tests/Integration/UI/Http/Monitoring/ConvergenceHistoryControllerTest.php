<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ConvergenceHistoryControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testConvergenceHistoryRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/convergence-history');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testConvergenceHistoryReturnsExpectedStructure(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/convergence-history', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('period_days', $data);
        $this->assertArrayHasKey('by_scam_type', $data);
        $this->assertSame(30, $data['period_days']);
    }

    public function testConvergenceHistoryByScamTypeIsObject(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/convergence-history', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        // by_scam_type is cast to (object), so it can be an empty object {} or keyed array
        $this->assertIsArray($data['by_scam_type']);
    }

    public function testConvergenceHistoryReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/convergence-history', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}

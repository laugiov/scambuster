<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class RateLimitControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testRateLimitEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/rate-limits');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRateLimitEndpointReturnsLimitsStructure(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/rate-limits', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('llm_calls_limit', $data);
        $this->assertArrayHasKey('active_conversations_limit', $data);
        $this->assertArrayHasKey('rate_limited_today', $data);
        $this->assertArrayHasKey('quarantined_senders_today', $data);
    }

    public function testRateLimitEndpointRateLimitedTodayIsArray(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/rate-limits', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data['rate_limited_today']);
    }

    public function testRateLimitEndpointReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/rate-limits', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}

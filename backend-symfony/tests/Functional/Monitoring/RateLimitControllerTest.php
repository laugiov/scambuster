<?php

declare(strict_types=1);

namespace Tests\Functional\Monitoring;

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

    public function testRateLimitQuarantinedSendersIsInteger(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/rate-limits', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsInt($data['quarantined_senders_today']);
    }

    public function testRateLimitTodayItemsHaveTypeAndCount(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/rate-limits', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        foreach ($data['rate_limited_today'] as $item) {
            $this->assertArrayHasKey('type', $item);
            $this->assertArrayHasKey('count', $item);
            $this->assertIsString($item['type']);
            $this->assertIsInt($item['count']);
        }
    }

    public function testRateLimitLlmCallsLimitIsInteger(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/rate-limits', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsInt($data['llm_calls_limit']);
        $this->assertIsInt($data['active_conversations_limit']);
    }

    public function testRateLimitWithAdminAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/rate-limits', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('llm_calls_limit', $data);
    }

    public function testRateLimitLimitsArePositive(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/rate-limits', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertGreaterThan(0, $data['llm_calls_limit']);
        $this->assertGreaterThan(0, $data['active_conversations_limit']);
    }

    public function testRateLimitQuarantinedSendersIsNonNegative(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/rate-limits', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertGreaterThanOrEqual(0, $data['quarantined_senders_today']);
    }
}

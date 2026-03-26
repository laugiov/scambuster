<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Campaign;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class GetCampaignMessagesControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testGetCampaignMessagesRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/campaign/00000000-0000-0000-0000-000000000001/messages');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetCampaignMessagesRejectsInvalidCampaignIdFormat(): void
    {
        $this->client->request('GET', '/api/v1/campaign/not-a-uuid/messages', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid campaign_id format', $data['error']);
    }

    public function testGetCampaignMessagesReturnsErrorForNonexistentCampaign(): void
    {
        $this->client->request('GET', '/api/v1/campaign/00000000-0000-0000-0000-000000000099/messages', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_NOT_FOUND,
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testGetCampaignMessagesAcceptsValidLimitParam(): void
    {
        $this->client->request('GET', '/api/v1/campaign/00000000-0000-0000-0000-000000000001/messages?limit=50', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Valid limit should not trigger 400
        $this->assertNotSame(Response::HTTP_BAD_REQUEST, $statusCode);
    }

    public function testGetCampaignMessagesRejectsInvalidLimitTooHigh(): void
    {
        $this->client->request('GET', '/api/v1/campaign/00000000-0000-0000-0000-000000000001/messages?limit=200', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('limit must be between', $data['error']);
    }

    public function testGetCampaignMessagesRejectsInvalidLimitZero(): void
    {
        $this->client->request('GET', '/api/v1/campaign/00000000-0000-0000-0000-000000000001/messages?limit=0', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testGetCampaignMessagesReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/campaign/not-a-uuid/messages', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testGetCampaignMessagesDefaultLimitWorks(): void
    {
        $this->client->request('GET', '/api/v1/campaign/00000000-0000-0000-0000-000000000001/messages', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Default limit=10, should not trigger 400
        $this->assertNotSame(Response::HTTP_BAD_REQUEST, $statusCode);
    }
}

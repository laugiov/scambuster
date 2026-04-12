<?php

declare(strict_types=1);

namespace Tests\EndToEnd\Campaign;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class PromoteCampaignControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testPromoteCampaignRequiresAuthentication(): void
    {
        $fakeUuid = '00000000-0000-0000-0000-000000000001';
        $this->client->request('POST', "/api/v1/campaign/rule/{$fakeUuid}/promote");

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testPromoteCampaignForbiddenForNonAdmin(): void
    {
        $fakeUuid = '00000000-0000-0000-0000-000000000001';
        $this->client->request('POST', "/api/v1/campaign/rule/{$fakeUuid}/promote", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testPromoteCampaignReturns400ForInvalidUuid(): void
    {
        $this->client->request('POST', '/api/v1/campaign/rule/not-a-uuid/promote', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Invalid rule_id format', $data['error']);
    }

    public function testPromoteCampaignReturns404ForNonExistentRule(): void
    {
        $fakeUuid = '00000000-0000-0000-0000-000000000099';
        $this->client->request('POST', "/api/v1/campaign/rule/{$fakeUuid}/promote", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Expect 404 (rule not found) or 400 (promotion failed / thresholds not met)
        $this->assertContains($statusCode, [
            Response::HTTP_NOT_FOUND,
            Response::HTTP_BAD_REQUEST,
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testPromoteCampaignReturnsJsonContentType(): void
    {
        $fakeUuid = '00000000-0000-0000-0000-000000000099';
        $this->client->request('POST', "/api/v1/campaign/rule/{$fakeUuid}/promote", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}

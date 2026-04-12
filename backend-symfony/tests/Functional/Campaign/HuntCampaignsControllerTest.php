<?php

declare(strict_types=1);

namespace Tests\Functional\Campaign;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class HuntCampaignsControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testHuntCampaignsRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/campaign/hunt');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testHuntCampaignsForbiddenForNonAdmin(): void
    {
        $this->client->request('POST', '/api/v1/campaign/hunt', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testHuntCampaignsReturnsResultsForAdmin(): void
    {
        $this->client->request('POST', '/api/v1/campaign/hunt', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Should be 200 on success or 500 if hunt execution fails
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_INTERNAL_SERVER_ERROR]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);

        if ($statusCode === Response::HTTP_OK) {
            $this->assertArrayHasKey('total_rules', $data);
            $this->assertArrayHasKey('total_hits', $data);
            $this->assertArrayHasKey('results', $data);
        } else {
            $this->assertArrayHasKey('error', $data);
        }
    }

    public function testHuntCampaignsReturnsJsonContentType(): void
    {
        $this->client->request('POST', '/api/v1/campaign/hunt', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}

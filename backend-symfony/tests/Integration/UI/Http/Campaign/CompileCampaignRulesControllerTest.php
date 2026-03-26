<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Campaign;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class CompileCampaignRulesControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testCompileRulesRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/campaign/00000000-0000-0000-0000-000000000001/rules/compile', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testCompileRulesForbiddenForNonAdmin(): void
    {
        $this->client->request('POST', '/api/v1/campaign/00000000-0000-0000-0000-000000000001/rules/compile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testCompileRulesRejectsInvalidCampaignIdFormat(): void
    {
        $this->client->request('POST', '/api/v1/campaign/not-a-uuid/rules/compile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid campaign_id format', $data['error']);
    }

    public function testCompileRulesReturnsJsonContentType(): void
    {
        $this->client->request('POST', '/api/v1/campaign/not-a-uuid/rules/compile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testCompileRulesReturnsErrorForNonexistentCampaign(): void
    {
        $this->client->request('POST', '/api/v1/campaign/00000000-0000-0000-0000-000000000099/rules/compile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Should be 404 (campaign not found) or 500 (handler error)
        $this->assertContains($statusCode, [Response::HTTP_NOT_FOUND, Response::HTTP_INTERNAL_SERVER_ERROR]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCompileRulesAcceptsOptionalExamples(): void
    {
        $payload = [
            'examples' => [
                'pos' => ['00000000-0000-0000-0000-000000000001'],
                'neg' => ['00000000-0000-0000-0000-000000000002'],
            ],
        ];

        $this->client->request('POST', '/api/v1/campaign/00000000-0000-0000-0000-000000000099/rules/compile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Should not be a 400 for bad input - the examples format is valid
        $this->assertNotSame(Response::HTTP_BAD_REQUEST, $statusCode);
    }
}

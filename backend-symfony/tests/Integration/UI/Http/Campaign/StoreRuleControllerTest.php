<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Campaign;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class StoreRuleControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testStoreRuleRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/campaign/rule', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testStoreRuleRejectsInvalidJson(): void
    {
        $this->client->request('POST', '/api/v1/campaign/rule', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], 'not-json');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testStoreRuleRejectsMissingRequiredFields(): void
    {
        $this->client->request('POST', '/api/v1/campaign/rule', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['campaign_id' => '00000000-0000-0000-0000-000000000001']));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('campaign_id, dsl, and compiled_sql required', $data['error']);
    }

    public function testStoreRuleRejectsInvalidCampaignIdFormat(): void
    {
        $payload = [
            'campaign_id' => 'not-a-uuid',
            'dsl' => 'RULE test { WHERE subject.simhash }',
            'compiled_sql' => ['sql' => 'SELECT 1', 'params' => []],
        ];

        $this->client->request('POST', '/api/v1/campaign/rule', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid campaign_id format', $data['error']);
    }

    public function testStoreRuleReturns404ForNonexistentCampaign(): void
    {
        $payload = [
            'campaign_id' => '00000000-0000-0000-0000-000000000099',
            'dsl' => 'RULE test { WHERE subject.simhash }',
            'compiled_sql' => ['sql' => 'SELECT 1', 'params' => []],
        ];

        $this->client->request('POST', '/api/v1/campaign/rule', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testStoreRuleReturnsJsonContentType(): void
    {
        $this->client->request('POST', '/api/v1/campaign/rule', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}

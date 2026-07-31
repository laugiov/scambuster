<?php

declare(strict_types=1);

namespace Tests\Functional\Campaign;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ExportCampaignSTIXControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testExportStixRequiresAuthentication(): void
    {
        $fakeUuid = '00000000-0000-0000-0000-000000000001';
        $this->client->request('POST', "/api/v1/campaign/{$fakeUuid}/export/stix");

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testExportStixReturns400ForInvalidUuid(): void
    {
        $this->client->request('POST', '/api/v1/campaign/not-a-uuid/export/stix', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Invalid campaign_id format', $data['error']);
    }

    public function testExportStixReturns404ForNonExistentCampaign(): void
    {
        $fakeUuid = '00000000-0000-0000-0000-000000000099';
        $this->client->request('POST', "/api/v1/campaign/{$fakeUuid}/export/stix", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Campaign not found', $data['error']);
    }

    public function testExportStixReturnsJsonContentType(): void
    {
        $fakeUuid = '00000000-0000-0000-0000-000000000099';
        $this->client->request('POST', "/api/v1/campaign/{$fakeUuid}/export/stix", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}

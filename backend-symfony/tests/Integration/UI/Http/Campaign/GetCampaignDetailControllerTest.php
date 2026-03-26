<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Campaign;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class GetCampaignDetailControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testCampaignDetailRequiresAuthentication(): void
    {
        $fakeUuid = '00000000-0000-0000-0000-000000000001';
        $this->client->request('GET', "/api/v1/campaign/{$fakeUuid}/detail");

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testCampaignDetailReturns404ForNonExistentCampaign(): void
    {
        $fakeUuid = '00000000-0000-0000-0000-000000000099';
        $this->client->request('GET', "/api/v1/campaign/{$fakeUuid}/detail", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Campaign not found', $data['error']);
    }

    public function testCampaignDetailReturnsJsonContentType(): void
    {
        $fakeUuid = '00000000-0000-0000-0000-000000000099';
        $this->client->request('GET', "/api/v1/campaign/{$fakeUuid}/detail", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}

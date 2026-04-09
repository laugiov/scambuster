<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Taxii;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TaxiiCollectionsTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testReturnsThreeCollections(): void
    {
        $this->client->request('GET', '/api/v1/taxii2/api/collections/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('collections', $data);
        $this->assertCount(3, $data['collections']);

        $titles = array_column($data['collections'], 'title');
        $this->assertContains('ScamBuster IOCs', $titles);
        $this->assertContains('ScamBuster Campaigns', $titles);
        $this->assertContains('ScamBuster Threat Actors', $titles);
    }

    public function testCollectionsHaveCorrectPermissions(): void
    {
        $this->client->request('GET', '/api/v1/taxii2/api/collections/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        foreach ($data['collections'] as $collection) {
            $this->assertTrue($collection['can_read']);
            $this->assertFalse($collection['can_write']);
        }
    }
}

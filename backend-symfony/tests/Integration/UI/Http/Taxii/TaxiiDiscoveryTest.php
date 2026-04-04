<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Taxii;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TaxiiDiscoveryTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testRequiresAuthentication(): void
    {
        $this->client->request('GET', '/taxii2/');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testReturnsCorrectStructure(): void
    {
        $this->client->request('GET', '/taxii2/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertSame('ScamBuster TAXII Server', $data['title']);
        $this->assertArrayHasKey('api_roots', $data);
        $this->assertIsArray($data['api_roots']);
        $this->assertContains('/taxii2/api/', $data['api_roots']);
    }

    public function testContentTypeIsTaxii(): void
    {
        $this->client->request('GET', '/taxii2/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            'application/taxii+json;version=2.1',
            $this->client->getResponse()->headers->get('Content-Type')
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Taxii;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class TaxiiControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testDiscoveryRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/taxii2/');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testDiscoveryReturnsJsonWithTaxiiContentType(): void
    {
        $this->client->request('GET', '/api/v1/taxii2/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            'application/taxii+json',
            $this->client->getResponse()->headers->get('Content-Type') ?? ''
        );
    }

    public function testApiRootRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/taxii2/api/');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testApiRootReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/taxii2/api/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testCollectionsRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/taxii2/api/collections/');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testCollectionsReturnsCollectionList(): void
    {
        $this->client->request('GET', '/api/v1/taxii2/api/collections/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertIsString($content);
        $data = json_decode($content, true);
        $this->assertArrayHasKey('collections', $data);
    }
}

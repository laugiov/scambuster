<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Meta;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ConfigControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testConfigEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/meta/config');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testConfigEndpointReturnsExpectedStructure(): void
    {
        $this->client->request('GET', '/api/v1/meta/config', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        // ConfigHandler should return personas and scam_types at minimum
        $this->assertArrayHasKey('personas', $data);
        $this->assertArrayHasKey('scam_types', $data);
    }

    public function testConfigEndpointPersonasIsArray(): void
    {
        $this->client->request('GET', '/api/v1/meta/config', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data['personas']);
    }

    public function testConfigEndpointScamTypesIsArray(): void
    {
        $this->client->request('GET', '/api/v1/meta/config', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data['scam_types']);
    }

    public function testConfigEndpointReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/meta/config', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}

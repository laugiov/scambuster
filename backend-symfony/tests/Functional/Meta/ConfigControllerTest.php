<?php

declare(strict_types=1);

namespace Tests\Functional\Meta;

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

    public function testConfigEndpointReturnsIocTypes(): void
    {
        $this->client->request('GET', '/api/v1/meta/config', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('ioc_types', $data);
        $this->assertIsArray($data['ioc_types']);
        $this->assertNotEmpty($data['ioc_types']);
    }

    public function testConfigEndpointReturnsBanditConfig(): void
    {
        $this->client->request('GET', '/api/v1/meta/config', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('bandit', $data);
        $this->assertIsArray($data['bandit']);
    }

    public function testConfigEndpointReturnsLlmProvider(): void
    {
        $this->client->request('GET', '/api/v1/meta/config', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('llm_provider', $data);
        $this->assertIsString($data['llm_provider']);
        $this->assertNotEmpty($data['llm_provider']);
    }

    public function testConfigEndpointReturnsLlmModel(): void
    {
        $this->client->request('GET', '/api/v1/meta/config', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('llm_model', $data);
        $this->assertIsString($data['llm_model']);
        $this->assertNotEmpty($data['llm_model']);
    }

    public function testConfigEndpointReturnsAllSixKeys(): void
    {
        $this->client->request('GET', '/api/v1/meta/config', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $expectedKeys = ['personas', 'scam_types', 'ioc_types', 'bandit', 'llm_provider', 'llm_model'];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $data, "Response must contain '$key' key");
        }
    }

    public function testConfigEndpointPersonasHaveExpectedFields(): void
    {
        $this->client->request('GET', '/api/v1/meta/config', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        if (!empty($data['personas'])) {
            $persona = $data['personas'][0];
            $this->assertArrayHasKey('code', $persona);
            $this->assertArrayHasKey('label', $persona);
            $this->assertArrayHasKey('tone', $persona);
            $this->assertArrayHasKey('active', $persona);
        }
    }

    public function testConfigEndpointScamTypesHaveExpectedFields(): void
    {
        $this->client->request('GET', '/api/v1/meta/config', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        if (!empty($data['scam_types'])) {
            $scamType = $data['scam_types'][0];
            $this->assertArrayHasKey('code', $scamType);
            $this->assertArrayHasKey('label', $scamType);
            $this->assertArrayHasKey('description', $scamType);
            $this->assertArrayHasKey('active', $scamType);
        }
    }

    public function testConfigEndpointReturnsHttp200(): void
    {
        $this->client->request('GET', '/api/v1/meta/config', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testConfigEndpointWithAdminToken(): void
    {
        $this->client->request('GET', '/api/v1/meta/config', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('personas', $data);
    }
}

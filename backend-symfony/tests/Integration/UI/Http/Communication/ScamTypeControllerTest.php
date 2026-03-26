<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ScamTypeControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function auth(string $role = 'user'): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . ($role === 'admin' ? 'fake-admin-jwt' : 'fake-jwt'),
        ];
    }

    public function testListScamTypesRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/communication/scam-types');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testListScamTypesReturnsSuccessfully(): void
    {
        $this->client->request('GET', '/api/v1/communication/scam-types', [], [], $this->auth());

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testListScamTypesReturnsArray(): void
    {
        $this->client->request('GET', '/api/v1/communication/scam-types', [], [], $this->auth());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testListScamTypesResponseStructure(): void
    {
        $this->client->request('GET', '/api/v1/communication/scam-types', [], [], $this->auth());

        $data = json_decode($this->client->getResponse()->getContent(), true);

        if (!empty($data)) {
            $first = $data[0];
            $this->assertArrayHasKey('scam_type_id', $first);
            $this->assertArrayHasKey('code', $first);
        }
    }
}

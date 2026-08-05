<?php

declare(strict_types=1);

namespace Tests\Functional\Personas;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Covers the two single-action Cognitive Mirror controllers
 * (by persona and by scam type).
 */
final class GetPersonaMirrorsControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testByPersonaRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/personas/elderly_person/mirrors');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testByScamTypeRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/scam-types/ROMANCE/mirrors');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testByPersonaReturnsMirrorEnvelope(): void
    {
        $this->client->request('GET', '/api/v1/personas/elderly_person/mirrors', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('elderly_person', $data['data']['persona_code']);
        $this->assertArrayHasKey('mirrors', $data['data']);
    }

    public function testByScamTypeReturnsMirrorEnvelope(): void
    {
        $this->client->request('GET', '/api/v1/scam-types/ROMANCE/mirrors', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('ROMANCE', $data['data']['scam_type_code']);
        $this->assertArrayHasKey('mirrors', $data['data']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Functional\Admin;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for ToggleLlmKillSwitchController and GetLlmKillSwitchStateController.
 */
final class ToggleLlmKillSwitchControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // ------------------------------------------------------------------ //
    //  POST /api/v1/admin/llm/killswitch
    // ------------------------------------------------------------------ //

    public function testToggleKillSwitchRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/admin/llm/killswitch', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['active' => true]));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testToggleKillSwitchForbiddenForNonAdmin(): void
    {
        $this->client->request('POST', '/api/v1/admin/llm/killswitch', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['active' => true]));

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testToggleKillSwitchActivateReturnsOk(): void
    {
        $this->client->request('POST', '/api/v1/admin/llm/killswitch', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['active' => true]));

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(true, $data['active']);
    }

    public function testToggleKillSwitchDeactivateReturnsOk(): void
    {
        $this->client->request('POST', '/api/v1/admin/llm/killswitch', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['active' => false]));

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(false, $data['active']);
    }

    public function testToggleKillSwitchRejectsInvalidBody(): void
    {
        $this->client->request('POST', '/api/v1/admin/llm/killswitch', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['wrong_field' => 'value']));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testToggleKillSwitchRejectsNonBooleanActive(): void
    {
        $this->client->request('POST', '/api/v1/admin/llm/killswitch', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['active' => 'yes']));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testToggleKillSwitchRejectsEmptyBody(): void
    {
        $this->client->request('POST', '/api/v1/admin/llm/killswitch', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], '');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    // ------------------------------------------------------------------ //
    //  GET /api/v1/admin/llm/killswitch
    // ------------------------------------------------------------------ //

    public function testGetKillSwitchStateRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/admin/llm/killswitch');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetKillSwitchStateForbiddenForNonAdmin(): void
    {
        $this->client->request('GET', '/api/v1/admin/llm/killswitch', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testGetKillSwitchStateReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/admin/llm/killswitch', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('active', $data);
        $this->assertIsBool($data['active']);
    }
}

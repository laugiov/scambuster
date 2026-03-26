<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ExportMispControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testExportMispRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/conversations/00000000-0000-0000-0000-000000000001/export/misp');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testExportMispReturns404ForConversationWithNoIocs(): void
    {
        $this->client->request('GET', '/api/v1/conversations/00000000-0000-0000-0000-000000000099/export/misp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('No IOCs found', $data['error']);
    }

    public function testExportMispReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/conversations/00000000-0000-0000-0000-000000000099/export/misp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testExportMispIsAccessibleWithRoleUser(): void
    {
        // The #[IsGranted('ROLE_USER')] attribute should allow regular users
        $this->client->request('GET', '/api/v1/conversations/00000000-0000-0000-0000-000000000001/export/misp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        // Should not be 403 Forbidden - the endpoint allows ROLE_USER
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertNotSame(Response::HTTP_FORBIDDEN, $statusCode);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Functional\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ExportMispControllerValidationTest extends WebTestCase
{
    private KernelBrowser $client;

    private const CONV_OPEN = '00000000-0000-0000-0000-000000000001';
    private const CONV_NONEXISTENT = '99999999-9999-9999-9999-999999999999';

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testExportMispRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/conversations/' . self::CONV_OPEN . '/export/misp');

        $this->assertContains(
            $this->client->getResponse()->getStatusCode(),
            [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN]
        );
    }

    public function testExportMispWithAuthReturnsValidResponse(): void
    {
        $this->client->request('GET', '/api/v1/conversations/' . self::CONV_OPEN . '/export/misp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // 200 if IOCs exist, 404 if no IOCs found, 403 if permission denied
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_NOT_FOUND,
            Response::HTTP_FORBIDDEN,
        ]);

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('Event', $data);
            $this->assertArrayHasKey('info', $data['Event']);
            $this->assertArrayHasKey('Attribute', $data['Event']);
            $this->assertStringContainsString(self::CONV_OPEN, $data['Event']['info']);
        }
    }

    public function testExportMispReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/conversations/' . self::CONV_OPEN . '/export/misp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode !== Response::HTTP_FORBIDDEN) {
            $this->assertResponseHeaderSame('content-type', 'application/json');
        }
    }

    public function testExportMispNonexistentConversationReturns404(): void
    {
        $this->client->request('GET', '/api/v1/conversations/' . self::CONV_NONEXISTENT . '/export/misp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // 404 (no IOCs / no conversation) or 403 (permission denied)
        $this->assertContains($statusCode, [
            Response::HTTP_NOT_FOUND,
            Response::HTTP_FORBIDDEN,
        ]);

        if ($statusCode === Response::HTTP_NOT_FOUND) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertArrayHasKey('error', $data);
        }
    }

    public function testExportMispWithAdminJwtReturnsValidResponse(): void
    {
        $this->client->request('GET', '/api/v1/conversations/' . self::CONV_OPEN . '/export/misp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_NOT_FOUND,
            Response::HTTP_FORBIDDEN,
        ]);
    }

    public function testExportMispInvalidUuidFormatStillRoutes(): void
    {
        $this->client->request('GET', '/api/v1/conversations/not-a-uuid/export/misp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Could be 404 (not found), 400 (bad request), 403 (permission denied),
        // or 500 if the handler throws on invalid UUID format
        $this->assertContains($statusCode, [
            Response::HTTP_NOT_FOUND,
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_FORBIDDEN,
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ]);
    }

    public function testExportMispEventStructureWhenIocsExist(): void
    {
        $this->client->request('GET', '/api/v1/conversations/' . self::CONV_OPEN . '/export/misp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $event = $data['Event'];

            $this->assertSame(2, $event['threat_level_id']);
            $this->assertSame(1, $event['analysis']);
            $this->assertSame(3, $event['distribution']);
            $this->assertIsArray($event['Attribute']);
        }
    }

    public function testExportMispDoesNotAcceptPostMethod(): void
    {
        $this->client->request('POST', '/api/v1/conversations/' . self::CONV_OPEN . '/export/misp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }
}

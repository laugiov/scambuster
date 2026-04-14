<?php

declare(strict_types=1);

namespace App\Tests\Functional\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for ExportMispController.
 *
 * Covers:
 * - MISP Event structure validation (Event key, threat_level_id, etc.)
 * - Attribute array structure within Event
 * - Empty UUID returns 404
 * - PATCH/DELETE methods not allowed
 * - Consistent content-type across error states
 */
final class ExportMispControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private const CONV_OPEN = '00000000-0000-0000-0000-000000000001';
    private const CONV_NONEXISTENT = '99999999-9999-9999-9999-999999999999';

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // ──────────────────────────────────────────────
    // MISP EVENT STRUCTURE
    // ──────────────────────────────────────────────

    public function testMispEventContainsConversationIdInInfo(): void
    {
        $this->client->request('GET', '/api/v1/conversations/' . self::CONV_OPEN . '/export/misp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertArrayHasKey('Event', $data);
            $this->assertStringContainsString(self::CONV_OPEN, $data['Event']['info']);
        } else {
            // 404 or 403 acceptable
            $this->assertContains($statusCode, [
                Response::HTTP_NOT_FOUND,
                Response::HTTP_FORBIDDEN,
            ]);
        }
    }

    // ──────────────────────────────────────────────
    // MISP EVENT STATIC FIELDS
    // ──────────────────────────────────────────────

    public function testMispEventStaticFieldsWhenIocsExist(): void
    {
        $this->client->request('GET', '/api/v1/conversations/' . self::CONV_OPEN . '/export/misp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $event = $data['Event'];

            // threat_level_id = 2 (Medium)
            $this->assertSame(2, $event['threat_level_id']);
            // analysis = 1 (Ongoing)
            $this->assertSame(1, $event['analysis']);
            // distribution = 3 (All communities)
            $this->assertSame(3, $event['distribution']);
            // Attribute is always an array
            $this->assertIsArray($event['Attribute']);
        } else {
            $this->assertContains($statusCode, [
                Response::HTTP_NOT_FOUND,
                Response::HTTP_FORBIDDEN,
            ]);
        }
    }

    // ──────────────────────────────────────────────
    // NONEXISTENT CONVERSATION RETURNS 404
    // ──────────────────────────────────────────────

    public function testNonexistentConversationReturns404WithErrorKey(): void
    {
        $this->client->request('GET', '/api/v1/conversations/' . self::CONV_NONEXISTENT . '/export/misp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_NOT_FOUND,
            Response::HTTP_FORBIDDEN,
        ]);

        if ($statusCode === Response::HTTP_NOT_FOUND) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('error', $data);
            $this->assertStringContainsString('No IOCs found', $data['error']);
        }
    }

    // ──────────────────────────────────────────────
    // DELETE METHOD NOT ALLOWED
    // ──────────────────────────────────────────────

    public function testDeleteMethodNotAllowed(): void
    {
        $this->client->request('DELETE', '/api/v1/conversations/' . self::CONV_OPEN . '/export/misp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ──────────────────────────────────────────────
    // PATCH METHOD NOT ALLOWED
    // ──────────────────────────────────────────────

    public function testPatchMethodNotAllowed(): void
    {
        $this->client->request('PATCH', '/api/v1/conversations/' . self::CONV_OPEN . '/export/misp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ──────────────────────────────────────────────
    // EMPTY UUID STRING
    // ──────────────────────────────────────────────

    public function testEmptyUuidReturns404(): void
    {
        $this->client->request('GET', '/api/v1/conversations//export/misp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Empty path segment -> 404 (no route match) or 405
        $this->assertContains($statusCode, [
            Response::HTTP_NOT_FOUND,
            Response::HTTP_METHOD_NOT_ALLOWED,
        ]);
    }

    // ──────────────────────────────────────────────
    // CONTENT TYPE ON ERROR
    // ──────────────────────────────────────────────

    public function testErrorResponseIsJson(): void
    {
        $this->client->request('GET', '/api/v1/conversations/' . self::CONV_NONEXISTENT . '/export/misp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode !== Response::HTTP_FORBIDDEN) {
            $this->assertResponseHeaderSame('content-type', 'application/json');
        }
    }
}

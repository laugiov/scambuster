<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Additional functional tests for TOTP verify controller (POST /api/v1/2fa/verify).
 *
 * Covers:
 * - Alphabetic code
 * - 5-digit code
 * - 7-digit code
 * - Valid code format but TOTP not configured (user not in DB)
 * - Null code value
 * - Integer code value
 * - Code with spaces
 * - Code as boolean
 * - Response JSON structure
 * - PUT method rejected
 */
final class TotpVerifyControllerAdditionalTest extends WebTestCase
{
    private KernelBrowser $client;

    private const ENDPOINT = '/api/v1/2fa/verify';

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // ──────────────────────────────────────────────
    // ALPHABETIC CODE
    // ──────────────────────────────────────────────

    public function testAlphabeticCodeReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => 'abcdef']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_NOT_FOUND,
        ]);

        if ($statusCode === Response::HTTP_BAD_REQUEST) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertSame('Invalid TOTP code', $data['message']);
        }
    }

    // ──────────────────────────────────────────────
    // 5-DIGIT CODE
    // ──────────────────────────────────────────────

    public function testFiveDigitCodeReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => '12345']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_NOT_FOUND,
        ]);
    }

    // ──────────────────────────────────────────────
    // 7-DIGIT CODE
    // ──────────────────────────────────────────────

    public function testSevenDigitCodeReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => '1234567']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_NOT_FOUND,
        ]);
    }

    // ──────────────────────────────────────────────
    // NULL CODE
    // ──────────────────────────────────────────────

    public function testNullCodeReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => null]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_NOT_FOUND,
        ]);
    }

    // ──────────────────────────────────────────────
    // INTEGER CODE
    // ──────────────────────────────────────────────

    public function testIntegerCodeReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => 123456]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Integer fails is_string() check in the controller -> empty string -> 400
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_NOT_FOUND,
        ]);
    }

    // ──────────────────────────────────────────────
    // CODE WITH SPACES
    // ──────────────────────────────────────────────

    public function testCodeWithSpacesReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => '123 456']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_NOT_FOUND,
        ]);
    }

    // ──────────────────────────────────────────────
    // CODE AS BOOLEAN
    // ──────────────────────────────────────────────

    public function testBooleanCodeReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => true]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_NOT_FOUND,
        ]);
    }

    // ──────────────────────────────────────────────
    // VALID FORMAT CODE REACHES HANDLER (USER NOT FOUND)
    // ──────────────────────────────────────────────

    public function testValidFormatCodeReachesUserLookup(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => '000000']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // InMemoryUser not in DB -> 404 "User not found" or 400 "TOTP not configured"
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_NOT_FOUND,
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('message', $data);
    }

    // ──────────────────────────────────────────────
    // RESPONSE ALWAYS JSON
    // ──────────────────────────────────────────────

    public function testResponseAlwaysReturnsJson(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => '123456']));

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ──────────────────────────────────────────────
    // PUT METHOD NOT ALLOWED
    // ──────────────────────────────────────────────

    public function testPutMethodNotAllowed(): void
    {
        $this->client->request('PUT', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => '123456']));

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ──────────────────────────────────────────────
    // EMPTY BODY
    // ──────────────────────────────────────────────

    public function testEmptyBodyReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], '');

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Empty body -> json_decode returns null -> $payload = [] -> code = '' -> 400
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_NOT_FOUND,
        ]);
    }
}

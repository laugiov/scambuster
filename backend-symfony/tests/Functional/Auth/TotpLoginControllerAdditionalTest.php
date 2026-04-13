<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Additional functional tests for TOTP login controller (POST /api/v1/auth/2fa/login).
 *
 * Covers error paths not exercised by the base test:
 * - Valid credentials but TOTP not configured
 * - Valid 6-digit code format but wrong credentials
 * - Valid JSON reaching handler layer
 * - Missing email/password fields
 * - Integer code value instead of string
 * - Whitespace-padded code
 * - Method restriction (GET)
 * - Empty JSON object
 */
final class TotpLoginControllerAdditionalTest extends WebTestCase
{
    private KernelBrowser $client;

    private const ENDPOINT = '/api/v1/auth/2fa/login';

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // ──────────────────────────────────────────────
    // VALID CREDENTIALS BUT TOTP NOT CONFIGURED
    // ──────────────────────────────────────────────

    public function testValidCredentialsWithoutTotpConfiguredReturns400Or401(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'admin@scambuster.test',
            'password' => 'admin-password',
            'code' => '123456',
        ]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // 401 if credentials invalid, 400 if TOTP not configured, 422 if validation fails
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_UNAUTHORIZED,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('message', $data);
    }

    // ──────────────────────────────────────────────
    // VALID 6-DIGIT CODE BUT WRONG CREDENTIALS
    // ──────────────────────────────────────────────

    public function testValidCodeFormatWithNonexistentUserReturns401(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'does-not-exist@example.com',
            'password' => 'IrrelevantPassword1!',
            'code' => '999999',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('message', $data);
    }

    // ──────────────────────────────────────────────
    // MISSING EMAIL FIELD
    // ──────────────────────────────────────────────

    public function testMissingEmailReturnsValidationError(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'password' => 'SomePassword1!',
            'code' => '123456',
        ]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_UNPROCESSABLE_ENTITY,
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_UNAUTHORIZED,
        ]);
    }

    // ──────────────────────────────────────────────
    // MISSING PASSWORD FIELD
    // ──────────────────────────────────────────────

    public function testMissingPasswordReturnsValidationError(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'user@example.com',
            'code' => '123456',
        ]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_UNPROCESSABLE_ENTITY,
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_UNAUTHORIZED,
        ]);
    }

    // ──────────────────────────────────────────────
    // CODE AS INTEGER INSTEAD OF STRING
    // ──────────────────────────────────────────────

    public function testIntegerCodeReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'user@example.com',
            'password' => 'somepassword',
            'code' => 123456,
        ]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Integer fails is_string check -> 400
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid TOTP code format', $data['message']);
    }

    // ──────────────────────────────────────────────
    // WHITESPACE PADDED CODE
    // ──────────────────────────────────────────────

    public function testWhitespacePaddedCodeReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'user@example.com',
            'password' => 'somepassword',
            'code' => ' 123456 ',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid TOTP code format', $data['message']);
    }

    // ──────────────────────────────────────────────
    // CODE WITH SPECIAL CHARACTERS
    // ──────────────────────────────────────────────

    public function testCodeWithSpecialCharsReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'user@example.com',
            'password' => 'somepassword',
            'code' => '12-456',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid TOTP code format', $data['message']);
    }

    // ──────────────────────────────────────────────
    // GET METHOD NOT ALLOWED
    // ──────────────────────────────────────────────

    public function testGetMethodNotAllowed(): void
    {
        $this->client->request('GET', self::ENDPOINT);

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ──────────────────────────────────────────────
    // EMPTY JSON OBJECT
    // ──────────────────────────────────────────────

    public function testEmptyJsonObjectReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid TOTP code format', $data['message']);
    }

    // ──────────────────────────────────────────────
    // RESPONSE IS ALWAYS JSON
    // ──────────────────────────────────────────────

    public function testResponseAlwaysReturnsJson(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'user@example.com',
            'password' => 'password',
            'code' => '123456',
        ]));

        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    // ──────────────────────────────────────────────
    // CODE WITH LEADING ZEROS
    // ──────────────────────────────────────────────

    public function testCodeWithLeadingZerosIsAcceptedAsValidFormat(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'nonexistent@example.com',
            'password' => 'SomePassword!',
            'code' => '000001',
        ]));

        // Code format is valid (6 digits), but credentials are wrong
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}

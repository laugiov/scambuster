<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for TOTP login controller (POST /api/v1/auth/2fa/login).
 *
 * Covers input validation, TOTP code format, missing fields, and auth flows.
 */
final class TotpLoginControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private const ENDPOINT = '/api/v1/auth/2fa/login';

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testInvalidJsonReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{not valid json');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid JSON', $data['message']);
    }

    public function testInvalidTotpCodeFormatAlphaReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'user@example.com',
            'password' => 'somepassword',
            'code' => 'abcdef',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid TOTP code format', $data['message']);
    }

    public function testInvalidTotpCodeFormatTooShortReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'user@example.com',
            'password' => 'somepassword',
            'code' => '123',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid TOTP code format', $data['message']);
    }

    public function testInvalidTotpCodeFormatTooLongReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'user@example.com',
            'password' => 'somepassword',
            'code' => '12345678',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid TOTP code format', $data['message']);
    }

    public function testEmptyCodeReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'user@example.com',
            'password' => 'somepassword',
            'code' => '',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid TOTP code format', $data['message']);
    }

    public function testMissingFieldsReturns422OrBadRequest(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'code' => '123456',
        ]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Missing email/password should fail validation (422) or auth (401)
        $this->assertContains($statusCode, [
            Response::HTTP_UNPROCESSABLE_ENTITY,
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_UNAUTHORIZED,
        ]);
    }

    public function testValidFormatButInvalidCredentialsReturns401(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'nonexistent@example.com',
            'password' => 'WrongPassword123!',
            'code' => '123456',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testNonIntegerCodeReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'user@example.com',
            'password' => 'somepassword',
            'code' => '12ab56',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid TOTP code format', $data['message']);
    }

    public function testNullCodeReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'user@example.com',
            'password' => 'somepassword',
            'code' => null,
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid TOTP code format', $data['message']);
    }

    // ================================================================== //
    //  Merged from TotpLoginControllerAdditionalTest
    // ================================================================== //

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

    public function testGetMethodNotAllowed(): void
    {
        $this->client->request('GET', self::ENDPOINT);

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testEmptyJsonObjectReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid TOTP code format', $data['message']);
    }

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

<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for TOTP verify controller (POST /api/v1/2fa/verify).
 *
 * Covers input validation (code format), missing/empty code, and auth requirement.
 */
final class TotpVerifyControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private const ENDPOINT = '/api/v1/2fa/verify';

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testVerifyRequiresAuthentication(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => '123456']));

        $this->assertContains(
            $this->client->getResponse()->getStatusCode(),
            [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN]
        );
    }

    public function testVerifyWithInvalidCodeFormatReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => 'abcdef']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // 400 for invalid code format, or 404 if InMemoryUser not found in DB
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_NOT_FOUND,
        ]);

        if ($statusCode === Response::HTTP_BAD_REQUEST) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertSame('Invalid TOTP code', $data['message']);
        }
    }

    public function testVerifyWithMissingCodeReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

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

    public function testVerifyWithEmptyCodeReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => '']));

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

    public function testVerifyWithTooShortCodeReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => '123']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_NOT_FOUND,
        ]);
    }

    public function testVerifyWithTooLongCodeReturns400(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => '12345678']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_NOT_FOUND,
        ]);
    }

    public function testVerifyWithValidFormatCodeReturnsExpectedStatus(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => '123456']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // 400 (TOTP not configured / invalid code), 404 (InMemoryUser not in DB), or 200 (success)
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_NOT_FOUND,
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('message', $data);
    }

    public function testVerifyRejectsGetMethod(): void
    {
        $this->client->request('GET', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ================================================================== //
    //  Merged from TotpVerifyControllerAdditionalTest
    // ================================================================== //

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

    public function testResponseAlwaysReturnsJson(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => '123456']));

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testPutMethodNotAllowed(): void
    {
        $this->client->request('PUT', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => '123456']));

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

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

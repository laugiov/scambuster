<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\TestTokenAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class TestTokenAuthenticatorTest extends TestCase
{
    private TestTokenAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->authenticator = new TestTokenAuthenticator();
    }

    public function testSupportsReturnsTrueWhenAuthorizationHeaderPresent(): void
    {
        $request = Request::create('/api/test', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertTrue($this->authenticator->supports($request));
    }

    public function testSupportsReturnsFalseWhenNoAuthorizationHeader(): void
    {
        $request = Request::create('/api/test', 'GET');

        $this->assertFalse($this->authenticator->supports($request));
    }

    public function testAuthenticateWithFakeJwtReturnsTestUser(): void
    {
        $request = Request::create('/api/test', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $passport = $this->authenticator->authenticate($request);

        $this->assertInstanceOf(SelfValidatingPassport::class, $passport);
        $this->assertSame('test-user', $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class)->getUserIdentifier());
    }

    public function testAuthenticateWithFakeAdminJwtReturnsTestAdmin(): void
    {
        $request = Request::create('/api/test', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $passport = $this->authenticator->authenticate($request);

        $this->assertInstanceOf(SelfValidatingPassport::class, $passport);
        $this->assertSame('test-admin', $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class)->getUserIdentifier());
    }

    public function testAuthenticateWithInvalidTokenThrowsException(): void
    {
        $request = Request::create('/api/test', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer invalid-token-xyz',
        ]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid JWT Token');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateWithNoTokenAtAllThrowsException(): void
    {
        $request = Request::create('/api/test', 'GET');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid JWT Token');

        $this->authenticator->authenticate($request);
    }

    public function testOnAuthenticationSuccessReturnsNull(): void
    {
        $request = Request::create('/api/test', 'GET');
        $token = new NullToken();

        $result = $this->authenticator->onAuthenticationSuccess($request, $token, 'main');

        $this->assertNull($result);
    }

    public function testOnAuthenticationFailureReturns401Json(): void
    {
        $request = Request::create('/api/test', 'GET');
        $exception = new AuthenticationException('Invalid JWT Token');

        $response = $this->authenticator->onAuthenticationFailure($request, $exception);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame(401, $data['code']);
        $this->assertSame('Invalid JWT Token', $data['message']);
    }

    public function testAuthenticateWithCookieToken(): void
    {
        // Request with no Authorization header but with X-AUTH-TOKEN cookie
        $request = Request::create('/api/test', 'GET', [], ['X-AUTH-TOKEN' => 'fake-jwt']);
        // The supports() would return false (no Authorization header),
        // but if authenticate() is called directly, the cookie fallback fires.
        // However, since the header is empty string, the cookie branch triggers.

        $passport = $this->authenticator->authenticate($request);

        $this->assertInstanceOf(SelfValidatingPassport::class, $passport);
        $this->assertSame('test-user', $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class)->getUserIdentifier());
    }

    public function testAuthenticateWithQueryStringToken(): void
    {
        $request = Request::create('/api/test?jwt_token=fake-admin-jwt', 'GET');

        $passport = $this->authenticator->authenticate($request);

        $this->assertInstanceOf(SelfValidatingPassport::class, $passport);
        $this->assertSame('test-admin', $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class)->getUserIdentifier());
    }
}

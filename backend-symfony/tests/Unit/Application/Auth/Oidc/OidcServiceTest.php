<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Auth\Oidc;

use App\Application\Auth\Oidc\OidcConfig;
use App\Application\Auth\Oidc\OidcException;
use App\Application\Auth\Oidc\OidcFlowState;
use App\Application\Auth\Oidc\OidcService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OidcServiceTest extends TestCase
{
    private const NOW = '2026-01-01 12:00:00';

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
    }

    private function flow(): OidcFlowState
    {
        return new OidcFlowState(
            state: 'state-abc',
            nonce: 'nonce-xyz',
            codeVerifier: 'verifier-123',
            codeChallenge: 'challenge-123',
            expiresAt: $this->clock->now()->getTimestamp() + 600,
        );
    }

    private function config(bool $enabled = true, array $allowedDomains = []): OidcConfig
    {
        return new OidcConfig(
            enabled: $enabled,
            discoveryUrl: 'https://idp.test/.well-known/openid-configuration',
            clientId: 'client-123',
            clientSecret: 'top-secret',
            redirectUri: 'https://app.test/api/v1/auth/oidc/callback',
            scopes: 'openid email profile',
            autoProvision: false,
            allowedEmailDomains: $allowedDomains,
            defaultRoles: ['ROLE_USER'],
            successRedirect: '',
        );
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function idToken(array $claims): string
    {
        $b64 = static fn (array $x): string => rtrim(strtr(base64_encode((string) json_encode($x)), '+/', '-_'), '=');

        // No signature verification (back-channel TLS trust) — the third segment is inert.
        return $b64(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $b64($claims) . '.signature';
    }

    /**
     * @param array<string, mixed> $tokenResponse
     * @param array<string, mixed> $userInfo
     */
    private function service(array $tokenResponse, array $userInfo, ?OidcConfig $config = null): OidcService
    {
        $discovery = [
            'issuer'                 => 'https://idp.test',
            'authorization_endpoint' => 'https://idp.test/authorize',
            'token_endpoint'         => 'https://idp.test/token',
            'userinfo_endpoint'      => 'https://idp.test/userinfo',
        ];

        $http = new MockHttpClient(function (string $method, string $url) use ($discovery, $tokenResponse, $userInfo): MockResponse {
            if (str_contains($url, '.well-known')) {
                return new MockResponse((string) json_encode($discovery));
            }

            if (str_contains($url, '/token')) {
                return new MockResponse((string) json_encode($tokenResponse));
            }

            if (str_contains($url, '/userinfo')) {
                return new MockResponse((string) json_encode($userInfo));
            }

            return new MockResponse('{}', ['http_code' => 404]);
        });

        return new OidcService($http, $config ?? $this->config(), $this->clock, new NullLogger());
    }

    /**
     * @param array<string, mixed> $claimOverrides
     *
     * @return array<string, mixed>
     */
    private function validTokenResponse(array $claimOverrides = []): array
    {
        $claims = array_merge([
            'iss'   => 'https://idp.test',
            'aud'   => 'client-123',
            'nonce' => 'nonce-xyz',
            'sub'   => 'sub-1',
            'email' => 'alice@corp.test',
            'name'  => 'Alice Example',
            'exp'   => $this->clock->now()->getTimestamp() + 3600,
        ], $claimOverrides);

        return ['id_token' => $this->idToken($claims), 'access_token' => 'access-1'];
    }

    /**
     * @return array<string, mixed>
     */
    private function validUserInfo(array $overrides = []): array
    {
        return array_merge([
            'sub'            => 'sub-1',
            'email'          => 'alice@corp.test',
            'email_verified' => true,
            'name'           => 'Alice Example',
        ], $overrides);
    }

    #[Test]
    public function it_builds_a_spec_compliant_authorization_url(): void
    {
        $service = $this->service($this->validTokenResponse(), $this->validUserInfo());

        $url = $service->buildAuthorizationUrl($this->flow());

        self::assertStringStartsWith('https://idp.test/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        self::assertSame('code', $q['response_type']);
        self::assertSame('client-123', $q['client_id']);
        self::assertSame('https://app.test/api/v1/auth/oidc/callback', $q['redirect_uri']);
        self::assertSame('openid email profile', $q['scope']);
        self::assertSame('state-abc', $q['state']);
        self::assertSame('nonce-xyz', $q['nonce']);
        self::assertSame('challenge-123', $q['code_challenge']);
        self::assertSame('S256', $q['code_challenge_method']);
    }

    #[Test]
    public function it_resolves_a_verified_identity_on_the_happy_path(): void
    {
        $service = $this->service($this->validTokenResponse(), $this->validUserInfo());

        $identity = $service->exchangeAndVerify('auth-code', $this->flow());

        self::assertSame('alice@corp.test', $identity->email);
        self::assertSame('sub-1', $identity->subject);
        self::assertSame('Alice Example', $identity->displayName);
    }

    #[Test]
    public function it_lowercases_the_email(): void
    {
        $service = $this->service(
            $this->validTokenResponse(['email' => 'Alice@Corp.Test']),
            $this->validUserInfo(['email' => 'Alice@Corp.Test']),
        );

        $identity = $service->exchangeAndVerify('auth-code', $this->flow());

        self::assertSame('alice@corp.test', $identity->email);
    }

    #[Test]
    public function it_rejects_a_token_endpoint_error(): void
    {
        $service = $this->service(['error' => 'invalid_grant'], $this->validUserInfo());

        $this->expectException(OidcException::class);
        $service->exchangeAndVerify('auth-code', $this->flow());
    }

    #[Test]
    public function it_rejects_an_issuer_mismatch(): void
    {
        $service = $this->service($this->validTokenResponse(['iss' => 'https://evil.test']), $this->validUserInfo());

        $this->expectException(OidcException::class);
        $service->exchangeAndVerify('auth-code', $this->flow());
    }

    #[Test]
    public function it_rejects_an_audience_mismatch(): void
    {
        $service = $this->service($this->validTokenResponse(['aud' => 'someone-else']), $this->validUserInfo());

        $this->expectException(OidcException::class);
        $service->exchangeAndVerify('auth-code', $this->flow());
    }

    #[Test]
    public function it_rejects_a_nonce_mismatch(): void
    {
        $service = $this->service($this->validTokenResponse(['nonce' => 'replayed']), $this->validUserInfo());

        $this->expectException(OidcException::class);
        $service->exchangeAndVerify('auth-code', $this->flow());
    }

    #[Test]
    public function it_rejects_an_expired_id_token(): void
    {
        $service = $this->service(
            $this->validTokenResponse(['exp' => $this->clock->now()->getTimestamp() - 1]),
            $this->validUserInfo(),
        );

        $this->expectException(OidcException::class);
        $service->exchangeAndVerify('auth-code', $this->flow());
    }

    #[Test]
    public function it_rejects_when_userinfo_subject_does_not_match_the_id_token(): void
    {
        $service = $this->service($this->validTokenResponse(), $this->validUserInfo(['sub' => 'different-sub']));

        $this->expectException(OidcException::class);
        $service->exchangeAndVerify('auth-code', $this->flow());
    }

    #[Test]
    public function it_rejects_an_unverified_email(): void
    {
        $service = $this->service($this->validTokenResponse(), $this->validUserInfo(['email_verified' => false]));

        $this->expectException(OidcException::class);
        $service->exchangeAndVerify('auth-code', $this->flow());
    }

    #[Test]
    public function it_rejects_email_verified_encoded_as_integer_zero(): void
    {
        // Some IdPs serialize the boolean as 0/1 — 0 must NOT pass as verified.
        $service = $this->service($this->validTokenResponse(), $this->validUserInfo(['email_verified' => 0]));

        $this->expectException(OidcException::class);
        $service->exchangeAndVerify('auth-code', $this->flow());
    }

    #[Test]
    public function it_accepts_string_true_email_verified(): void
    {
        $service = $this->service($this->validTokenResponse(), $this->validUserInfo(['email_verified' => 'true']));

        $identity = $service->exchangeAndVerify('auth-code', $this->flow());

        self::assertSame('alice@corp.test', $identity->email);
    }

    #[Test]
    public function it_rejects_azp_mismatch_on_a_multi_audience_token(): void
    {
        $service = $this->service(
            $this->validTokenResponse(['aud' => ['client-123', 'another-app'], 'azp' => 'another-app']),
            $this->validUserInfo(),
        );

        $this->expectException(OidcException::class);
        $service->exchangeAndVerify('auth-code', $this->flow());
    }

    #[Test]
    public function it_rejects_a_disallowed_email_domain(): void
    {
        $service = $this->service(
            $this->validTokenResponse(['email' => 'bob@other.test']),
            $this->validUserInfo(['email' => 'bob@other.test']),
            $this->config(allowedDomains: ['corp.test']),
        );

        $this->expectException(OidcException::class);
        $service->exchangeAndVerify('auth-code', $this->flow());
    }

    #[Test]
    public function it_rejects_when_no_email_is_returned(): void
    {
        $service = $this->service(
            $this->validTokenResponse(['email' => null]),
            $this->validUserInfo(['email' => null]),
        );

        $this->expectException(OidcException::class);
        $service->exchangeAndVerify('auth-code', $this->flow());
    }
}

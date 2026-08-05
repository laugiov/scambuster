<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Application\Auth\Oidc\OidcConfig;
use App\Application\Auth\Oidc\OidcFlowState;
use App\Application\Auth\Oidc\OidcService;
use App\Application\Auth\Oidc\OidcStateManager;
use App\UI\Http\Auth\OidcLoginController;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Guards the opt-in contract: SSO is OFF by default (endpoints 404, password login
 * untouched); when switched on it starts a proper authorization-code redirect.
 */
final class OidcSsoAccessTest extends WebTestCase
{
    #[Test]
    public function login_returns_404_when_sso_is_disabled(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/auth/oidc/login');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    #[Test]
    public function callback_returns_404_when_sso_is_disabled(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/auth/oidc/callback?code=x&state=y');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    #[Test]
    public function login_redirects_to_the_idp_and_sets_a_state_cookie_when_enabled(): void
    {
        $client = static::createClient();
        $this->enableSso($client);

        $client->request('GET', '/api/v1/auth/oidc/login');
        $response = $client->getResponse();

        self::assertSame(302, $response->getStatusCode());
        self::assertStringStartsWith('https://idp.test/authorize', (string) $response->headers->get('Location'));

        $cookieNames = array_map(static fn ($c) => $c->getName(), $response->headers->getCookies());
        self::assertContains(OidcLoginController::STATE_COOKIE, $cookieNames);
    }

    #[Test]
    public function callback_without_the_state_cookie_is_unauthorized_when_enabled(): void
    {
        $client = static::createClient();
        $this->enableSso($client);

        $client->request('GET', '/api/v1/auth/oidc/callback?code=abc&state=def');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    #[Test]
    public function callback_completes_the_flow_and_returns_local_session_tokens(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $container = static::getContainer();

        $clock = new MockClock();
        $config = new OidcConfig(
            enabled: true,
            discoveryUrl: 'https://idp.test/.well-known/openid-configuration',
            clientId: 'client-123',
            clientSecret: 'top-secret',
            redirectUri: 'https://app.test/api/v1/auth/oidc/callback',
            scopes: 'openid email',
            autoProvision: true, // create the mapped user inside the test transaction
            allowedEmailDomains: [],
            defaultRoles: ['ROLE_USER'],
            successRedirect: '',
        );

        $b64 = static fn (array $x): string => rtrim(strtr(base64_encode((string) json_encode($x)), '+/', '-_'), '=');
        $idToken = $b64(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $b64([
            'iss'   => 'https://idp.test',
            'aud'   => 'client-123',
            'nonce' => 'fixed-nonce',
            'sub'   => 'sub-1',
            'email' => 'sso.user@corp.test',
            'exp'   => $clock->now()->getTimestamp() + 3600,
        ]) . '.sig';

        $http = new MockHttpClient(static function (string $method, string $url) use ($b64, $idToken): MockResponse {
            if (str_contains($url, '.well-known')) {
                return new MockResponse((string) json_encode([
                    'issuer'                 => 'https://idp.test',
                    'authorization_endpoint' => 'https://idp.test/authorize',
                    'token_endpoint'         => 'https://idp.test/token',
                    'userinfo_endpoint'      => 'https://idp.test/userinfo',
                ]));
            }

            if (str_contains($url, '/token')) {
                return new MockResponse((string) json_encode(['id_token' => $idToken, 'access_token' => 'at-1']));
            }

            return new MockResponse((string) json_encode([
                'sub'            => 'sub-1',
                'email'          => 'sso.user@corp.test',
                'email_verified' => true,
            ]));
        });

        $container->set(OidcConfig::class, $config);
        $container->set(OidcService::class, new OidcService($http, $config, $clock, new NullLogger()));

        // Craft the signed state cookie the /login leg would have set.
        $stateManager = $container->get(OidcStateManager::class);
        $flow = new OidcFlowState('fixed-state', 'fixed-nonce', 'verifier', 'challenge', time() + 3600);
        $cookie = $stateManager->serialize($flow);
        $client->getCookieJar()->set(
            new BrowserKitCookie(OidcLoginController::STATE_COOKIE, $cookie, null, '/', 'localhost'),
        );

        $client->request('GET', '/api/v1/auth/oidc/callback?code=the-code&state=fixed-state');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame('fake-jwt', $payload['access_token']);
        self::assertSame('fake-refresh', $payload['refresh_token']);
        self::assertArrayHasKey('expires_in', $payload);

        // The one-time state cookie must be cleared.
        $cleared = array_filter(
            $response->headers->getCookies(),
            static fn ($c) => $c->getName() === OidcLoginController::STATE_COOKIE && $c->getValue() === null,
        );
        self::assertNotEmpty($cleared, 'state cookie should be cleared on success');
    }

    private function enableSso(KernelBrowser $client): void
    {
        $client->disableReboot();
        $container = static::getContainer();

        $config = new OidcConfig(
            enabled: true,
            discoveryUrl: 'https://idp.test/.well-known/openid-configuration',
            clientId: 'client-123',
            clientSecret: 'top-secret',
            redirectUri: 'https://app.test/api/v1/auth/oidc/callback',
            scopes: 'openid email profile',
            autoProvision: false,
            allowedEmailDomains: [],
            defaultRoles: ['ROLE_USER'],
            successRedirect: '',
        );

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode([
            'issuer'                 => 'https://idp.test',
            'authorization_endpoint' => 'https://idp.test/authorize',
            'token_endpoint'         => 'https://idp.test/token',
            'userinfo_endpoint'      => 'https://idp.test/userinfo',
        ])));

        $container->set(OidcConfig::class, $config);
        $container->set(OidcService::class, new OidcService($http, $config, new MockClock(), new NullLogger()));
    }
}

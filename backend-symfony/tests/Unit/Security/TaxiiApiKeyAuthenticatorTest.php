<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\TaxiiApiKeyAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class TaxiiApiKeyAuthenticatorTest extends TestCase
{
    /**
     * Long enough to pass the authenticator's 32-character floor, and
     * deliberately low-entropy: a random-looking literal here trips secret
     * scanners (gitleaks flagged the previous value as a generic-api-key).
     */
    private const KEY = 'not-a-real-key-not-a-real-key-not-a-real-key-not-a-real-key';

    /**
     * @param array<string, string> $server
     */
    private static function request(string $path, array $server = []): Request
    {
        return Request::create($path, 'GET', [], [], [], $server);
    }

    /**
     * @return array<string, string>
     */
    private static function basic(string $user, string $password): array
    {
        return ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($user . ':' . $password)];
    }

    public function testAcceptsTheKeyAsBasicPassword(): void
    {
        $authenticator = new TaxiiApiKeyAuthenticator(self::KEY);
        $request = self::request('/api/v1/taxii2/', self::basic('taxii', self::KEY));

        self::assertTrue($authenticator->supports($request));

        $passport = $authenticator->authenticate($request);
        $user = $passport->getUser();

        self::assertSame('taxii-feed', $user->getUserIdentifier());
        self::assertSame([TaxiiApiKeyAuthenticator::ROLE_TAXII_FEED], $user->getRoles());
    }

    public function testAcceptsTheKeyAsHeader(): void
    {
        $authenticator = new TaxiiApiKeyAuthenticator(self::KEY);
        $request = self::request('/api/v1/taxii2/api/collections/', ['HTTP_X_TAXII_API_KEY' => self::KEY]);

        self::assertTrue($authenticator->supports($request));
        self::assertSame('taxii-feed', $authenticator->authenticate($request)->getUser()->getUserIdentifier());
    }

    public function testAcceptsTheKeyAsBasicUsernameWhenPasswordIsEmpty(): void
    {
        $authenticator = new TaxiiApiKeyAuthenticator(self::KEY);
        $request = self::request('/api/v1/taxii2/', self::basic(self::KEY, ''));

        self::assertTrue($authenticator->supports($request));
        self::assertSame('taxii-feed', $authenticator->authenticate($request)->getUser()->getUserIdentifier());
    }

    /**
     * The whole point of the design: a Bearer value is the JWT authenticator's
     * business. Claiming it here would make both authenticators run and let the
     * JWT failure override our success.
     */
    public function testIgnoresBearerCredentialsEntirely(): void
    {
        $authenticator = new TaxiiApiKeyAuthenticator(self::KEY);
        $request = self::request('/api/v1/taxii2/', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::KEY]);

        self::assertFalse($authenticator->supports($request));
    }

    public function testIgnoresEveryPathOutsideTheFeed(): void
    {
        $authenticator = new TaxiiApiKeyAuthenticator(self::KEY);

        foreach (['/api/v1/communication/conversation', '/api/v1/iocs', '/healthz'] as $path) {
            self::assertFalse(
                $authenticator->supports(self::request($path, self::basic('taxii', self::KEY))),
                $path . ' must not be authenticable with the feed key'
            );
        }
    }

    public function testRejectsAWrongKey(): void
    {
        $authenticator = new TaxiiApiKeyAuthenticator(self::KEY);
        $request = self::request('/api/v1/taxii2/', self::basic('taxii', str_repeat('f', 64)));

        // supports() is true — a credential of our kind is present — but the
        // comparison in authenticate() must reject it.
        self::assertTrue($authenticator->supports($request));

        $this->expectException(AuthenticationException::class);
        $authenticator->authenticate($request);
    }

    public function testUnsetKeyLeavesTheFeatureOff(): void
    {
        $authenticator = new TaxiiApiKeyAuthenticator('');

        self::assertFalse($authenticator->supports(self::request('/api/v1/taxii2/', self::basic('taxii', ''))));
    }

    /**
     * A short key is a misconfiguration, not a credential: it must disable the
     * feature rather than expose the feed behind something guessable.
     */
    public function testTooShortKeyIsIgnored(): void
    {
        $short = 'tooshort';
        $authenticator = new TaxiiApiKeyAuthenticator($short);

        self::assertFalse($authenticator->supports(self::request('/api/v1/taxii2/', self::basic('taxii', $short))));
    }

    public function testRequestWithoutAnyCredentialFallsThrough(): void
    {
        $authenticator = new TaxiiApiKeyAuthenticator(self::KEY);

        self::assertFalse($authenticator->supports(self::request('/api/v1/taxii2/')));
    }

    public function testFailureResponseNeverEchoesTheCredential(): void
    {
        $authenticator = new TaxiiApiKeyAuthenticator(self::KEY);
        $response = $authenticator->onAuthenticationFailure(
            self::request('/api/v1/taxii2/', self::basic('taxii', 'wrong-key-value')),
            new AuthenticationException('Invalid TAXII API key')
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertStringNotContainsString('wrong-key-value', (string) $response->getContent());
        self::assertStringNotContainsString(self::KEY, (string) $response->getContent());
    }
}

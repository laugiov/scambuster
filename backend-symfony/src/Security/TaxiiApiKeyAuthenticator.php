<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Static-API-key authentication for the read-only TAXII 2.1 feed.
 *
 * TAXII consumers (OpenCTI, MISP, TheHive, SIEMs) poll unattended on a schedule
 * and store one credential; they cannot refresh it. The platform JWT lives 900
 * seconds, so a feed configured with one stops ingesting after 15 minutes —
 * observed against OpenCTI, which then logs `Feed fetch failed / 401` on every
 * subsequent poll. This authenticator gives such consumers a credential that
 * does not expire, without touching the JWT lifetime protecting the rest of the
 * API.
 *
 * The key travels as HTTP Basic (password field) or in the `X-TAXII-API-KEY`
 * header — deliberately NOT as `Authorization: Bearer`. Symfony executes every
 * authenticator whose supports() returns true and lets the last response win;
 * since the JWT authenticator claims any Bearer value, a Bearer key would
 * authenticate here and then be overridden by its "Invalid JWT Token" failure.
 * Keeping the key out of the Bearer namespace leaves exactly one authenticator
 * in play per request, and analyst JWTs keep working on the same URLs.
 *
 * Deliberately narrow:
 *  - only `^/api/v1/taxii2` paths are eligible; every other route is untouched;
 *  - the key authenticates a synthetic principal holding ROLE_TAXII_FEED, which
 *    {@see PermissionVoter} maps to `ioc:read` and nothing else — a leaked feed
 *    key can read the feed, never write or reach another endpoint;
 *  - unset, blank or too-short keys leave the feature off, so an install that
 *    never configures one keeps JWT-only behaviour.
 */
final class TaxiiApiKeyAuthenticator extends AbstractAuthenticator
{
    /** Path prefix this authenticator is allowed to act on. */
    public const TAXII_PATH_PREFIX = '/api/v1/taxii2';

    /** Role granted to the feed principal. Mapped to `ioc:read` by PermissionVoter. */
    public const ROLE_TAXII_FEED = 'ROLE_TAXII_FEED';

    /** Header alternative to HTTP Basic, for curl and scripted consumers. */
    public const API_KEY_HEADER = 'X-TAXII-API-KEY';

    /**
     * Shortest accepted key. 32 chars is the length of `openssl rand -hex 16`;
     * anything shorter is treated as a misconfiguration and disables the key
     * rather than exposing the feed to a guessable credential.
     */
    private const MIN_KEY_LENGTH = 32;

    public function __construct(
        private readonly string $taxiiApiKey = '',
    ) {
    }

    public function supports(Request $request): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if (!str_starts_with($request->getPathInfo(), self::TAXII_PATH_PREFIX)) {
            return false;
        }

        // No credential of our kind → let the JWT authenticator handle it.
        return $this->presentedKey($request) !== null;
    }

    public function authenticate(Request $request): Passport
    {
        $presented = $this->presentedKey($request);

        if ($presented === null || !hash_equals($this->taxiiApiKey, $presented)) {
            throw new AuthenticationException('Invalid TAXII API key');
        }

        return new SelfValidatingPassport(
            new UserBadge(
                'taxii-feed',
                static fn (string $identifier): InMemoryUser => new InMemoryUser($identifier, null, [self::ROLE_TAXII_FEED])
            )
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null; // continue to the controller
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // Message is deliberately generic: never echo back anything derived
        // from the presented credential.
        return new JsonResponse(
            ['code' => Response::HTTP_UNAUTHORIZED, 'message' => 'Invalid TAXII API key'],
            Response::HTTP_UNAUTHORIZED
        );
    }

    private function isEnabled(): bool
    {
        return \strlen($this->taxiiApiKey) >= self::MIN_KEY_LENGTH;
    }

    /**
     * The candidate key this request presents, or null when it presents none.
     * Validation happens in authenticate(); this only locates the value.
     */
    private function presentedKey(Request $request): ?string
    {
        $header = $request->headers->get(self::API_KEY_HEADER);

        if (\is_string($header) && trim($header) !== '') {
            return trim($header);
        }

        // HTTP Basic. The key belongs in the password field; accepting it in
        // the username covers clients that send `<key>:` with no password.
        $password = $request->getPassword();
        $user = $request->getUser();

        if (!\is_string($password) || $password === '') {
            $password = null;
        }

        if (!\is_string($user) || $user === '') {
            $user = null;
        }

        if ($password === null && $user === null) {
            // PHP_AUTH_* is not populated by every SAPI — parse the raw header.
            [$user, $password] = $this->decodeBasicHeader($request);
        }

        return $password ?? $user;
    }

    /**
     * @return array{0: string|null, 1: string|null} username, password
     */
    private function decodeBasicHeader(Request $request): array
    {
        $authorization = $request->headers->get('Authorization');

        if (!\is_string($authorization) || preg_match('/^Basic\s+(\S+)$/i', $authorization, $m) !== 1) {
            return [null, null];
        }

        $decoded = base64_decode($m[1], true);

        if (!\is_string($decoded) || !str_contains($decoded, ':')) {
            return [null, null];
        }

        [$user, $password] = explode(':', $decoded, 2);

        return [$user !== '' ? $user : null, $password !== '' ? $password : null];
    }
}

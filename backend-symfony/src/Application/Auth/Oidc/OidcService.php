<?php

declare(strict_types=1);

namespace App\Application\Auth\Oidc;

use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Generic OIDC Authorization-Code client (works with any spec-compliant IdP:
 * Keycloak, Entra ID, Okta, Google, Auth0…). Discovery-driven, PKCE-protected,
 * back-channel token exchange with a UserInfo cross-check.
 *
 * ID-token trust: the token is fetched directly from the token endpoint over a
 * TLS back-channel authenticated with the client secret, so per OIDC Core
 * §3.1.3.7 signature validation MAY be omitted. We still validate iss/aud/exp/
 * nonce and, as a second independent proof, call the UserInfo endpoint with the
 * access token and require its `sub` to match the ID token. Optional JWKS
 * signature verification is noted as a hardening step in docs/20_enterprise_sso.md.
 */
final class OidcService
{
    /** @var array<string, mixed>|null */
    private ?array $metadata = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OidcConfig $config,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function buildAuthorizationUrl(OidcFlowState $state): string
    {
        $meta = $this->metadata();

        $params = http_build_query([
            'response_type'         => 'code',
            'client_id'             => $this->config->clientId,
            'redirect_uri'          => $this->config->redirectUri,
            'scope'                 => $this->config->scopes,
            'state'                 => $state->state,
            'nonce'                 => $state->nonce,
            'code_challenge'        => $state->codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return $this->requireString($meta, 'authorization_endpoint') . '?' . $params;
    }

    public function exchangeAndVerify(string $code, OidcFlowState $state): OidcIdentity
    {
        $meta = $this->metadata();

        $tokens = $this->postTokenEndpoint($this->requireString($meta, 'token_endpoint'), $code, $state);

        $idClaims = $this->decodeIdToken(self::str($tokens['id_token'] ?? null));
        $this->validateIdClaims($idClaims, $state, $this->requireString($meta, 'issuer'));

        $userInfo = $this->fetchUserInfo(
            $this->requireString($meta, 'userinfo_endpoint'),
            self::str($tokens['access_token'] ?? null),
        );

        // Second, independent proof the access token is genuinely valid at the IdP.
        if (($userInfo['sub'] ?? null) !== ($idClaims['sub'] ?? null)) {
            throw new OidcException('UserInfo subject does not match ID token.');
        }

        $email = self::str($userInfo['email'] ?? $idClaims['email'] ?? null);

        if ($email === '') {
            throw new OidcException('No email claim returned by the identity provider.');
        }

        // Require an affirmatively-verified address. Absent claim = trust the IdP
        // (many providers omit it); but any explicit non-true value (bool false,
        // "false", int 0, "0", "") is treated as unverified and refused.
        $verified = $userInfo['email_verified'] ?? $idClaims['email_verified'] ?? true;

        if ($verified !== true && $verified !== 'true') {
            throw new OidcException('Identity provider reports the email as unverified.');
        }

        if (!$this->config->isEmailDomainAllowed($email)) {
            throw new OidcException('Email domain is not allowed for SSO.');
        }

        $name = $userInfo['name'] ?? $idClaims['name'] ?? null;

        return new OidcIdentity(
            subject: self::str($idClaims['sub'] ?? $userInfo['sub'] ?? null),
            email: strtolower($email),
            displayName: is_string($name) ? $name : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function postTokenEndpoint(string $endpoint, string $code, OidcFlowState $state): array
    {
        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => ['Accept' => 'application/json'],
                'body'    => [
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                    'redirect_uri'  => $this->config->redirectUri,
                    'client_id'     => $this->config->clientId,
                    'client_secret' => $this->config->clientSecret,
                    'code_verifier' => $state->codeVerifier,
                ],
            ]);

            /** @var array<string, mixed> $data */
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('OIDC token exchange failed', ['error' => $e->getMessage()]);

            throw new OidcException('OIDC token exchange failed.');
        }

        if (isset($data['error'])) {
            throw new OidcException('OIDC token endpoint returned an error.');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchUserInfo(string $endpoint, string $accessToken): array
    {
        if ($accessToken === '') {
            throw new OidcException('Identity provider returned no access token.');
        }

        try {
            $response = $this->httpClient->request('GET', $endpoint, [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json'],
            ]);

            /** @var array<string, mixed> $data */
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('OIDC userinfo call failed', ['error' => $e->getMessage()]);

            throw new OidcException('OIDC userinfo call failed.');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeIdToken(string $idToken): array
    {
        $segments = explode('.', $idToken);

        if (count($segments) < 2) {
            throw new OidcException('Malformed ID token.');
        }

        $payload = base64_decode(strtr($segments[1], '-_', '+/'), true);

        if ($payload === false) {
            throw new OidcException('Malformed ID token payload.');
        }

        try {
            /** @var array<string, mixed> $claims */
            $claims = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new OidcException('Unparseable ID token payload.');
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function validateIdClaims(array $claims, OidcFlowState $state, string $expectedIssuer): void
    {
        if (($claims['iss'] ?? null) !== $expectedIssuer) {
            throw new OidcException('ID token issuer mismatch.');
        }

        $aud = $claims['aud'] ?? null;
        $audMatches = is_array($aud)
            ? in_array($this->config->clientId, $aud, true)
            : $aud === $this->config->clientId;

        if (!$audMatches) {
            throw new OidcException('ID token audience mismatch.');
        }

        // OIDC Core §3.1.3.7: a multi-valued `aud` SHOULD carry `azp`, and when
        // present it MUST equal our client_id.
        if (is_array($aud) && count($aud) > 1 && isset($claims['azp']) && $claims['azp'] !== $this->config->clientId) {
            throw new OidcException('ID token authorized-party (azp) mismatch.');
        }

        if (($claims['nonce'] ?? null) !== $state->nonce) {
            throw new OidcException('ID token nonce mismatch.');
        }

        $exp = self::int($claims['exp'] ?? null);

        if ($exp <= $this->clock->now()->getTimestamp()) {
            throw new OidcException('ID token has expired.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(): array
    {
        if ($this->metadata !== null) {
            return $this->metadata;
        }

        try {
            $response = $this->httpClient->request('GET', $this->config->discoveryUrl, [
                'headers' => ['Accept' => 'application/json'],
            ]);

            /** @var array<string, mixed> $data */
            $data = $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('OIDC discovery failed', ['error' => $e->getMessage()]);

            throw new OidcException('OIDC discovery document could not be loaded.');
        }

        return $this->metadata = $data;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function requireString(array $meta, string $key): string
    {
        $value = $meta[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new OidcException(sprintf('OIDC discovery document is missing "%s".', $key));
        }

        return $value;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}

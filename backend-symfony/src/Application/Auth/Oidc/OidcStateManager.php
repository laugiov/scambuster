<?php

declare(strict_types=1);

namespace App\Application\Auth\Oidc;

use Symfony\Component\Clock\ClockInterface;

/**
 * Issues and verifies the short-lived, HMAC-signed state carried across the OIDC
 * authorization-code round-trip.
 *
 * The value is a self-contained token (no server-side session — the API firewall
 * is stateless): base64url(payload).base64url(hmac). It carries the CSRF `state`,
 * the replay-defeating `nonce`, and the PKCE `code_verifier`, plus an expiry.
 * It is delivered to the browser as a short-lived HttpOnly+Secure cookie and
 * echoed back on the callback.
 */
final readonly class OidcStateManager
{
    private const TTL_SECONDS = 600; // 10 minutes — an auth round-trip is seconds

    public function __construct(
        private string $appSecret,
        private ClockInterface $clock,
    ) {
    }

    public function issue(): OidcFlowState
    {
        $verifier = self::base64Url(random_bytes(64));
        $challenge = self::base64Url(hash('sha256', $verifier, true));

        return new OidcFlowState(
            state: self::base64Url(random_bytes(32)),
            nonce: self::base64Url(random_bytes(32)),
            codeVerifier: $verifier,
            codeChallenge: $challenge,
            expiresAt: $this->clock->now()->getTimestamp() + self::TTL_SECONDS,
        );
    }

    public function serialize(OidcFlowState $state): string
    {
        $payload = self::base64Url((string) json_encode([
            's' => $state->state,
            'n' => $state->nonce,
            'v' => $state->codeVerifier,
            'e' => $state->expiresAt,
        ], JSON_THROW_ON_ERROR));

        return $payload . '.' . $this->sign($payload);
    }

    /**
     * @throws OidcException on tampering, malformed input or expiry
     */
    public function parse(string $cookie): OidcFlowState
    {
        $parts = explode('.', $cookie);

        if (count($parts) !== 2) {
            throw new OidcException('Malformed OIDC state.');
        }

        [$payload, $mac] = $parts;

        // Constant-time comparison — reject any tampering with the payload.
        if (!hash_equals($this->sign($payload), $mac)) {
            throw new OidcException('OIDC state signature mismatch.');
        }

        try {
            $decoded = json_decode(self::base64UrlDecode($payload), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new OidcException('Unparseable OIDC state payload.');
        }

        if (!is_array($decoded) || !isset($decoded['s'], $decoded['n'], $decoded['v'], $decoded['e'])) {
            throw new OidcException('Incomplete OIDC state.');
        }

        $state = $decoded['s'];
        $nonce = $decoded['n'];
        $verifier = $decoded['v'];
        $exp = $decoded['e'];

        if (!is_string($state) || !is_string($nonce) || !is_string($verifier) || !is_int($exp)) {
            throw new OidcException('Malformed OIDC state fields.');
        }

        if ($exp < $this->clock->now()->getTimestamp()) {
            throw new OidcException('OIDC state expired.');
        }

        // codeChallenge is not needed on the return leg (only the verifier is sent
        // to the token endpoint); recompute it for a complete object anyway.
        return new OidcFlowState(
            state: $state,
            nonce: $nonce,
            codeVerifier: $verifier,
            codeChallenge: self::base64Url(hash('sha256', $verifier, true)),
            expiresAt: $exp,
        );
    }

    public static function cookieMaxAge(): int
    {
        return self::TTL_SECONDS;
    }

    private function sign(string $payload): string
    {
        return self::base64Url(hash_hmac('sha256', $payload, $this->appSecret, true));
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new OidcException('Invalid base64url in OIDC state.');
        }

        return $decoded;
    }
}

<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Rejects known-default and obviously-weak secret values in production.
 *
 * `.env.dist` ships valid-but-globally-known keys so the stack boots out of the box.
 * A production instance must never run on them: APP_SECRET signs cookies/CSRF,
 * AUDIT_HMAC_KEY signs the tamper-evidence chain, TOTP_ENCRYPTION_KEY protects 2FA
 * seeds — all forgeable by anyone who read the public repo.
 *
 * This is the single source of truth; the prod entrypoint enforces it by running
 * `app:security:check-secrets`, which delegates here. It only *strengthens* posture
 * and never enforces outside production, so dev/test/e2e keep booting on defaults.
 */
final class SecretPolicy
{
    /**
     * Exact published `.env.dist` defaults (and the documented admin password),
     * keyed by the variable they ship under. A value equal to its own default —
     * or to any other listed default — is rejected.
     *
     * @var array<string, string>
     */
    private const PUBLISHED_DEFAULTS = [
        'APP_SECRET'                => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
        'TOTP_ENCRYPTION_KEY'       => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'AUDIT_HMAC_KEY'            => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'N8N_ENCRYPTION_KEY'        => 'dev-only-change-in-production-openssl-rand-hex-32',
        'N8N_DEFAULT_USER_PASSWORD' => 'Scambuster2026!',
        'ADMIN_PASSWORD'            => 'Un1que$trongPassword2024',
    ];

    /**
     * Substrings that mark a placeholder rather than a real secret. Matched
     * case-insensitively anywhere in the value.
     *
     * @var list<string>
     */
    private const PLACEHOLDER_MARKERS = [
        'dev-only-change',
        'change-in-production',
        'changeme',
        'change-me',
        'changthis',
        'change-this',
        'placeholder',
        'example',
        'insecure',
    ];

    /**
     * Evaluate a set of secret values.
     *
     * @param array<string, string|null> $secrets variable name => value (null = absent, ignored)
     *
     * @return array<string, string> variable name => human-readable reason, for each violation
     *                               (empty when everything is acceptable, or when not in prod)
     */
    public function validate(array $secrets, bool $isProd): array
    {
        // Never enforced outside production: dev/test/e2e must keep booting on the
        // documented .env.dist defaults.
        if (!$isProd) {
            return [];
        }

        $violations = [];

        foreach ($secrets as $name => $value) {
            // Presence is enforced elsewhere (the entrypoint's `:?` guards); the
            // policy only judges values it is actually given.
            if ($value === null) {
                continue;
            }

            $reason = $this->reasonFor($value);

            if ($reason !== null) {
                $violations[$name] = $reason;
            }
        }

        return $violations;
    }

    /**
     * @return string|null the reason this value is unacceptable in prod, or null if it is fine
     */
    private function reasonFor(string $value): ?string
    {
        if ($value === '') {
            return 'is empty';
        }

        // Exact match against any published default (a value shipped for one
        // variable is just as public when reused for another).
        foreach (self::PUBLISHED_DEFAULTS as $default) {
            if (hash_equals($default, $value)) {
                return 'equals a published .env.dist default value';
            }
        }

        // A single character repeated (e.g. 64 × "a") carries no entropy.
        if (strspn($value, $value[0]) === strlen($value)) {
            return 'is a single repeated character (no entropy)';
        }

        $needle = strtolower($value);

        foreach (self::PLACEHOLDER_MARKERS as $marker) {
            if (str_contains($needle, $marker)) {
                return sprintf('looks like a placeholder (contains "%s")', $marker);
            }
        }

        if (str_starts_with($needle, 'your-') || str_starts_with($needle, 'your_')) {
            return 'looks like a placeholder (starts with "your-")';
        }

        return null;
    }
}

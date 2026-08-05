<?php

declare(strict_types=1);

namespace App\Application\Auth\Oidc;

/**
 * Immutable OIDC configuration, bound from environment variables (see services.yaml).
 *
 * Disabled by default: when {@see $enabled} is false the SSO endpoints return 404
 * and nothing in the password-login path is affected.
 */
final readonly class OidcConfig
{
    /** @var list<string> lowercase domains; empty = any domain accepted */
    public array $allowedEmailDomains;

    /** @var list<string> roles granted to auto-provisioned users */
    public array $defaultRoles;

    /**
     * @param list<string> $allowedEmailDomains lowercase domains; empty = any domain accepted
     * @param list<string> $defaultRoles        roles granted to auto-provisioned users
     */
    public function __construct(
        public bool $enabled,
        public string $discoveryUrl,
        public string $clientId,
        public string $clientSecret,
        public string $redirectUri,
        public string $scopes,
        public bool $autoProvision,
        array $allowedEmailDomains,
        array $defaultRoles,
        public string $successRedirect,
    ) {
        // An empty CSV env var can decode to [''] — normalise so an empty allow-list
        // means "any domain" rather than "no domain matches".
        $this->allowedEmailDomains = array_values(array_filter(
            array_map(static fn (string $d): string => strtolower(trim($d)), $allowedEmailDomains),
            static fn (string $d): bool => $d !== '',
        ));
        $this->defaultRoles = array_values(array_filter(
            array_map(static fn (string $r): string => trim($r), $defaultRoles),
            static fn (string $r): bool => $r !== '',
        ));
    }

    public function isEmailDomainAllowed(string $email): bool
    {
        if ($this->allowedEmailDomains === []) {
            return true;
        }

        $at = strrpos($email, '@');

        if ($at === false) {
            return false;
        }

        $domain = strtolower(substr($email, $at + 1));

        return in_array($domain, $this->allowedEmailDomains, true);
    }
}

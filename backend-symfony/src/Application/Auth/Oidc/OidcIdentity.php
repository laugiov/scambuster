<?php

declare(strict_types=1);

namespace App\Application\Auth\Oidc;

/**
 * The verified identity resolved from a completed OIDC round-trip: the IdP subject
 * plus the claims we trust after ID-token + UserInfo cross-validation.
 */
final readonly class OidcIdentity
{
    public function __construct(
        public string $subject,
        public string $email,
        public ?string $displayName = null,
    ) {
    }
}

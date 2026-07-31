<?php

declare(strict_types=1);

namespace App\Application\Auth\Oidc;

/**
 * Immutable per-login OIDC flow secrets, minted at /login and verified at /callback.
 */
final readonly class OidcFlowState
{
    public function __construct(
        public string $state,
        public string $nonce,
        public string $codeVerifier,
        public string $codeChallenge,
        public int $expiresAt,
    ) {
    }
}

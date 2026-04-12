<?php

declare(strict_types=1);

namespace App\Application\Auth\Port;

/**
 * Port for checking whether a user has TOTP (2FA) enabled.
 *
 * Used by LoginController to decide whether to return a 2FA-required
 * response instead of the JWT tokens.
 */
interface UserTotpCheckerInterface
{
    /**
     * Check if the user identified by email has TOTP enabled.
     *
     * Returns false if the user does not exist or if TOTP is not enabled.
     * Must not throw — graceful degradation on infrastructure errors.
     */
    public function isTotpRequired(string $email): bool;
}

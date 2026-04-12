<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Application\Auth\Port\UserTotpCheckerInterface;
use App\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Doctrine adapter for the UserTotpChecker port.
 *
 * Looks up the user by email and checks the TOTP flag.
 * Catches all exceptions to ensure graceful degradation
 * (e.g., migration not yet run, DB unreachable).
 */
final class DoctrineUserTotpChecker implements UserTotpCheckerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function isTotpRequired(string $email): bool
    {
        try {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        } catch (\Throwable) {
            return false;
        }

        return $user instanceof User && $user->isTotpEnabled();
    }
}

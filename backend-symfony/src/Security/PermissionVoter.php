<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\User\Permission;
use App\Domain\User\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Votes on fine-grained Permission checks.
 *
 * Usage in controllers: #[IsGranted('conversation:read')]
 * Admins (ROLE_ADMIN) are automatically granted all permissions.
 * Regular users need the permission in their permissions JSON array.
 */
/** @extends Voter<string, mixed> */
class PermissionVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return Permission::tryFrom($attribute) instanceof \App\Domain\User\Permission;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof UserInterface) {
            return false;
        }

        // ROLE_ADMIN has all permissions (both User entity and InMemoryUser)
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        // The TAXII feed principal (static API key, see TaxiiApiKeyAuthenticator)
        // reads the feed and nothing else. Checked BEFORE the InMemoryUser
        // fallback below, which would otherwise grant it every permission.
        if (in_array(TaxiiApiKeyAuthenticator::ROLE_TAXII_FEED, $user->getRoles(), true)) {
            return $attribute === Permission::IOC_READ->value;
        }

        // For User entity: check fine-grained permissions
        if ($user instanceof User) {
            $permission = Permission::from($attribute);

            return $user->hasPermission($permission);
        }

        // For InMemoryUser (test environment): ROLE_USER has all permissions
        // This allows test fixtures to work without configuring permissions per user.
        // In production, the real User entity is always used.
        return in_array('ROLE_USER', $user->getRoles(), true);
    }
}

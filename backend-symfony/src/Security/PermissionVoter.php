<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\User\Permission;
use App\Domain\User\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

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
        return Permission::tryFrom($attribute) !== null;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        $permission = Permission::from($attribute);

        return $user->hasPermission($permission);
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Auth\Oidc;

use App\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Maps a verified OIDC identity onto a local {@see User}. Existing users are matched
 * by email; unknown users are created only when auto-provisioning is enabled,
 * otherwise SSO is refused (least privilege — an admin must pre-create the account).
 */
final readonly class OidcUserProvisioner
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private OidcConfig $config,
    ) {
    }

    public function resolve(OidcIdentity $identity): User
    {
        $repo = $this->em->getRepository(User::class);
        $user = $repo->findOneBy(['email' => $identity->email]);

        if ($user instanceof User) {
            return $user;
        }

        if (!$this->config->autoProvision) {
            throw new OidcException('No local account is provisioned for this SSO identity.');
        }

        $user = new User();
        $user->setEmail($identity->email);
        // Password login is unusable for SSO-provisioned accounts: set an
        // unguessable random hash so the password column is never a weak point.
        $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(32))));
        $user->setRoles($this->config->defaultRoles !== [] ? $this->config->defaultRoles : ['ROLE_USER']);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\DataFixtures\User;

use App\Domain\User\Permission;
use App\Domain\User\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    private UserPasswordHasherInterface $passwordHasher;

    public const TEST_USER_EMAIL = 'user@example.com';
    public const TEST_USER_PASSWORD = 'Un1que$trongPassword2024';
    public const TEST_ADMIN_EMAIL = 'admin@example.com';
    public const TEST_ADMIN_PASSWORD = 'Un1que$trongPassword2024';

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    public function load(ObjectManager $manager): void
    {
        // Standard user with all fine-grained permissions
        $user = new User();
        $user->setEmail(self::TEST_USER_EMAIL);
        $user->setRoles(['ROLE_USER']);
        $user->setPermissions(array_map(fn (Permission $p) => $p->value, Permission::cases()));

        $hashedPassword = $this->passwordHasher->hashPassword($user, self::TEST_USER_PASSWORD);
        $user->setPassword($hashedPassword);

        // Admin user (has all permissions implicitly via ROLE_ADMIN)
        $admin = new User();
        $admin->setEmail(self::TEST_ADMIN_EMAIL);
        $admin->setRoles(['ROLE_ADMIN']);
        $hashedAdminPassword = $this->passwordHasher->hashPassword($admin, self::TEST_ADMIN_PASSWORD);
        $admin->setPassword($hashedAdminPassword);

        $manager->persist($user);
        $manager->persist($admin);
        $manager->flush();
    }
}

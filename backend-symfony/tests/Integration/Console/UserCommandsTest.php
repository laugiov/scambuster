<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Domain\User\User;
use App\UI\Console\UserCreateCommand;
use App\UI\Console\UserListCommand;
use App\UI\Console\UserPromoteCommand;
use App\UI\Console\UserSetPasswordCommand;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Integration tests for the user-management CLI:
 * app:user:create / set-password / promote / list.
 *
 * DB writes auto-roll-back via the DAMA DoctrineTestBundle extension.
 */
final class UserCommandsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $hasher;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine')->getManager();
        $this->hasher = $container->get(UserPasswordHasherInterface::class);
    }

    private function tester(string $class): CommandTester
    {
        return new CommandTester(static::getContainer()->get($class));
    }

    private function seedUser(string $email, string $plainPassword = 'SeedPassword123', array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $user->setRoles($roles);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        return $user;
    }

    private function findUser(string $email): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    // ── create ────────────────────────────────────────────────────────────

    public function testCreateSucceedsAndHashesPassword(): void
    {
        $tester = $this->tester(UserCreateCommand::class);
        $exit = $tester->execute([
            '--email' => 'created@example.com',
            '--password' => 'StrongPassword123',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $this->em->clear();
        $user = $this->findUser('created@example.com');
        self::assertNotNull($user);
        self::assertTrue($this->hasher->isPasswordValid($user, 'StrongPassword123'));
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testCreateAdminSetsRoleAdmin(): void
    {
        $tester = $this->tester(UserCreateCommand::class);
        $exit = $tester->execute([
            '--email' => 'admin-created@example.com',
            '--password' => 'StrongPassword123',
            '--admin' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $this->em->clear();
        self::assertContains('ROLE_ADMIN', $this->findUser('admin-created@example.com')->getRoles());
    }

    public function testCreateRejectsDuplicateEmail(): void
    {
        $this->seedUser('dupe@example.com');
        $tester = $this->tester(UserCreateCommand::class);
        $exit = $tester->execute([
            '--email' => 'dupe@example.com',
            '--password' => 'StrongPassword123',
        ]);

        self::assertNotSame(Command::SUCCESS, $exit);
        self::assertStringContainsStringIgnoringCase('exist', $tester->getDisplay());
    }

    public function testCreateRejectsShortPassword(): void
    {
        $tester = $this->tester(UserCreateCommand::class);
        $exit = $tester->execute([
            '--email' => 'short@example.com',
            '--password' => 'short',
        ]);

        self::assertSame(Command::INVALID, $exit);
        self::assertNull($this->findUser('short@example.com'));
    }

    public function testCreateNonInteractiveWithoutPasswordFails(): void
    {
        $tester = $this->tester(UserCreateCommand::class);
        $exit = $tester->execute(
            ['--email' => 'nopass@example.com'],
            ['interactive' => false],
        );

        self::assertSame(Command::INVALID, $exit);
        self::assertNull($this->findUser('nopass@example.com'));
    }

    public function testCreateNormalizesEmailAndIsFoundByAnyCase(): void
    {
        $createExit = $this->tester(UserCreateCommand::class)->execute([
            '--email' => '  Mixed@Example.COM ',
            '--password' => 'StrongPassword123',
        ]);
        self::assertSame(Command::SUCCESS, $createExit);

        $this->em->clear();
        self::assertNotNull($this->findUser('mixed@example.com'));
        self::assertNull($this->findUser('Mixed@Example.COM'));

        // A later command can target the account with any casing (normalized).
        $rotateExit = $this->tester(UserSetPasswordCommand::class)->execute([
            'email' => 'MIXED@EXAMPLE.com',
            '--password' => 'RotatedPassword9',
        ]);
        self::assertSame(Command::SUCCESS, $rotateExit);
        $this->em->clear();
        self::assertTrue($this->hasher->isPasswordValid($this->findUser('mixed@example.com'), 'RotatedPassword9'));
    }

    public function testCreateRejectsInvalidEmailFormat(): void
    {
        $exit = $this->tester(UserCreateCommand::class)->execute([
            '--email' => 'not-an-email',
            '--password' => 'StrongPassword123',
        ]);

        self::assertSame(Command::INVALID, $exit);
        self::assertNull($this->findUser('not-an-email'));
    }

    public function testCreateWithGeneratePrintsUsablePassword(): void
    {
        $tester = $this->tester(UserCreateCommand::class);
        $exit = $tester->execute([
            '--email' => 'generated@example.com',
            '--generate' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(1, preg_match('/Generated password: (\S+)/', $tester->getDisplay(), $m));
        $this->em->clear();
        $user = $this->findUser('generated@example.com');
        self::assertNotNull($user);
        self::assertTrue($this->hasher->isPasswordValid($user, $m[1]));
    }

    public function testCreateWritesAuditLogEntry(): void
    {
        $this->tester(UserCreateCommand::class)->execute([
            '--email' => 'audited@example.com',
            '--password' => 'StrongPassword123',
            '--admin' => true,
        ]);

        $count = $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE event_type = 'USER_CREATED' AND resource_id = ?",
            ['audited@example.com'],
        );
        self::assertGreaterThanOrEqual(1, (int) $count);
    }

    // ── set-password ──────────────────────────────────────────────────────

    public function testSetPasswordRotatesCredential(): void
    {
        $this->seedUser('rotate@example.com', 'OldPassword123');
        $tester = $this->tester(UserSetPasswordCommand::class);
        $exit = $tester->execute([
            'email' => 'rotate@example.com',
            '--password' => 'NewPassword456',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $this->em->clear();
        $user = $this->findUser('rotate@example.com');
        self::assertTrue($this->hasher->isPasswordValid($user, 'NewPassword456'));
        self::assertFalse($this->hasher->isPasswordValid($user, 'OldPassword123'));
    }

    public function testSetPasswordFailsForUnknownUser(): void
    {
        $tester = $this->tester(UserSetPasswordCommand::class);
        $exit = $tester->execute([
            'email' => 'ghost@example.com',
            '--password' => 'NewPassword456',
        ]);

        self::assertSame(Command::FAILURE, $exit);
    }

    // ── promote / demote ──────────────────────────────────────────────────

    public function testPromoteGrantsAdmin(): void
    {
        $this->seedUser('promote@example.com', 'SeedPassword123', ['ROLE_USER']);
        $tester = $this->tester(UserPromoteCommand::class);
        $exit = $tester->execute(['email' => 'promote@example.com']);

        self::assertSame(Command::SUCCESS, $exit);
        $this->em->clear();
        self::assertContains('ROLE_ADMIN', $this->findUser('promote@example.com')->getRoles());
    }

    public function testDemoteRemovesAdmin(): void
    {
        $this->seedUser('demote@example.com', 'SeedPassword123', ['ROLE_ADMIN']);
        $tester = $this->tester(UserPromoteCommand::class);
        $exit = $tester->execute(['email' => 'demote@example.com', '--demote' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $this->em->clear();
        self::assertNotContains('ROLE_ADMIN', $this->findUser('demote@example.com')->getRoles());
    }

    public function testPromoteFailsForUnknownUser(): void
    {
        $tester = $this->tester(UserPromoteCommand::class);
        $exit = $tester->execute(['email' => 'ghost@example.com']);

        self::assertSame(Command::FAILURE, $exit);
    }

    // ── list ──────────────────────────────────────────────────────────────

    public function testListShowsSeededUser(): void
    {
        $this->seedUser('listed@example.com');
        $tester = $this->tester(UserListCommand::class);
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('listed@example.com', $tester->getDisplay());
    }
}

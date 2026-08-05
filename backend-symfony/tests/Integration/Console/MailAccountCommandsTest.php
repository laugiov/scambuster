<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Application\Communication\Smtp\SmtpDsnEncryptor;
use App\Domain\Communication\MailAccount;
use App\UI\Console\MailAccountAddCommand;
use App\UI\Console\MailAccountDisableCommand;
use App\UI\Console\MailAccountListCommand;
use App\UI\Console\MailAccountRotateSmtpCommand;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * E2E lifecycle tests for the 4 mail-account CLI commands.
 *
 * Add → List → Rotate → Disable, with assertions at every step.
 */
final class MailAccountCommandsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SmtpDsnEncryptor $encryptor;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine')->getManager();
        $this->encryptor = $container->get(SmtpDsnEncryptor::class);
    }

    private function addCommand(): CommandTester
    {
        return new CommandTester(static::getContainer()->get(MailAccountAddCommand::class));
    }

    private function listCommand(): CommandTester
    {
        return new CommandTester(static::getContainer()->get(MailAccountListCommand::class));
    }

    private function disableCommand(): CommandTester
    {
        return new CommandTester(static::getContainer()->get(MailAccountDisableCommand::class));
    }

    private function rotateCommand(): CommandTester
    {
        return new CommandTester(static::getContainer()->get(MailAccountRotateSmtpCommand::class));
    }

    public function testAddCommandCreatesAccountAndReturnsUuid(): void
    {
        $tester = $this->addCommand();

        $exitCode = $tester->execute([
            '--owner-id' => '22222222-2222-2222-2222-222222222222',
            '--email' => 'cli-test@example.com',
            '--smtp-dsn' => 'smtps://cli:pass@smtp.example.com:465',
            '--label' => 'CLI Test',
        ]);

        self::assertSame(0, $exitCode);

        $output = $tester->getDisplay();
        // Extract the UUID printed on the last line of stdout
        $lines = array_filter(explode("\n", trim($output)));
        $lastLine = trim((string) end($lines));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $lastLine);

        // Verify in DB
        $stored = $this->em->getRepository(MailAccount::class)->find($lastLine);
        self::assertInstanceOf(MailAccount::class, $stored);
        self::assertSame('cli-test@example.com', $stored->getEmailAddress());
        self::assertTrue($stored->hasCustomSmtp());
    }

    public function testAddCommandRejectsMissingOwnerId(): void
    {
        $tester = $this->addCommand();
        $exitCode = $tester->execute([
            '--email' => 'test@example.com',
            '--smtp-dsn' => 'smtps://u:p@smtp.example.com:465',
        ]);
        self::assertSame(2, $exitCode); // Command::INVALID
    }

    public function testAddCommandRejectsMissingEmail(): void
    {
        $tester = $this->addCommand();
        $exitCode = $tester->execute([
            '--owner-id' => '22222222-2222-2222-2222-222222222222',
            '--smtp-dsn' => 'smtps://u:p@smtp.example.com:465',
        ]);
        self::assertSame(2, $exitCode);
    }

    public function testAddCommandRejectsInvalidEmail(): void
    {
        $tester = $this->addCommand();
        $exitCode = $tester->execute([
            '--owner-id' => '22222222-2222-2222-2222-222222222222',
            '--email' => 'not-an-email',
            '--smtp-dsn' => 'smtps://u:p@smtp.example.com:465',
        ]);
        self::assertSame(1, $exitCode);
    }

    public function testAddCommandRejectsInvalidSmtpDsn(): void
    {
        $tester = $this->addCommand();
        $exitCode = $tester->execute([
            '--owner-id' => '22222222-2222-2222-2222-222222222222',
            '--email' => 'test@example.com',
            '--smtp-dsn' => 'http://not-a-mailer-dsn',
        ]);
        self::assertSame(1, $exitCode);
    }

    public function testAddCommandWorksWithoutSmtpDsn(): void
    {
        $tester = $this->addCommand();
        $exitCode = $tester->execute([
            '--owner-id' => '22222222-2222-2222-2222-222222222222',
            '--email' => 'no-smtp@example.com',
        ]);
        self::assertSame(0, $exitCode);
    }

    public function testListCommandShowsAccountsWithoutDsn(): void
    {
        // Create one account first
        $tester = $this->addCommand();
        $tester->execute([
            '--owner-id' => '22222222-2222-2222-2222-222222222222',
            '--email' => 'list-test@example.com',
            '--smtp-dsn' => 'smtps://supersecret:pass@smtp.example.com:465',
            '--label' => 'List Test',
        ]);

        $listTester = $this->listCommand();
        $exitCode = $listTester->execute([]);

        self::assertSame(0, $exitCode);
        $output = $listTester->getDisplay();
        self::assertStringContainsString('list-test@example.com', $output);
        self::assertStringContainsString('List Test', $output);
        self::assertStringContainsString('yes', $output); // has_custom_smtp column
        self::assertStringNotContainsString('supersecret', $output, 'List output must NEVER reveal SMTP credentials');
        self::assertStringNotContainsString('smtps://', $output);
    }

    public function testDisableCommandSetsAccountInactive(): void
    {
        // Create and capture UUID
        $tester = $this->addCommand();
        $tester->execute([
            '--owner-id' => '22222222-2222-2222-2222-222222222222',
            '--email' => 'disable-test@example.com',
        ]);
        $output = $tester->getDisplay();
        $lines = array_filter(explode("\n", trim($output)));
        $accountId = trim((string) end($lines));

        $disableTester = $this->disableCommand();
        $exitCode = $disableTester->execute(['account-id' => $accountId]);

        self::assertSame(0, $exitCode);

        // Verify in DB
        $this->em->clear();
        $account = $this->em->getRepository(MailAccount::class)->find($accountId);
        self::assertInstanceOf(MailAccount::class, $account);
        self::assertFalse($account->isActive());
    }

    public function testDisableCommandFailsForUnknownAccount(): void
    {
        $tester = $this->disableCommand();
        $exitCode = $tester->execute(['account-id' => '99999999-9999-9999-9999-999999999999']);
        self::assertSame(1, $exitCode);
    }

    public function testRotateCommandUpdatesEncryptedDsn(): void
    {
        $addTester = $this->addCommand();
        $addTester->execute([
            '--owner-id' => '22222222-2222-2222-2222-222222222222',
            '--email' => 'rotate-test@example.com',
            '--smtp-dsn' => 'smtps://old:oldpass@smtp.example.com:465',
        ]);
        $output = $addTester->getDisplay();
        $lines = array_filter(explode("\n", trim($output)));
        $accountId = trim((string) end($lines));

        $this->em->clear();
        $oldEncrypted = $this->em->getRepository(MailAccount::class)->find($accountId)?->getSmtpDsnEncrypted();

        $rotateTester = $this->rotateCommand();
        $exitCode = $rotateTester->execute([
            'account-id' => $accountId,
            '--smtp-dsn' => 'smtps://new:newpass@smtp.example.com:465',
        ]);

        self::assertSame(0, $exitCode);

        $this->em->clear();
        $newEncrypted = $this->em->getRepository(MailAccount::class)->find($accountId)?->getSmtpDsnEncrypted();

        self::assertNotNull($newEncrypted);
        self::assertNotSame($oldEncrypted, $newEncrypted);
        self::assertSame('smtps://new:newpass@smtp.example.com:465', $this->encryptor->decrypt($newEncrypted));
    }

    public function testRotateCommandFailsForUnknownAccount(): void
    {
        $tester = $this->rotateCommand();
        $exitCode = $tester->execute([
            'account-id' => '99999999-9999-9999-9999-999999999999',
            '--smtp-dsn' => 'smtps://u:p@smtp.example.com:465',
        ]);
        self::assertSame(1, $exitCode);
    }

    public function testRotateCommandRejectsInvalidDsn(): void
    {
        $addTester = $this->addCommand();
        $addTester->execute([
            '--owner-id' => '22222222-2222-2222-2222-222222222222',
            '--email' => 'rotate-bad@example.com',
        ]);
        $lines = array_filter(explode("\n", trim($addTester->getDisplay())));
        $accountId = trim((string) end($lines));

        $tester = $this->rotateCommand();
        $exitCode = $tester->execute([
            'account-id' => $accountId,
            '--smtp-dsn' => 'http://not-valid',
        ]);
        self::assertSame(1, $exitCode);
    }

    public function testFullLifecycleAddListRotateDisable(): void
    {
        // ADD
        $addTester = $this->addCommand();
        $addTester->execute([
            '--owner-id' => '22222222-2222-2222-2222-222222222222',
            '--email' => 'lifecycle@example.com',
            '--smtp-dsn' => 'smtps://lifecycle:pass1@smtp.example.com:465',
            '--label' => 'Lifecycle',
        ]);
        $linesLifecycle = array_filter(explode("\n", trim($addTester->getDisplay())));
        $accountId = trim((string) end($linesLifecycle));

        // LIST
        $listTester = $this->listCommand();
        $listTester->execute([]);
        self::assertStringContainsString('lifecycle@example.com', $listTester->getDisplay());

        // ROTATE
        $rotateTester = $this->rotateCommand();
        $rotateTester->execute([
            'account-id' => $accountId,
            '--smtp-dsn' => 'smtps://lifecycle:pass2@smtp.example.com:465',
        ]);

        // DISABLE
        $disableTester = $this->disableCommand();
        $disableTester->execute(['account-id' => $accountId]);

        // Verify final state
        $this->em->clear();
        $account = $this->em->getRepository(MailAccount::class)->find($accountId);
        self::assertInstanceOf(MailAccount::class, $account);
        self::assertFalse($account->isActive());
        self::assertTrue($account->hasCustomSmtp());
        $encrypted = $account->getSmtpDsnEncrypted();
        self::assertNotNull($encrypted);
        self::assertSame('smtps://lifecycle:pass2@smtp.example.com:465', $this->encryptor->decrypt($encrypted));
    }
}

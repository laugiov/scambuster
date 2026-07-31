<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\MailAccountManager;
use App\Application\Communication\Smtp\SmtpDsnEncryptor;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Repository\MailAccountRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class MailAccountManagerTest extends TestCase
{
    private const APP_SECRET = 'unit-test-secret-32-chars-min!!!!!!!';

    private function makeRepoStub(): MailAccountRepositoryInterface
    {
        return new class implements MailAccountRepositoryInterface {
            /** @var array<string, MailAccount> */
            public array $store = [];

            public function findById(string $id): ?MailAccount
            {
                return $this->store[$id] ?? null;
            }

            public function findByEmail(string $email): ?MailAccount
            {
                foreach ($this->store as $account) {
                    if ($account->isActive()
                        && strcasecmp((string) $account->getEmailAddress(), $email) === 0) {
                        return $account;
                    }
                }

                return null;
            }

            /** @return list<MailAccount> */
            public function findAll(): array
            {
                return array_values($this->store);
            }

            public function save(MailAccount $account): void
            {
                $this->store[$account->getAccountId()] = $account;
            }
        };
    }

    public function testAddAccountReturnsAccountIdAndPersistsRow(): void
    {
        $repo = $this->makeRepoStub();
        $manager = new MailAccountManager($repo, new SmtpDsnEncryptor(self::APP_SECRET));

        $accountId = $manager->addAccount(
            ownerId: '22222222-2222-2222-2222-222222222222',
            email: 'user@example.com',
            smtpDsn: 'smtps://user:pass@smtp.example.com:465',
            label: 'Test mailbox',
            endpoint: 'imap.example.com',
        );

        self::assertNotEmpty($accountId);

        $stored = $repo->findById($accountId);
        self::assertInstanceOf(MailAccount::class, $stored);
        self::assertSame('user@example.com', $stored->getEmailAddress());
        self::assertSame('Test mailbox', $stored->getLabel());
        self::assertTrue($stored->hasCustomSmtp());
        self::assertNull($stored->getSmtpDsnEncrypted() === null ? null : null); // ensure not null
        self::assertNotNull($stored->getSmtpDsnEncrypted());
    }

    public function testAddAccountEncryptsSmtpDsn(): void
    {
        $repo = $this->makeRepoStub();
        $encryptor = new SmtpDsnEncryptor(self::APP_SECRET);
        $manager = new MailAccountManager($repo, $encryptor);

        $plainDsn = 'smtps://user:secretpass@smtp.example.com:465';
        $accountId = $manager->addAccount(
            ownerId: '22222222-2222-2222-2222-222222222222',
            email: 'user@example.com',
            smtpDsn: $plainDsn,
            label: 'Test',
            endpoint: 'imap.example.com',
        );

        $stored = $repo->findById($accountId);
        self::assertInstanceOf(MailAccount::class, $stored);
        $encrypted = $stored->getSmtpDsnEncrypted();
        self::assertNotNull($encrypted);
        self::assertNotSame($plainDsn, $encrypted, 'DSN must be encrypted');
        self::assertStringNotContainsString('secretpass', $encrypted);

        $decrypted = $encryptor->decrypt($encrypted);
        self::assertSame($plainDsn, $decrypted);
    }

    public function testAddAccountRejectsInvalidEmail(): void
    {
        $manager = new MailAccountManager($this->makeRepoStub(), new SmtpDsnEncryptor(self::APP_SECRET));

        $this->expectException(\InvalidArgumentException::class);
        $manager->addAccount(
            ownerId: '22222222-2222-2222-2222-222222222222',
            email: 'not-an-email',
            smtpDsn: 'smtps://u:p@smtp.example.com:465',
            label: 'Test',
            endpoint: 'imap.example.com',
        );
    }

    public function testAddAccountRejectsInvalidDsn(): void
    {
        $manager = new MailAccountManager($this->makeRepoStub(), new SmtpDsnEncryptor(self::APP_SECRET));

        $this->expectException(\InvalidArgumentException::class);
        $manager->addAccount(
            ownerId: '22222222-2222-2222-2222-222222222222',
            email: 'user@example.com',
            smtpDsn: 'http://not-a-mailer-dsn',
            label: 'Test',
            endpoint: 'imap.example.com',
        );
    }

    public function testListAccountsReturnsDtoWithoutDsn(): void
    {
        $repo = $this->makeRepoStub();
        $manager = new MailAccountManager($repo, new SmtpDsnEncryptor(self::APP_SECRET));

        $manager->addAccount(
            ownerId: '22222222-2222-2222-2222-222222222222',
            email: 'a@example.com',
            smtpDsn: 'smtps://a:p@smtp.example.com:465',
            label: 'A',
            endpoint: 'imap.example.com',
        );
        $manager->addAccount(
            ownerId: '22222222-2222-2222-2222-222222222222',
            email: 'b@example.com',
            smtpDsn: null,
            label: 'B',
            endpoint: 'imap.example.com',
        );

        $list = $manager->listAccounts();

        self::assertCount(2, $list);
        foreach ($list as $row) {
            self::assertArrayHasKey('account_id', $row);
            self::assertArrayHasKey('email', $row);
            self::assertArrayHasKey('label', $row);
            self::assertArrayHasKey('has_custom_smtp', $row);
            self::assertArrayHasKey('is_active', $row);
            self::assertArrayNotHasKey('smtp_dsn', $row);
            self::assertArrayNotHasKey('smtp_dsn_encrypted', $row);
        }
    }

    public function testListAccountsReportsHasCustomSmtpCorrectly(): void
    {
        $repo = $this->makeRepoStub();
        $manager = new MailAccountManager($repo, new SmtpDsnEncryptor(self::APP_SECRET));

        $manager->addAccount(
            ownerId: '22222222-2222-2222-2222-222222222222',
            email: 'with@example.com',
            smtpDsn: 'smtps://u:p@smtp.example.com:465',
            label: 'With',
            endpoint: 'imap.example.com',
        );
        $manager->addAccount(
            ownerId: '22222222-2222-2222-2222-222222222222',
            email: 'without@example.com',
            smtpDsn: null,
            label: 'Without',
            endpoint: 'imap.example.com',
        );

        $list = $manager->listAccounts();
        $byEmail = [];
        foreach ($list as $row) {
            $byEmail[$row['email']] = $row;
        }

        self::assertTrue($byEmail['with@example.com']['has_custom_smtp']);
        self::assertFalse($byEmail['without@example.com']['has_custom_smtp']);
    }

    public function testDisableAccountSetsIsActiveFalse(): void
    {
        $repo = $this->makeRepoStub();
        $manager = new MailAccountManager($repo, new SmtpDsnEncryptor(self::APP_SECRET));

        $accountId = $manager->addAccount(
            ownerId: '22222222-2222-2222-2222-222222222222',
            email: 'user@example.com',
            smtpDsn: null,
            label: 'Test',
            endpoint: 'imap.example.com',
        );

        $manager->disableAccount($accountId);

        $stored = $repo->findById($accountId);
        self::assertInstanceOf(MailAccount::class, $stored);
        self::assertFalse($stored->isActive());
    }

    public function testDisableAccountThrowsForUnknownAccount(): void
    {
        $manager = new MailAccountManager($this->makeRepoStub(), new SmtpDsnEncryptor(self::APP_SECRET));

        $this->expectException(\RuntimeException::class);
        $manager->disableAccount('99999999-9999-9999-9999-999999999999');
    }

    public function testRotateSmtpUpdatesEncryptedDsn(): void
    {
        $repo = $this->makeRepoStub();
        $encryptor = new SmtpDsnEncryptor(self::APP_SECRET);
        $manager = new MailAccountManager($repo, $encryptor);

        $accountId = $manager->addAccount(
            ownerId: '22222222-2222-2222-2222-222222222222',
            email: 'user@example.com',
            smtpDsn: 'smtps://old:oldpass@smtp.example.com:465',
            label: 'Test',
            endpoint: 'imap.example.com',
        );

        $oldEncrypted = $repo->findById($accountId)?->getSmtpDsnEncrypted();
        self::assertNotNull($oldEncrypted);

        $manager->rotateSmtp($accountId, 'smtps://new:newpass@smtp.example.com:465');

        $newEncrypted = $repo->findById($accountId)?->getSmtpDsnEncrypted();
        self::assertNotNull($newEncrypted);
        self::assertNotSame($oldEncrypted, $newEncrypted);

        $decrypted = $encryptor->decrypt($newEncrypted);
        self::assertSame('smtps://new:newpass@smtp.example.com:465', $decrypted);
    }

    public function testRotateSmtpThrowsForUnknownAccount(): void
    {
        $manager = new MailAccountManager($this->makeRepoStub(), new SmtpDsnEncryptor(self::APP_SECRET));

        $this->expectException(\RuntimeException::class);
        $manager->rotateSmtp('99999999-9999-9999-9999-999999999999', 'smtps://u:p@smtp.example.com:465');
    }

    public function testRotateSmtpRejectsInvalidDsn(): void
    {
        $repo = $this->makeRepoStub();
        $manager = new MailAccountManager($repo, new SmtpDsnEncryptor(self::APP_SECRET));

        $accountId = $manager->addAccount(
            ownerId: '22222222-2222-2222-2222-222222222222',
            email: 'user@example.com',
            smtpDsn: null,
            label: 'Test',
            endpoint: 'imap.example.com',
        );

        $this->expectException(\InvalidArgumentException::class);
        $manager->rotateSmtp($accountId, 'http://not-a-dsn');
    }
}

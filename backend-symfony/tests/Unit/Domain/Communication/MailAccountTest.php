<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication;

use App\Domain\Communication\MailAccount;
use PHPUnit\Framework\TestCase;

class MailAccountTest extends TestCase
{
    public function test_it_creates_mail_account_with_all_fields(): void
    {
        $accountId = 'b3b6c1e2-8e2a-4e2a-9e2a-8e2a4e2a9e2a';
        $ownerId = 'a1a2a3a4-a5a6-a7a8-a9a0-a1a2a3a4a5a6';
        $protocol = 'imap';
        $endpoint = 'mail.example.com';
        $loginHash = 'hashedpassword';
        $oauthScopes = ['mail.read', 'mail.send'];
        $isActive = false;
        $createdAt = new \DateTimeImmutable('-1 day');
        $updatedAt = new \DateTimeImmutable('now');

        $mailAccount = new MailAccount(
            $accountId,
            $ownerId,
            $protocol,
            $endpoint,
            $loginHash,
            $oauthScopes,
            $isActive,
            $createdAt,
            $updatedAt
        );

        $this->assertSame($accountId, $mailAccount->getAccountId());
        $this->assertSame($ownerId, $mailAccount->getOwnerId());
        $this->assertSame($protocol, $mailAccount->getProtocol());
        $this->assertSame($endpoint, $mailAccount->getEndpoint());
        $this->assertSame($loginHash, $mailAccount->getLoginHash());
        $this->assertSame($oauthScopes, $mailAccount->getOauthScopes());
        $this->assertFalse($mailAccount->isActive());
        $this->assertEquals($createdAt, $mailAccount->getCreatedAt());
        $this->assertEquals($updatedAt, $mailAccount->getUpdatedAt());
    }

    public function test_it_defaults_is_active_and_dates(): void
    {
        $mailAccount = new MailAccount(
            'b3b6c1e2-8e2a-4e2a-9e2a-8e2a4e2a9e2a',
            'a1a2a3a4-a5a6-a7a8-a9a0-a1a2a3a4a5a6',
            'imap',
            'mail.example.com',
            'hashedpassword',
            ['mail.read']
        );

        $this->assertTrue($mailAccount->isActive());
        $this->assertInstanceOf(\DateTimeImmutable::class, $mailAccount->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $mailAccount->getUpdatedAt());
    }

    private function makeAccount(): MailAccount
    {
        return new MailAccount(
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
            'IMAP',
            'imap.example.com',
            'login-hash',
            [],
        );
    }

    public function test_new_account_has_no_custom_smtp(): void
    {
        $account = $this->makeAccount();
        $this->assertFalse($account->hasCustomSmtp());
        $this->assertNull($account->getSmtpDsnEncrypted());
    }

    public function test_new_account_has_no_email_address(): void
    {
        $this->assertNull($this->makeAccount()->getEmailAddress());
    }

    public function test_new_account_has_no_label(): void
    {
        $this->assertNull($this->makeAccount()->getLabel());
    }

    public function test_set_email_address(): void
    {
        $account = $this->makeAccount();
        $account->setEmailAddress('user@example.com');
        $this->assertSame('user@example.com', $account->getEmailAddress());
    }

    public function test_clear_email_address(): void
    {
        $account = $this->makeAccount();
        $account->setEmailAddress('user@example.com');
        $account->setEmailAddress(null);
        $this->assertNull($account->getEmailAddress());
    }

    public function test_set_label(): void
    {
        $account = $this->makeAccount();
        $account->setLabel('Production mailbox');
        $this->assertSame('Production mailbox', $account->getLabel());
    }

    public function test_has_custom_smtp_true_when_dsn_set(): void
    {
        $account = $this->makeAccount();
        $account->setSmtpDsnEncrypted('base64ciphertext');
        $this->assertTrue($account->hasCustomSmtp());
    }

    public function test_has_custom_smtp_false_when_dsn_null(): void
    {
        $account = $this->makeAccount();
        $account->setSmtpDsnEncrypted('something');
        $account->setSmtpDsnEncrypted(null);
        $this->assertFalse($account->hasCustomSmtp());
    }

    public function test_has_custom_smtp_false_when_empty_string(): void
    {
        $account = $this->makeAccount();
        $account->setSmtpDsnEncrypted('');
        $this->assertFalse($account->hasCustomSmtp());
    }

    public function test_disable_sets_the_account_inactive(): void
    {
        $account = new MailAccount('id', 'owner', 'imap', 'mail.example.com', 'hash', [], true);
        self::assertTrue($account->isActive());

        $account->disable();

        self::assertFalse($account->isActive());
    }

    public function test_enable_sets_the_account_active(): void
    {
        $account = new MailAccount('id', 'owner', 'imap', 'mail.example.com', 'hash', [], false);
        self::assertFalse($account->isActive());

        $account->enable();

        self::assertTrue($account->isActive());
    }
}

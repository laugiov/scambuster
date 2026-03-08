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
} 
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication\Smtp;

use App\Application\Communication\Smtp\SmtpDsnEncryptor;
use App\Application\Communication\Smtp\SmtpTransportResolver;
use App\Domain\Communication\MailAccount;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test: SmtpTransportResolver wiring + per-account routing.
 *
 * Verifies:
 * - DI container resolves SmtpTransportResolver with all dependencies
 * - Account without custom SMTP returns the default mailer
 * - Account with custom SMTP returns a different mailer instance
 * - Two different accounts get two different mailer instances
 * - Round-trip encryption works through the real container
 */
final class SmtpRoutingIntegrationTest extends KernelTestCase
{
    private SmtpTransportResolver $resolver;
    private SmtpDsnEncryptor $encryptor;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->resolver = $container->get(SmtpTransportResolver::class);
        $this->encryptor = $container->get(SmtpDsnEncryptor::class);
    }

    private function makeAccount(string $accountId, ?string $smtpDsnEncrypted = null): MailAccount
    {
        $account = new MailAccount(
            $accountId,
            '22222222-2222-2222-2222-222222222222',
            'IMAP',
            'imap.example.com',
            'login-hash-' . $accountId,
            [],
        );

        if ($smtpDsnEncrypted !== null) {
            $account->setSmtpDsnEncrypted($smtpDsnEncrypted);
        }

        return $account;
    }

    public function testContainerResolvesResolverWithDependencies(): void
    {
        self::assertInstanceOf(SmtpTransportResolver::class, $this->resolver);
        self::assertInstanceOf(SmtpDsnEncryptor::class, $this->encryptor);
    }

    public function testAccountWithoutCustomSmtpUsesDefaultMailer(): void
    {
        $account = $this->makeAccount('11111111-1111-1111-1111-111111111111');
        $resolved = $this->resolver->resolveForAccount($account);

        $container = static::getContainer();
        $defaultMailer = $container->get('mailer.mailer');

        self::assertSame($defaultMailer, $resolved, 'Should fall back to default Symfony Mailer');
    }

    public function testAccountWithCustomSmtpUsesCustomMailer(): void
    {
        $encryptedDsn = $this->encryptor->encrypt('null://custom');
        $account = $this->makeAccount('33333333-3333-3333-3333-333333333333', $encryptedDsn);

        $resolved = $this->resolver->resolveForAccount($account);

        $container = static::getContainer();
        $defaultMailer = $container->get('mailer.mailer');

        self::assertNotSame($defaultMailer, $resolved, 'Should NOT fall back to default mailer');
        self::assertInstanceOf(\Symfony\Component\Mailer\MailerInterface::class, $resolved);
    }

    public function testTwoAccountsGetTwoDifferentMailers(): void
    {
        $dsn1 = $this->encryptor->encrypt('null://account-one');
        $dsn2 = $this->encryptor->encrypt('null://account-two');

        $account1 = $this->makeAccount('44444444-4444-4444-4444-444444444444', $dsn1);
        $account2 = $this->makeAccount('55555555-5555-5555-5555-555555555555', $dsn2);

        $mailer1 = $this->resolver->resolveForAccount($account1);
        $mailer2 = $this->resolver->resolveForAccount($account2);

        self::assertNotSame($mailer1, $mailer2);
    }

    public function testSameAccountReturnsCachedMailer(): void
    {
        $dsn = $this->encryptor->encrypt('null://cached');
        $account = $this->makeAccount('66666666-6666-6666-6666-666666666666', $dsn);

        $mailer1 = $this->resolver->resolveForAccount($account);
        $mailer2 = $this->resolver->resolveForAccount($account);

        self::assertSame($mailer1, $mailer2);
    }

    public function testCorruptedDsnThrowsAndDoesNotFallBack(): void
    {
        $account = $this->makeAccount('77777777-7777-7777-7777-777777777777', 'corrupted-base64!!!');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to decrypt SMTP DSN');
        $this->resolver->resolveForAccount($account);
    }
}

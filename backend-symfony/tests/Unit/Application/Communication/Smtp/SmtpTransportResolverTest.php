<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication\Smtp;

use App\Application\Communication\Smtp\SmtpDsnEncryptor;
use App\Application\Communication\Smtp\SmtpTransportResolver;
use App\Domain\Communication\MailAccount;
use App\Infrastructure\Mailer\TransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class SmtpTransportResolverTest extends TestCase
{
    private const APP_SECRET = 'test-secret-32-chars-long-okay-yes!';

    private function makeAccount(?string $smtpDsnEncrypted = null): MailAccount
    {
        $account = new MailAccount(
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
            'IMAP',
            'imap.example.com',
            'login-hash',
            [],
        );

        if ($smtpDsnEncrypted !== null) {
            $account->setSmtpDsnEncrypted($smtpDsnEncrypted);
        }

        return $account;
    }

    public function testResolveReturnsDefaultMailerForAccountWithoutCustomSmtp(): void
    {
        $defaultTransport = Transport::fromDsn('null://null');
        $defaultMailer = new Mailer($defaultTransport);

        $resolver = new SmtpTransportResolver(
            new SmtpDsnEncryptor(self::APP_SECRET),
            new TransportFactory(),
            $defaultMailer,
        );

        $account = $this->makeAccount();
        $resolved = $resolver->resolveForAccount($account);

        self::assertSame($defaultMailer, $resolved);
    }

    public function testResolveReturnsCustomTransportForAccountWithDsn(): void
    {
        $encryptor = new SmtpDsnEncryptor(self::APP_SECRET);
        $defaultMailer = new Mailer(Transport::fromDsn('null://null'));

        $resolver = new SmtpTransportResolver(
            $encryptor,
            new TransportFactory(),
            $defaultMailer,
        );

        $encryptedDsn = $encryptor->encrypt('null://custom');
        $account = $this->makeAccount($encryptedDsn);

        $resolved = $resolver->resolveForAccount($account);

        self::assertNotSame($defaultMailer, $resolved);
        self::assertInstanceOf(\Symfony\Component\Mailer\MailerInterface::class, $resolved);
    }

    public function testResolveCachesPerAccount(): void
    {
        $encryptor = new SmtpDsnEncryptor(self::APP_SECRET);
        $defaultMailer = new Mailer(Transport::fromDsn('null://null'));

        $resolver = new SmtpTransportResolver(
            $encryptor,
            new TransportFactory(),
            $defaultMailer,
        );

        $encryptedDsn = $encryptor->encrypt('null://custom');
        $account = $this->makeAccount($encryptedDsn);

        $resolved1 = $resolver->resolveForAccount($account);
        $resolved2 = $resolver->resolveForAccount($account);

        self::assertSame($resolved1, $resolved2, 'Resolver must cache per account_id');
    }

    public function testResolveDifferentAccountsReturnsDifferentTransports(): void
    {
        $encryptor = new SmtpDsnEncryptor(self::APP_SECRET);
        $defaultMailer = new Mailer(Transport::fromDsn('null://null'));

        $resolver = new SmtpTransportResolver(
            $encryptor,
            new TransportFactory(),
            $defaultMailer,
        );

        $account1 = new MailAccount(
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
            'IMAP',
            'imap.example.com',
            'h1',
            [],
        );
        $account1->setSmtpDsnEncrypted($encryptor->encrypt('null://account1'));

        $account2 = new MailAccount(
            '33333333-3333-3333-3333-333333333333',
            '22222222-2222-2222-2222-222222222222',
            'IMAP',
            'imap.example.com',
            'h2',
            [],
        );
        $account2->setSmtpDsnEncrypted($encryptor->encrypt('null://account2'));

        $resolved1 = $resolver->resolveForAccount($account1);
        $resolved2 = $resolver->resolveForAccount($account2);

        self::assertNotSame($resolved1, $resolved2, 'Different accounts must get different transports');
    }

    public function testResolveThrowsOnDecryptionFailure(): void
    {
        $encryptor = new SmtpDsnEncryptor(self::APP_SECRET);
        $defaultMailer = new Mailer(Transport::fromDsn('null://null'));

        $resolver = new SmtpTransportResolver(
            $encryptor,
            new TransportFactory(),
            $defaultMailer,
        );

        $account = $this->makeAccount('not-valid-base64!!!');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to decrypt SMTP DSN');
        $resolver->resolveForAccount($account);
    }

    public function testResolveThrowsOnInvalidDecryptedDsn(): void
    {
        $encryptor = new SmtpDsnEncryptor(self::APP_SECRET);
        $defaultMailer = new Mailer(Transport::fromDsn('null://null'));

        $resolver = new SmtpTransportResolver(
            $encryptor,
            new TransportFactory(),
            $defaultMailer,
        );

        // Encrypt a syntactically correct but invalid mailer DSN
        $encryptedBadDsn = $encryptor->encrypt('http://not-a-mailer-dsn');
        $account = $this->makeAccount($encryptedBadDsn);

        $this->expectException(\RuntimeException::class);
        $resolver->resolveForAccount($account);
    }
}

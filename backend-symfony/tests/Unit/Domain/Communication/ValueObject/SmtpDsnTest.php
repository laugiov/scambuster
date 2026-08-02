<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication\ValueObject;

use App\Domain\Communication\ValueObject\SmtpDsn;
use PHPUnit\Framework\TestCase;

final class SmtpDsnTest extends TestCase
{
    public function testAcceptsValidSmtpDsn(): void
    {
        $dsn = new SmtpDsn('smtp://user:pass@smtp.example.com:25');
        self::assertSame('smtp://user:pass@smtp.example.com:25', (string) $dsn);
    }

    public function testAcceptsValidSmtpsDsn(): void
    {
        $dsn = new SmtpDsn('smtps://user:pass@smtp.example.com:465');
        self::assertSame('smtps://user:pass@smtp.example.com:465', (string) $dsn);
    }

    public function testAcceptsNullDsnForTesting(): void
    {
        $dsn = new SmtpDsn('null://null');
        self::assertSame('null://null', (string) $dsn);
    }

    public function testAcceptsSendmailDsn(): void
    {
        $dsn = new SmtpDsn('sendmail://default');
        self::assertSame('sendmail://default', (string) $dsn);
    }

    public function testRejectsEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SMTP DSN cannot be empty');
        new SmtpDsn('');
    }

    public function testRejectsHttpScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SMTP DSN must use a valid mailer scheme');
        new SmtpDsn('http://user:pass@smtp.example.com');
    }

    public function testRejectsMalformedDsn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SmtpDsn('not-a-dsn');
    }

    public function testRejectsSchemeWithoutHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SmtpDsn('smtp://');
    }

    public function testIsImmutable(): void
    {
        $reflection = new \ReflectionClass(SmtpDsn::class);
        self::assertTrue($reflection->isFinal(), 'SmtpDsn must be final');

        foreach ($reflection->getProperties() as $property) {
            self::assertTrue($property->isReadOnly(), sprintf('Property %s must be readonly', $property->getName()));
        }
    }

    public function testEqualityByValue(): void
    {
        $dsn1 = new SmtpDsn('smtps://user:pass@smtp.example.com:465');
        $dsn2 = new SmtpDsn('smtps://user:pass@smtp.example.com:465');
        $dsn3 = new SmtpDsn('smtp://user:pass@smtp.example.com:25');

        self::assertTrue($dsn1->equals($dsn2));
        self::assertFalse($dsn1->equals($dsn3));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication\ValueObject;

use App\Domain\Communication\ValueObject\EmailAddress;
use PHPUnit\Framework\TestCase;

final class EmailAddressTest extends TestCase
{
    public function testAcceptsValidEmail(): void
    {
        $email = new EmailAddress('user@example.com');
        self::assertSame('user@example.com', (string) $email);
    }

    public function testNormalizesToLowercase(): void
    {
        $email = new EmailAddress('User@Example.COM');
        self::assertSame('user@example.com', (string) $email);
    }

    public function testTrimsWhitespace(): void
    {
        $email = new EmailAddress('  user@example.com  ');
        self::assertSame('user@example.com', (string) $email);
    }

    public function testAcceptsEmailWithPlus(): void
    {
        $email = new EmailAddress('user+tag@example.com');
        self::assertSame('user+tag@example.com', (string) $email);
    }

    public function testAcceptsEmailWithDots(): void
    {
        $email = new EmailAddress('first.last@example.com');
        self::assertSame('first.last@example.com', (string) $email);
    }

    public function testAcceptsSubdomain(): void
    {
        $email = new EmailAddress('user@mail.example.com');
        self::assertSame('user@mail.example.com', (string) $email);
    }

    public function testRejectsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmailAddress('');
    }

    public function testRejectsMissingAt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmailAddress('not-an-email');
    }

    public function testRejectsMissingDomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmailAddress('user@');
    }

    public function testRejectsMissingLocal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmailAddress('@example.com');
    }

    public function testRejectsMultipleAt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmailAddress('user@@example.com');
    }

    public function testRejectsSpaces(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmailAddress('user name@example.com');
    }

    public function testIsImmutable(): void
    {
        $reflection = new \ReflectionClass(EmailAddress::class);
        self::assertTrue($reflection->isFinal());

        foreach ($reflection->getProperties() as $property) {
            self::assertTrue($property->isReadOnly());
        }
    }

    public function testGetDomain(): void
    {
        $email = new EmailAddress('user@example.com');
        self::assertSame('example.com', $email->getDomain());
    }

    public function testEquality(): void
    {
        $a = new EmailAddress('user@example.com');
        $b = new EmailAddress('USER@example.com');
        $c = new EmailAddress('other@example.com');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}

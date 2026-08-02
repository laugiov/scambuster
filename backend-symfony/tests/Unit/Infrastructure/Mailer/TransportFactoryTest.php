<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Mailer;

use App\Infrastructure\Mailer\TransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class TransportFactoryTest extends TestCase
{
    public function testFromDsnReturnsTransportInterface(): void
    {
        $factory = new TransportFactory();
        $transport = $factory->fromDsn('null://null');

        self::assertInstanceOf(TransportInterface::class, $transport);
    }

    public function testFromDsnAcceptsSmtpDsn(): void
    {
        $factory = new TransportFactory();
        $transport = $factory->fromDsn('smtp://localhost:25');

        self::assertInstanceOf(TransportInterface::class, $transport);
    }

    public function testFromDsnAcceptsSendmail(): void
    {
        $factory = new TransportFactory();
        $transport = $factory->fromDsn('sendmail://default');

        self::assertInstanceOf(TransportInterface::class, $transport);
    }

    public function testFromDsnThrowsOnInvalidDsn(): void
    {
        $factory = new TransportFactory();

        $this->expectException(\InvalidArgumentException::class);
        $factory->fromDsn('not-a-valid-dsn');
    }

    public function testFromDsnThrowsOnEmptyString(): void
    {
        $factory = new TransportFactory();

        $this->expectException(\InvalidArgumentException::class);
        $factory->fromDsn('');
    }
}

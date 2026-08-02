<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocDecayConfig;
use PHPUnit\Framework\TestCase;

class IocDecayConfigTest extends TestCase
{
    public function testUrlHalfLife(): void
    {
        $this->assertSame(14, IocDecayConfig::getHalfLifeDays('url'));
    }

    public function testIpv4HalfLife(): void
    {
        $this->assertSame(7, IocDecayConfig::getHalfLifeDays('ipv4'));
    }

    public function testDomainHalfLife(): void
    {
        $this->assertSame(30, IocDecayConfig::getHalfLifeDays('domain'));
    }

    public function testHashHalfLife(): void
    {
        $this->assertSame(365, IocDecayConfig::getHalfLifeDays('sha256'));
    }

    public function testFinancialHalfLife(): void
    {
        $this->assertSame(180, IocDecayConfig::getHalfLifeDays('iban'));
        $this->assertSame(180, IocDecayConfig::getHalfLifeDays('wallet_btc'));
    }

    public function testUnknownTypeReturnsDefault(): void
    {
        $this->assertSame(30, IocDecayConfig::getHalfLifeDays('unknown_type'));
    }

    public function testCaseInsensitive(): void
    {
        $this->assertSame(14, IocDecayConfig::getHalfLifeDays('URL'));
        $this->assertSame(7, IocDecayConfig::getHalfLifeDays('IPv4'));
    }

    public function testGetAllHalfLives(): void
    {
        $all = IocDecayConfig::getAllHalfLives();
        $this->assertIsArray($all);
        $this->assertArrayHasKey('url', $all);
        $this->assertArrayHasKey('domain', $all);
        $this->assertGreaterThan(10, count($all));
    }
}

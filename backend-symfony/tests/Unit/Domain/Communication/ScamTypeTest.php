<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication;

use App\Domain\Communication\ScamType;
use PHPUnit\Framework\TestCase;

class ScamTypeTest extends TestCase
{
    public function test_it_creates_scam_type_with_attack_id(): void
    {
        $scamType = new ScamType(
            'PHISH_CREDENTIALS',
            'Credential Phish',
            'Targets login/MFA (O365, banking, webmail)',
            'rsit:fraud="phishing"',
            'T1566.002',
            true,
            new \DateTimeImmutable(),
            new \DateTimeImmutable()
        );

        $this->assertSame('PHISH_CREDENTIALS', $scamType->getCode());
        $this->assertSame('Credential Phish', $scamType->getLabel());
        $this->assertSame('Targets login/MFA (O365, banking, webmail)', $scamType->getDescription());
        $this->assertSame('rsit:fraud="phishing"', $scamType->getMispTaxonomy());
        $this->assertSame('T1566.002', $scamType->getAttckTechnique());
        $this->assertTrue($scamType->isActive());
    }

    public function test_it_creates_scam_type_without_attack_id(): void
    {
        $scamType = new ScamType(
            'ROMANCE',
            'Romance Scam',
            'Builds trust, then asks for money',
            'rsit:fraud="scam"',
            null,
            true,
            new \DateTimeImmutable(),
            new \DateTimeImmutable()
        );

        $this->assertSame('ROMANCE', $scamType->getCode());
        $this->assertSame('Romance Scam', $scamType->getLabel());
        $this->assertSame('Builds trust, then asks for money', $scamType->getDescription());
        $this->assertSame('rsit:fraud="scam"', $scamType->getMispTaxonomy());
        $this->assertNull($scamType->getAttckTechnique());
        $this->assertTrue($scamType->isActive());
    }
}

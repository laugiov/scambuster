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
            'Phishing d\'identifiants',
            'Vise login/MFA (O365, banque, webmail)',
            'rsit:fraud="phishing"',
            'T1566.002',
            true,
            new \DateTimeImmutable(),
            new \DateTimeImmutable()
        );

        $this->assertSame('PHISH_CREDENTIALS', $scamType->getCode());
        $this->assertSame('Phishing d\'identifiants', $scamType->getLabel());
        $this->assertSame('Vise login/MFA (O365, banque, webmail)', $scamType->getDescription());
        $this->assertSame('rsit:fraud="phishing"', $scamType->getMispTaxonomy());
        $this->assertSame('T1566.002', $scamType->getAttckTechnique());
        $this->assertTrue($scamType->isActive());
    }

    public function test_it_creates_scam_type_without_attack_id(): void
    {
        $scamType = new ScamType(
            'ROMANCE_SCAM',
            'Arnaque sentimentale',
            'Etablit confiance puis demande argent',
            'rsit:fraud="scam"',
            null,
            true,
            new \DateTimeImmutable(),
            new \DateTimeImmutable()
        );

        $this->assertSame('ROMANCE_SCAM', $scamType->getCode());
        $this->assertSame('Arnaque sentimentale', $scamType->getLabel());
        $this->assertSame('Etablit confiance puis demande argent', $scamType->getDescription());
        $this->assertSame('rsit:fraud="scam"', $scamType->getMispTaxonomy());
        $this->assertNull($scamType->getAttckTechnique());
        $this->assertTrue($scamType->isActive());
    }
}

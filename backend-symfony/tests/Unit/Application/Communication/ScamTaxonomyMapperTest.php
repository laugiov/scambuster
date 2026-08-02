<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\ScamTaxonomyMapper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure scam-taxonomy → CTI-tag mapper.
 *
 * The ATT&CK galaxy names are externally-sourced CTI display strings; these
 * tests pin the exact verified values and — critically — the fail-safe: an
 * unmapped or absent technique yields NO galaxy tag (never a fabricated one).
 */
class ScamTaxonomyMapperTest extends TestCase
{
    private ScamTaxonomyMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ScamTaxonomyMapper();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function verifiedGalaxyProvider(): array
    {
        return [
            'T1566'     => ['T1566', 'misp-galaxy:mitre-attack-pattern="Phishing - T1566"'],
            'T1566.001' => ['T1566.001', 'misp-galaxy:mitre-attack-pattern="Spearphishing Attachment - T1566.001"'],
            'T1566.002' => ['T1566.002', 'misp-galaxy:mitre-attack-pattern="Spearphishing Link - T1566.002"'],
            'T1566.003' => ['T1566.003', 'misp-galaxy:mitre-attack-pattern="Spearphishing via Service - T1566.003"'],
            'T1656'     => ['T1656', 'misp-galaxy:mitre-attack-pattern="Impersonation - T1656"'],
        ];
    }

    /**
     * @dataProvider verifiedGalaxyProvider
     */
    public function testAttckGalaxyTagForVerifiedTechniques(string $id, string $expected): void
    {
        self::assertSame($expected, $this->mapper->attckGalaxyTag($id));
    }

    public function testAttckGalaxyTagIsNullForUnmappedTechnique(): void
    {
        // Fail-safe: a technique we have not verified must NOT produce a
        // fabricated galaxy string.
        self::assertNull($this->mapper->attckGalaxyTag('T9999'));
    }

    public function testAttckGalaxyTagIsNullForNullOrEmpty(): void
    {
        self::assertNull($this->mapper->attckGalaxyTag(null));
        self::assertNull($this->mapper->attckGalaxyTag(''));
    }

    public function testRsitTagPassesThroughStoredMachineTag(): void
    {
        self::assertSame('rsit:fraud="phishing"', $this->mapper->rsitTag('rsit:fraud="phishing"'));
        self::assertSame('rsit:fraud="scam"', $this->mapper->rsitTag('  rsit:fraud="scam"  '));
    }

    public function testRsitTagIsNullForNullOrEmpty(): void
    {
        self::assertNull($this->mapper->rsitTag(null));
        self::assertNull($this->mapper->rsitTag(''));
        self::assertNull($this->mapper->rsitTag('   '));
    }

    public function testScamTypeTagFormat(): void
    {
        self::assertSame('scambuster:scam-type="ROMANCE"', $this->mapper->scamTypeTag('ROMANCE'));
    }

    public function testScamTypeTagNormalisesToUpperCase(): void
    {
        self::assertSame('scambuster:scam-type="ROMANCE"', $this->mapper->scamTypeTag('romance'));
    }
}

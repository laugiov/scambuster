<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication;

use App\Domain\Communication\Service\TtpStixIdGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The TTP attack-pattern STIX id must be deterministic (same code -> same id,
 * forever) so the catalogue re-imports idempotently into OpenCTI, and must
 * reject an empty code rather than mint a meaningless id.
 */
final class TtpStixIdGeneratorTest extends TestCase
{
    private TtpStixIdGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new TtpStixIdGenerator();
    }

    public function testIdIsWellFormedAttackPatternId(): void
    {
        $id = $this->generator->attackPatternId('SB-T001');

        self::assertMatchesRegularExpression(
            '/^attack-pattern--[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
            'Must be an attack-pattern-- prefixed RFC 4122 UUIDv5',
        );
    }

    public function testIdIsDeterministicForTheSameCode(): void
    {
        self::assertSame(
            $this->generator->attackPatternId('SB-T013'),
            $this->generator->attackPatternId('SB-T013'),
        );
    }

    public function testKnownVectorPinsTheAlgorithm(): void
    {
        // Locks the seed recipe ('scambuster:ttp:v1:<CODE>' in the URL namespace):
        // a change here would silently re-key every attack-pattern in OpenCTI.
        self::assertSame(
            'attack-pattern--a49ae9cd-c3ef-55ab-8d40-b90bee8ad544',
            $this->generator->attackPatternId('SB-T001'),
        );
    }

    public function testDifferentCodesYieldDifferentIds(): void
    {
        self::assertNotSame(
            $this->generator->attackPatternId('SB-T001'),
            $this->generator->attackPatternId('SB-T002'),
        );
    }

    public function testEmptyCodeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator->attackPatternId('');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\ThreatActor;

use App\Domain\ThreatActor\AnalystVerdict;
use PHPUnit\Framework\TestCase;

final class AnalystVerdictTest extends TestCase
{
    public function testValuesAreTheTwoVerdicts(): void
    {
        self::assertSame(['confirmed', 'false_positive'], AnalystVerdict::values());
    }

    public function testTryFromParsesKnownVerdicts(): void
    {
        self::assertSame(AnalystVerdict::Confirmed, AnalystVerdict::tryFrom('confirmed'));
        self::assertSame(AnalystVerdict::FalsePositive, AnalystVerdict::tryFrom('false_positive'));
    }

    public function testTryFromReturnsNullOnUnknown(): void
    {
        self::assertNull(AnalystVerdict::tryFrom('maybe'));
        self::assertNull(AnalystVerdict::tryFrom(''));
    }
}

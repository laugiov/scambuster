<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\ThreatActor;

use App\Domain\ThreatActor\CialdiniLever;
use PHPUnit\Framework\TestCase;

final class CialdiniLeverTest extends TestCase
{
    public function testNamesReturnsTheEightRuleSevenLabels(): void
    {
        self::assertSame(
            ['Authority', 'Urgency', 'Scarcity', 'Secrecy', 'Reciprocity', 'Liking', 'SocialProof', 'None'],
            CialdiniLever::names(),
        );
    }

    public function testTryFromLabelIsCaseInsensitive(): void
    {
        self::assertSame(CialdiniLever::SocialProof, CialdiniLever::tryFromLabel('socialproof'));
        self::assertSame(CialdiniLever::Authority, CialdiniLever::tryFromLabel('  AUTHORITY '));
        self::assertSame(CialdiniLever::None, CialdiniLever::tryFromLabel('None'));
    }

    public function testTryFromLabelReturnsNullOnUnknown(): void
    {
        self::assertNull(CialdiniLever::tryFromLabel('Gaslighting'));
        self::assertNull(CialdiniLever::tryFromLabel(''));
    }

    public function testBackingValuesMatchTheAnalyzerVocabulary(): void
    {
        self::assertSame('SocialProof', CialdiniLever::SocialProof->value);
        self::assertSame('Reciprocity', CialdiniLever::Reciprocity->value);
    }
}

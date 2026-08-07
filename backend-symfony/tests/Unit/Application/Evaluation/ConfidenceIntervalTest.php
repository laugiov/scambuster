<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation;

use App\Application\Evaluation\ConfidenceInterval;
use PHPUnit\Framework\TestCase;

/**
 * A per-arm reward average is only defensible with its sample size and a
 * confidence interval. Small samples (the bandit's common case) must be flagged
 * unreliable rather than reported as a point effect.
 */
final class ConfidenceIntervalTest extends TestCase
{
    public function testStudentTIntervalForSmallSample(): void
    {
        // [1,2,3,4,5]: mean 3, sample sd 1.5811, se 0.7071, t(df=4)=2.776,
        // margin ≈ 1.963 → CI ≈ [1.037, 4.963].
        $ci = ConfidenceInterval::forMean([1.0, 2.0, 3.0, 4.0, 5.0]);

        self::assertSame(5, $ci['n']);
        self::assertEqualsWithDelta(3.0, $ci['mean'], 0.0001);
        self::assertEqualsWithDelta(1.5811, $ci['stddev'], 0.001);
        self::assertEqualsWithDelta(1.963, $ci['margin'], 0.001);
        self::assertEqualsWithDelta(1.037, $ci['lower'], 0.001);
        self::assertEqualsWithDelta(4.963, $ci['upper'], 0.001);
        self::assertFalse($ci['reliable'], 'n=5 is below the reliability threshold');
    }

    public function testSingleSampleHasNoInterval(): void
    {
        $ci = ConfidenceInterval::forMean([0.42]);

        self::assertSame(1, $ci['n']);
        self::assertEqualsWithDelta(0.42, $ci['mean'], 0.0001);
        self::assertNull($ci['margin'], 'a single observation has no confidence interval');
        self::assertNull($ci['lower']);
        self::assertNull($ci['upper']);
        self::assertFalse($ci['reliable']);
    }

    public function testEmptySample(): void
    {
        $ci = ConfidenceInterval::forMean([]);

        self::assertSame(0, $ci['n']);
        self::assertFalse($ci['reliable']);
        self::assertNull($ci['margin']);
    }

    public function testLargeSampleIsFlaggedReliable(): void
    {
        $samples = array_fill(0, 12, 0.5);
        $samples[0] = 0.6; // a little spread so stddev > 0
        $ci = ConfidenceInterval::forMean($samples);

        self::assertSame(12, $ci['n']);
        self::assertTrue($ci['reliable'], 'n>=10 with a real interval is reliable');
        self::assertNotNull($ci['margin']);
        self::assertGreaterThan($ci['lower'], $ci['upper'], 'upper bound is above the lower bound');
    }
}

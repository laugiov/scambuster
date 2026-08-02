<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Guard;

use App\Application\Guard\CanarySummary;
use PHPUnit\Framework\TestCase;

final class CanarySummaryTest extends TestCase
{
    public function testAggregatesPerFixtureAndOverall(): void
    {
        $s = new CanarySummary();
        // fixture "a": 2 runs — 1 approved / 1 rejected, 1 fallback
        $s->record('a', true, 1, false, 0.01, 'OUT-A1');
        $s->record('a', false, 2, true, 0.02, 'OUT-A2');
        // fixture "b": 1 run — approved, no fallback
        $s->record('b', true, 1, false, 0.03);

        $arr = $s->toArray();

        self::assertSame(2, $arr['aggregate']['fixtures_count']);
        self::assertSame(3, $arr['aggregate']['total_runs']);

        // per-fixture averages
        self::assertEqualsWithDelta(0.5, $arr['fixtures']['a']['approved_rate'], 1e-9);
        self::assertEqualsWithDelta(0.5, $arr['fixtures']['a']['fallback_rate'], 1e-9);
        self::assertEqualsWithDelta(1.5, $arr['fixtures']['a']['attempts_avg'], 1e-9);
        self::assertSame(['OUT-A1', 'OUT-A2'], $arr['fixtures']['a']['out_texts']);
        self::assertSame([], $arr['fixtures']['b']['out_texts']);

        // overall: 2 approved / 3 runs, 1 fallback / 3 runs
        self::assertEqualsWithDelta(2 / 3, $arr['aggregate']['approved_rate'], 1e-9);
        self::assertEqualsWithDelta(1 / 3, $arr['aggregate']['fallback_rate'], 1e-9);
        self::assertEqualsWithDelta((1 + 2 + 1) / 3, $arr['aggregate']['attempts_avg'], 1e-9);
        self::assertEqualsWithDelta(0.06, $arr['aggregate']['total_cost'], 1e-9);
    }

    public function testToJsonIsValidJson(): void
    {
        $s = new CanarySummary();
        $s->record('x', true, 1, false, 0.0);

        $decoded = json_decode($s->toJson(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('aggregate', $decoded);
        self::assertArrayHasKey('fixtures', $decoded);
        self::assertArrayHasKey('x', $decoded['fixtures']);
    }

    public function testEmptySummaryHasZeroAggregate(): void
    {
        $arr = (new CanarySummary())->toArray();

        self::assertSame(0, $arr['aggregate']['fixtures_count']);
        self::assertSame(0, $arr['aggregate']['total_runs']);
        self::assertSame(0, $arr['aggregate']['errors']);
        self::assertSame(0.0, $arr['aggregate']['approved_rate']);
        self::assertSame(0.0, $arr['aggregate']['fallback_rate']);
        self::assertSame(0.0, $arr['aggregate']['total_cost']);
    }

    public function testToJsonPreservesFloatTypeForWholeNumbers(): void
    {
        $s = new CanarySummary();
        $s->record('x', true, 1, false, 0.0); // approved_rate=1.0, fallback_rate=0.0

        $json = $s->toJson();

        // Whole-number rates must serialize as floats (1.0, not 1) so a frozen baseline
        // keeps a stable numeric type across values.
        self::assertStringContainsString('"approved_rate": 1.0', $json);
        self::assertStringContainsString('"fallback_rate": 0.0', $json);
    }

    public function testEmptyOutTextIsExcludedLikeNull(): void
    {
        $s = new CanarySummary();
        $s->record('x', true, 1, false, 0.0, '');    // empty → excluded
        $s->record('x', true, 1, false, 0.0, null);  // null → excluded
        $s->record('x', true, 1, false, 0.0, 'OUT'); // kept

        self::assertSame(['OUT'], $s->toArray()['fixtures']['x']['out_texts']);
    }

    public function testRecordErrorSurfacesInAggregate(): void
    {
        $s = new CanarySummary();
        $s->record('x', true, 1, false, 0.0);
        $s->recordError();
        $s->recordError();

        self::assertSame(2, $s->toArray()['aggregate']['errors']);
    }
}

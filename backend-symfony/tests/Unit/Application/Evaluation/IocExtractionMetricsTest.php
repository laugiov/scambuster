<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation;

use App\Application\Evaluation\IocExtractionMetrics;
use PHPUnit\Framework\TestCase;

/**
 * Precision / recall / F1 of IOC extraction against a human-annotated gold set —
 * the number the audit says is missing (recall is unmeasured by construction).
 * Each IOC is a "type:value_norm" key; matching is exact on that key.
 */
final class IocExtractionMetricsTest extends TestCase
{
    public function testPerfectExtraction(): void
    {
        $r = IocExtractionMetrics::score([
            ['gold' => ['iban:GB29…', 'phone:33612'], 'predicted' => ['iban:GB29…', 'phone:33612']],
        ]);

        self::assertSame(1.0, $r['overall']['precision']);
        self::assertSame(1.0, $r['overall']['recall']);
        self::assertSame(1.0, $r['overall']['f1']);
    }

    public function testMissedIocLowersRecall(): void
    {
        // gold has 2, predicted 1 (a false negative).
        $r = IocExtractionMetrics::score([
            ['gold' => ['iban:A', 'wallet_btc:B'], 'predicted' => ['iban:A']],
        ]);

        self::assertSame(1.0, $r['overall']['precision']);
        self::assertSame(0.5, $r['overall']['recall']);
        self::assertEqualsWithDelta(0.6667, $r['overall']['f1'], 0.0001);
        self::assertSame(1, $r['overall']['fn']);
    }

    public function testSpuriousIocLowersPrecision(): void
    {
        // predicted has an extra IOC not in gold (a false positive).
        $r = IocExtractionMetrics::score([
            ['gold' => ['iban:A'], 'predicted' => ['iban:A', 'phone:X']],
        ]);

        self::assertSame(0.5, $r['overall']['precision']);
        self::assertSame(1.0, $r['overall']['recall']);
        self::assertSame(1, $r['overall']['fp']);
    }

    public function testPerTypeBreakdown(): void
    {
        $r = IocExtractionMetrics::score([
            ['gold' => ['iban:A', 'phone:P'], 'predicted' => ['iban:A', 'phone:Q']],
        ]);

        // iban: perfect. phone: 1 FP (Q) + 1 FN (P) → P=0, R=0.
        self::assertSame(1.0, $r['by_type']['iban']['precision']);
        self::assertSame(1.0, $r['by_type']['iban']['recall']);
        self::assertSame(0.0, $r['by_type']['phone']['precision']);
        self::assertSame(0.0, $r['by_type']['phone']['recall']);
    }

    public function testEmptyPredictionsGiveZeroPrecisionAndRecall(): void
    {
        $r = IocExtractionMetrics::score([
            ['gold' => ['iban:A'], 'predicted' => []],
        ]);

        self::assertSame(0.0, $r['overall']['precision'], 'no predictions → precision defined as 0');
        self::assertSame(0.0, $r['overall']['recall']);
    }
}

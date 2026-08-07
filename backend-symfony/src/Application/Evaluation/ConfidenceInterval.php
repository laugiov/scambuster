<?php

declare(strict_types=1);

namespace App\Application\Evaluation;

/**
 * Two-sided 95% confidence interval for a sample mean (Student-t).
 *
 * The bandit reports per-persona reward averages over very small samples; a mean
 * without its n and interval is not a defensible effect. This pairs every average
 * with an interval and a `reliable` flag (n below the threshold — the common case —
 * is flagged rather than presented as a point estimate).
 */
final class ConfidenceInterval
{
    /** Below this sample size a per-arm average is not reliable (audit §7.3: <10). */
    public const MIN_RELIABLE_N = 10;

    /** Two-sided 95% Student-t critical values by degrees of freedom (1..30). */
    private const T_95 = [
        1 => 12.706, 2 => 4.303, 3 => 3.182, 4 => 2.776, 5 => 2.571,
        6 => 2.447, 7 => 2.365, 8 => 2.306, 9 => 2.262, 10 => 2.228,
        11 => 2.201, 12 => 2.179, 13 => 2.160, 14 => 2.145, 15 => 2.131,
        16 => 2.120, 17 => 2.110, 18 => 2.101, 19 => 2.093, 20 => 2.086,
        21 => 2.080, 22 => 2.074, 23 => 2.069, 24 => 2.064, 25 => 2.060,
        26 => 2.056, 27 => 2.052, 28 => 2.048, 29 => 2.045, 30 => 2.042,
    ];

    /**
     * @param list<float> $samples
     *
     * @return array{n: int, mean: float, stddev: float, std_error: float|null, margin: float|null, lower: float|null, upper: float|null, reliable: bool}
     */
    public static function forMean(array $samples): array
    {
        $n = \count($samples);

        if ($n === 0) {
            return self::result(0, 0.0, 0.0, null, null);
        }

        $mean = array_sum($samples) / $n;

        // A single observation has no dispersion and no interval.
        if ($n < 2) {
            return self::result(1, $mean, 0.0, null, null);
        }

        $variance = 0.0;

        foreach ($samples as $x) {
            $variance += ($x - $mean) ** 2;
        }

        $stddev = sqrt($variance / ($n - 1));
        $stdError = $stddev / sqrt($n);
        $margin = self::tCritical($n - 1) * $stdError;

        return self::result($n, $mean, $stddev, $stdError, $margin);
    }

    /**
     * @return array{n: int, mean: float, stddev: float, std_error: float|null, margin: float|null, lower: float|null, upper: float|null, reliable: bool}
     */
    private static function result(int $n, float $mean, float $stddev, ?float $stdError, ?float $margin): array
    {
        return [
            'n' => $n,
            'mean' => round($mean, 4),
            'stddev' => round($stddev, 4),
            'std_error' => $stdError !== null ? round($stdError, 4) : null,
            'margin' => $margin !== null ? round($margin, 4) : null,
            'lower' => $margin !== null ? round($mean - $margin, 4) : null,
            'upper' => $margin !== null ? round($mean + $margin, 4) : null,
            'reliable' => $n >= self::MIN_RELIABLE_N && $margin !== null,
        ];
    }

    private static function tCritical(int $df): float
    {
        // Beyond df=30 the t-distribution is close enough to the normal (z=1.96).
        return self::T_95[$df] ?? 1.96;
    }
}

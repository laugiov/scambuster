<?php

declare(strict_types=1);

namespace App\Application\Evaluation;

/**
 * Precision / recall / F1 for IOC extraction against a human-annotated gold set.
 *
 * The audit notes no precision/recall is reported anywhere and recall is unmeasured
 * by construction. This computes both (overall and per IOC type) from a gold set
 * and the system's predictions, so a defensible extraction accuracy can be published
 * once real human annotations exist. Each IOC is a "type:value_norm" key; a true
 * positive is an exact key match.
 */
final class IocExtractionMetrics
{
    /**
     * @param list<array{gold: list<string>, predicted: list<string>}> $documents
     *
     * @return array{
     *     overall: array{precision: float, recall: float, f1: float, tp: int, fp: int, fn: int},
     *     by_type: array<string, array{precision: float, recall: float, f1: float, tp: int, fp: int, fn: int}>,
     *     documents: int
     * }
     */
    public static function score(array $documents): array
    {
        $tp = 0;
        $fp = 0;
        $fn = 0;
        /** @var array<string, array{tp: int, fp: int, fn: int}> $perType */
        $perType = [];

        foreach ($documents as $doc) {
            $gold = array_values(array_unique($doc['gold']));
            $predicted = array_values(array_unique($doc['predicted']));

            $goldSet = array_fill_keys($gold, true);
            $predSet = array_fill_keys($predicted, true);

            foreach ($predicted as $key) {
                $type = self::typeOf($key);
                $perType[$type] ??= ['tp' => 0, 'fp' => 0, 'fn' => 0];

                if (isset($goldSet[$key])) {
                    $tp++;
                    $perType[$type]['tp']++;
                } else {
                    $fp++;
                    $perType[$type]['fp']++;
                }
            }

            foreach ($gold as $key) {
                if (!isset($predSet[$key])) {
                    $type = self::typeOf($key);
                    $perType[$type] ??= ['tp' => 0, 'fp' => 0, 'fn' => 0];
                    $fn++;
                    $perType[$type]['fn']++;
                }
            }
        }

        $byType = [];

        foreach ($perType as $type => $c) {
            $byType[$type] = self::rates($c['tp'], $c['fp'], $c['fn']);
        }

        ksort($byType);

        return [
            'overall' => self::rates($tp, $fp, $fn),
            'by_type' => $byType,
            'documents' => \count($documents),
        ];
    }

    /**
     * @return array{precision: float, recall: float, f1: float, tp: int, fp: int, fn: int}
     */
    private static function rates(int $tp, int $fp, int $fn): array
    {
        // Undefined ratios (no predictions / no gold) are reported as 0, never
        // silently inflated.
        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;

        return [
            'precision' => round($precision, 4),
            'recall' => round($recall, 4),
            'f1' => round($f1, 4),
            'tp' => $tp,
            'fp' => $fp,
            'fn' => $fn,
        ];
    }

    private static function typeOf(string $key): string
    {
        $pos = strpos($key, ':');

        return $pos === false ? $key : substr($key, 0, $pos);
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Evaluation;

use App\Application\Evaluation\Metric\IocElicitationMetric;
use App\Application\Evaluation\Metric\LanguageComplianceMetric;
use App\Application\Evaluation\Metric\MetricResult;
use App\Application\Evaluation\Metric\NaturalnessMetric;
use App\Application\Evaluation\Metric\NonRepetitivenessMetric;
use App\Application\Evaluation\Metric\OpeningDiversityMetric;
use App\Application\Evaluation\Metric\PersonaDistinctnessMetric;
use App\Application\Evaluation\Metric\SafetyMetric;

/**
 * Analyzes a generated corpus across 6 quality dimensions (10 metrics total).
 */
final class ReplyQualityAnalyzer
{
    /**
     * @param array<int, array<string, mixed>> $corpus
     *
     * @return array{metrics: MetricResult[], overall_verdict: string, best_replies: array<int, array<string, mixed>>, worst_replies: array<int, array<string, mixed>>, persona_matrix: array<string, array<string, float>>, corpus_size: int}
     */
    public function analyze(array $corpus): array
    {
        $nonRep = new NonRepetitivenessMetric();
        $opening = new OpeningDiversityMetric();
        $persona = new PersonaDistinctnessMetric();
        $naturalness = new NaturalnessMetric();
        $language = new LanguageComplianceMetric();
        $ioc = new IocElicitationMetric();
        $safety = new SafetyMetric();

        $metrics = [
            $nonRep->compute($corpus),
            $opening->compute($corpus),
            $persona->compute($corpus),
            $naturalness->compute($corpus),
            $naturalness->computeAvgScore($corpus),
            $language->compute($corpus),
            $ioc->compute($corpus),
            $safety->compute($corpus),
            $safety->computeFallbackRate($corpus),
        ];

        $passCount = 0;
        $failCount = 0;

        foreach ($metrics as $m) {
            if ($m->verdict === 'PASS') {
                ++$passCount;
            } elseif ($m->verdict === 'FAIL') {
                ++$failCount;
            }
        }

        $totalDecided = $passCount + $failCount;
        $overallVerdict = 'INSUFFICIENT_DATA';

        if ($totalDecided > 0) {
            $passRate = $passCount / $totalDecided;
            $overallVerdict = $passRate >= 0.70 ? 'PASS' : ($passRate >= 0.50 ? 'WARNING' : 'FAIL');
        }

        return [
            'metrics' => $metrics,
            'overall_verdict' => $overallVerdict,
            'best_replies' => $this->extractBest($corpus, 5),
            'worst_replies' => $this->extractWorst($corpus, 5),
            'persona_matrix' => $persona->getSimilarityMatrix($corpus),
            'corpus_size' => count($corpus),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $corpus
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractBest(array $corpus, int $count): array
    {
        $scored = array_filter($corpus, fn (array $e): bool => isset($e['naturalness']) && $e['naturalness'] > 0 && !($e['fallback_used'] ?? false));

        usort($scored, static function (array $a, array $b): int {
            /** @var int $na */
            $na = $a['naturalness'];
            /** @var int $nb */
            $nb = $b['naturalness'];

            return $nb <=> $na;
        });

        return array_slice($scored, 0, $count);
    }

    /**
     * @param array<int, array<string, mixed>> $corpus
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractWorst(array $corpus, int $count): array
    {
        $scored = array_filter($corpus, fn (array $e): bool => isset($e['naturalness']) && $e['naturalness'] > 0 && !($e['fallback_used'] ?? false));

        usort($scored, static function (array $a, array $b): int {
            /** @var int $na */
            $na = $a['naturalness'];
            /** @var int $nb */
            $nb = $b['naturalness'];

            return $na <=> $nb;
        });

        return array_slice($scored, 0, $count);
    }
}

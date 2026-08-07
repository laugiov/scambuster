<?php

declare(strict_types=1);

namespace App\Application\Evaluation;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Analyzes epsilon-greedy persona selection convergence per scam type.
 */
final readonly class BanditAnalyzer
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param int $minSessions Minimum sessions per scam type to report
     *
     * @return array<string, mixed>
     */
    public function analyze(int $minSessions = 3): array
    {
        $conn = $this->em->getConnection();

        $conversations = $conn->fetchAllAssociative(<<<'SQL'
            SELECT
                c.conv_id,
                st.code AS scam_type,
                p.persona_code,
                c.reward_value,
                c.status,
                c.engagement_duration_sec,
                c.created_at
            FROM conversation c
            LEFT JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
            LEFT JOIN persona p ON c.persona_id = p.persona_id
            WHERE st.code IS NOT NULL AND p.persona_code IS NOT NULL
            ORDER BY st.code, c.created_at
            SQL);

        $byScamType = [];

        foreach ($conversations as $row) {
            $scamType = $row['scam_type'];
            $byScamType[$scamType][] = $row;
        }

        $analyses = [];
        $totalRegret = 0.0;
        $totalRandomRegret = 0.0;
        $convergedCount = 0;
        $activeCount = 0;

        foreach ($byScamType as $scamType => $rows) {
            if (count($rows) < $minSessions) {
                continue;
            }

            ++$activeCount;
            $analysis = $this->analyzeScamType($scamType, $rows);
            $analyses[] = $analysis;

            if ($analysis['converged']) {
                ++$convergedCount;
            }

            /** @var float $regret */
            $regret = $analysis['regret'];
            /** @var float $randomRegret */
            $randomRegret = $analysis['random_regret'];
            $totalRegret += $regret;
            $totalRandomRegret += $randomRegret;
        }

        $convergenceRate = $activeCount > 0 ? $convergedCount / $activeCount : 0.0;

        return [
            'total_conversations' => count($conversations),
            'active_scam_types' => $activeCount,
            'overall_convergence' => $convergenceRate >= 0.50,
            'convergence_rate' => round($convergenceRate, 2),
            'scam_type_analyses' => $analyses,
            'cumulative_regret' => round($totalRegret, 4),
            'random_baseline_regret' => round($totalRandomRegret, 4),
            'cold_start_analysis' => $this->analyzeColdStart($conversations),
            'generated_at' => date(\DATE_ATOM),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function analyzeScamType(string $scamType, array $rows): array
    {
        $personaCounts = [];
        $personaRewards = [];

        foreach ($rows as $row) {
            $persona = $row['persona_code'];
            $personaCounts[$persona] = ($personaCounts[$persona] ?? 0) + 1;
            $reward = $row['reward_value'];

            if ($reward !== null && \is_numeric($reward)) {
                $personaRewards[$persona][] = (float) $reward;
            }
        }

        arsort($personaCounts);
        $totalSessions = count($rows);
        $dominantPersona = array_key_first($personaCounts);
        $dominantCount = $personaCounts[$dominantPersona] ?? 0;
        $dominantPct = $totalSessions > 0 ? $dominantCount / $totalSessions : 0.0;

        $converged = $dominantPct > 0.60 && $totalSessions >= 10;

        $rewardStats = $this->computeRewardStats($personaRewards);
        $regret = $this->computeRegret($personaRewards, $totalSessions);
        $randomRegret = $this->computeRandomRegret($personaRewards, $totalSessions);

        return [
            'scam_type' => $scamType,
            'sessions_count' => $totalSessions,
            'dominant_persona' => $dominantPersona,
            'dominant_percentage' => round($dominantPct, 3),
            'converged' => $converged,
            'persona_distribution' => $personaCounts,
            'reward_stats' => $rewardStats,
            'regret' => round($regret, 4),
            'random_regret' => round($randomRegret, 4),
        ];
    }

    /**
     * @param array<string, array<int, float>> $personaRewards
     *
     * @return array<string, array{count: int, mean: float, stddev: float, ci_lower: float|null, ci_upper: float|null, ci_margin: float|null, reliable: bool, q1: float, median: float, q3: float}>
     */
    private function computeRewardStats(array $personaRewards): array
    {
        $stats = [];

        foreach ($personaRewards as $persona => $rewards) {
            $n = count($rewards);

            if ($n === 0) {
                continue;
            }

            sort($rewards);
            $mean = array_sum($rewards) / $n;
            $variance = 0.0;

            foreach ($rewards as $r) {
                $variance += ($r - $mean) ** 2;
            }

            $stddev = $n > 1 ? sqrt($variance / ($n - 1)) : 0.0;

            // A per-arm average is not a defensible effect without its interval:
            // pair every mean with a 95% CI and flag small samples as unreliable.
            // $rewards is a list of floats (re-indexed by sort() above).
            $ci = ConfidenceInterval::forMean($rewards);

            $stats[$persona] = [
                'count' => $n,
                'mean' => round($mean, 4),
                'stddev' => round($stddev, 4),
                'ci_lower' => $ci['lower'],
                'ci_upper' => $ci['upper'],
                'ci_margin' => $ci['margin'],
                'reliable' => $ci['reliable'],
                'q1' => round($rewards[(int) floor($n * 0.25)] ?? 0, 4),
                'median' => round($rewards[(int) floor($n * 0.50)] ?? 0, 4),
                'q3' => round($rewards[(int) floor($n * 0.75)] ?? 0, 4),
            ];
        }

        return $stats;
    }

    /**
     * Regret vs oracle (best persona for each scam type).
     *
     * @param array<string, array<int, float>> $personaRewards
     */
    private function computeRegret(array $personaRewards, int $totalSessions): float
    {
        $bestMean = 0.0;

        foreach ($personaRewards as $rewards) {
            if (!empty($rewards)) {
                $mean = array_sum($rewards) / count($rewards);
                $bestMean = max($bestMean, $mean);
            }
        }

        $allRewards = array_merge(...array_values($personaRewards));
        $actualTotal = $allRewards === [] ? 0.0 : array_sum($allRewards);
        $oracleTotal = $bestMean * $totalSessions;

        return max(0.0, $oracleTotal - $actualTotal);
    }

    /**
     * @param array<string, array<int, float>> $personaRewards
     */
    private function computeRandomRegret(array $personaRewards, int $totalSessions): float
    {
        $allRewards = array_merge(...array_values($personaRewards));

        if ($allRewards === []) {
            return 0.0;
        }

        $globalMean = array_sum($allRewards) / count($allRewards);
        $bestMean = 0.0;

        foreach ($personaRewards as $rewards) {
            if (!empty($rewards)) {
                $bestMean = max($bestMean, array_sum($rewards) / count($rewards));
            }
        }

        return max(0.0, ($bestMean - $globalMean) * $totalSessions);
    }

    /**
     * Analyze cold start behavior (first 3 sessions per scam type).
     *
     * @param array<int, array<string, mixed>> $conversations
     *
     * @return array<string, mixed>
     */
    private function analyzeColdStart(array $conversations): array
    {
        $byScamType = [];

        foreach ($conversations as $row) {
            $byScamType[$row['scam_type']][] = $row;
        }

        $results = [];

        foreach ($byScamType as $scamType => $rows) {
            $first3 = array_slice($rows, 0, 3);
            $personas = array_map(static fn (array $r): string => \is_string($r['persona_code']) ? $r['persona_code'] : '', $first3);
            $uniquePersonas = count(array_unique($personas));
            $first3Count = count($first3);

            $results[$scamType] = [
                'total_sessions' => count($rows),
                'first_3_personas' => $personas,
                'exploration_ratio' => $uniquePersonas / $first3Count,
            ];
        }

        return $results;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation;

use App\Application\Evaluation\BanditAnalyzer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Mutation-killing tests for BanditAnalyzer.
 *
 * Targets specific Infection mutant patterns:
 * - Default minSessions=3 boundary
 * - totalRegret/totalRandomRegret initialization and accumulation
 * - convergenceRate division vs multiplication
 * - convergenceRate >= 0.50 threshold (boundary)
 * - round() precision values (2, 4, 3)
 * - RoundingFamily (round vs floor vs ceil)
 * - personaCounts initial value and increment
 * - reward filtering (null check, is_numeric, float cast)
 * - arsort removal
 * - dominantPct > 0.60 convergence threshold
 * - totalSessions >= 10 convergence minimum
 * - continue vs break in minSessions filter
 * - cold start analysis: exploration_ratio, first_3_personas
 * - computeRegret: bestMean, oracleTotal, max(0.0, ...)
 * - computeRandomRegret: globalMean, bestMean differences
 * - computeRewardStats: sort, mean, variance, stddev, quartiles
 */
final class BanditAnalyzerMutationTest extends TestCase
{
    private function createAnalyzer(array $rows): BanditAnalyzer
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        return new BanditAnalyzer($em);
    }

    private function makeRow(string $scamType, string $persona, ?float $reward, string $date = '2026-03-01 00:00:00'): array
    {
        static $counter = 0;
        ++$counter;

        return [
            'conv_id' => 'c-' . $counter,
            'scam_type' => $scamType,
            'persona_code' => $persona,
            'reward_value' => $reward,
            'status' => 'closed',
            'engagement_duration_sec' => 3600,
            'created_at' => $date,
        ];
    }

    // === Default minSessions=3 boundary ===

    public function test_default_min_sessions_is_3_exactly_2_skipped(): void
    {
        // 2 rows for PHISHING => skipped at default minSessions=3
        $rows = [
            $this->makeRow('PHISHING', 'p1', 0.5),
            $this->makeRow('PHISHING', 'p1', 0.6),
        ];

        $result = $this->createAnalyzer($rows)->analyze(); // default minSessions=3
        $this->assertSame(0, $result['active_scam_types'], 'With 2 rows, default minSessions=3 should skip');
        $this->assertEmpty($result['scam_type_analyses']);
    }

    public function test_default_min_sessions_is_3_exactly_3_included(): void
    {
        $rows = [
            $this->makeRow('PHISHING', 'p1', 0.5),
            $this->makeRow('PHISHING', 'p1', 0.6),
            $this->makeRow('PHISHING', 'p1', 0.7),
        ];

        $result = $this->createAnalyzer($rows)->analyze(); // default minSessions=3
        $this->assertSame(1, $result['active_scam_types'], 'With 3 rows, default minSessions=3 should include');
    }

    // === totalRegret initialization at 0.0 (not 1.0) ===

    public function test_total_regret_starts_at_zero(): void
    {
        // Single scam type, all same reward => regret = 0
        $rows = [];
        for ($i = 0; $i < 5; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p1', 0.5);
        }

        $result = $this->createAnalyzer($rows)->analyze();
        // When all rewards are the same and only one persona, regret should be 0
        $this->assertSame(0.0, $result['cumulative_regret'], 'Regret must start at 0.0, not 1.0');
    }

    public function test_total_random_regret_starts_at_zero(): void
    {
        $rows = [];
        for ($i = 0; $i < 5; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p1', 0.5);
        }

        $result = $this->createAnalyzer($rows)->analyze();
        $this->assertSame(0.0, $result['random_baseline_regret'], 'Random baseline regret must start at 0.0, not 1.0');
    }

    // === Regret accumulation across multiple scam types ===

    public function test_regret_accumulates_across_scam_types(): void
    {
        // Two scam types, each with different personas and different rewards
        // This catches += vs = and += vs -=
        $rows = [];
        for ($i = 0; $i < 5; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p1', 0.9);
            $rows[] = $this->makeRow('PHISHING', 'p2', 0.1);
        }
        for ($i = 0; $i < 5; ++$i) {
            $rows[] = $this->makeRow('ROMANCE', 'p1', 0.8);
            $rows[] = $this->makeRow('ROMANCE', 'p2', 0.2);
        }

        $result = $this->createAnalyzer($rows)->analyze();

        // Both types have regret > 0, accumulation means total > either alone
        $analyses = $result['scam_type_analyses'];
        $this->assertCount(2, $analyses);

        $regret1 = $analyses[0]['regret'];
        $regret2 = $analyses[1]['regret'];
        $this->assertGreaterThan(0.0, $regret1);
        $this->assertGreaterThan(0.0, $regret2);

        // Cumulative must be sum, not last-only (kills = vs +=)
        // Must be positive (kills -= vs +=)
        $this->assertGreaterThan($regret1, $result['cumulative_regret'], 'Cumulative regret must sum across types, not replace');
        $this->assertGreaterThan(0.0, $result['cumulative_regret']);
    }

    public function test_random_regret_accumulates_across_scam_types(): void
    {
        $rows = [];
        for ($i = 0; $i < 5; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p1', 0.9);
            $rows[] = $this->makeRow('PHISHING', 'p2', 0.1);
        }
        for ($i = 0; $i < 5; ++$i) {
            $rows[] = $this->makeRow('ROMANCE', 'p1', 0.8);
            $rows[] = $this->makeRow('ROMANCE', 'p2', 0.2);
        }

        $result = $this->createAnalyzer($rows)->analyze();
        $analyses = $result['scam_type_analyses'];
        $rr1 = $analyses[0]['random_regret'];
        $rr2 = $analyses[1]['random_regret'];

        $this->assertGreaterThan($rr1, $result['random_baseline_regret'], 'Random baseline must accumulate across types');
    }

    // === convergenceRate = convergedCount / activeCount (not *) ===

    public function test_convergence_rate_is_division_not_multiplication(): void
    {
        // 2 scam types, 1 converged, 1 not => rate = 0.5
        $rows = [];
        // PHISHING: 15 rows, 12 for p1 (80% > 60%, >= 10) => converged
        for ($i = 0; $i < 12; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p1', 0.8);
        }
        for ($i = 0; $i < 3; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p2', 0.3);
        }
        // ROMANCE: 10 rows evenly split => not converged
        for ($i = 0; $i < 2; ++$i) {
            $rows[] = $this->makeRow('ROMANCE', 'a', 0.5);
            $rows[] = $this->makeRow('ROMANCE', 'b', 0.5);
            $rows[] = $this->makeRow('ROMANCE', 'c', 0.5);
            $rows[] = $this->makeRow('ROMANCE', 'd', 0.5);
            $rows[] = $this->makeRow('ROMANCE', 'e', 0.5);
        }

        $result = $this->createAnalyzer($rows)->analyze();
        // convergedCount=1, activeCount=2 => rate=0.5
        // If multiplication: 1*2=2.0 => would be very different
        $this->assertSame(0.50, $result['convergence_rate']);
    }

    // === overall_convergence >= 0.50 boundary ===

    public function test_convergence_rate_exactly_0_50_is_converged(): void
    {
        // rate = 0.50 => >= 0.50 is TRUE (kills >= -> >)
        $rows = [];
        for ($i = 0; $i < 12; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p1', 0.8);
        }
        for ($i = 0; $i < 3; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p2', 0.3);
        }
        for ($i = 0; $i < 2; ++$i) {
            $rows[] = $this->makeRow('ROMANCE', 'a', 0.5);
            $rows[] = $this->makeRow('ROMANCE', 'b', 0.5);
            $rows[] = $this->makeRow('ROMANCE', 'c', 0.5);
            $rows[] = $this->makeRow('ROMANCE', 'd', 0.5);
            $rows[] = $this->makeRow('ROMANCE', 'e', 0.5);
        }

        $result = $this->createAnalyzer($rows)->analyze();
        $this->assertSame(0.50, $result['convergence_rate']);
        $this->assertTrue($result['overall_convergence'], 'Rate exactly 0.50 must satisfy >= 0.50');
    }

    // === round() precision for convergence_rate: 2 (not 1, 3) ===

    public function test_convergence_rate_rounded_to_2_decimals(): void
    {
        // 1 converged out of 3 => rate = 0.333... => round(0.333..., 2) = 0.33
        $rows = [];
        // 1 converged type
        for ($i = 0; $i < 12; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p1', 0.8);
        }
        for ($i = 0; $i < 3; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p2', 0.3);
        }
        // 2 non-converged types
        for ($i = 0; $i < 2; ++$i) {
            $rows[] = $this->makeRow('ROMANCE', 'a', 0.5);
            $rows[] = $this->makeRow('ROMANCE', 'b', 0.5);
            $rows[] = $this->makeRow('ROMANCE', 'c', 0.5);
        }
        for ($i = 0; $i < 2; ++$i) {
            $rows[] = $this->makeRow('LOTTERY', 'a', 0.5);
            $rows[] = $this->makeRow('LOTTERY', 'b', 0.5);
            $rows[] = $this->makeRow('LOTTERY', 'c', 0.5);
        }

        $result = $this->createAnalyzer($rows)->analyze();
        // rate = 1/3 = 0.333... round(0.333..., 2) = 0.33
        // round(0.333..., 1) = 0.3, round(0.333..., 3) = 0.333
        // floor(0.333...) = 0, ceil(0.333...) = 1
        $this->assertSame(0.33, $result['convergence_rate'], 'convergence_rate must be rounded to 2 decimal places');
    }

    // === cumulative_regret rounded to 4 decimals (not 3, 5, floor, ceil) ===

    public function test_cumulative_regret_rounded_to_4_decimals(): void
    {
        // Create data where regret has > 4 decimal places
        $rows = [];
        for ($i = 0; $i < 3; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p1', 0.9);
        }
        for ($i = 0; $i < 3; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p2', 0.1);
        }

        $result = $this->createAnalyzer($rows)->analyze();
        $regret = $result['cumulative_regret'];

        // Verify it's a float with at most 4 decimal digits
        $regretStr = (string) $regret;
        if (str_contains($regretStr, '.')) {
            $decimals = strlen(explode('.', $regretStr)[1]);
            $this->assertLessThanOrEqual(4, $decimals, 'cumulative_regret must have at most 4 decimal places');
        }

        // Verify it's NOT floor or ceil (those would be integers for small values)
        $this->assertNotSame(floor($regret), $regret === floor($regret) && $regret > 0 ? $regret : -1.0);
    }

    // === random_baseline_regret rounded to 4 decimals ===

    public function test_random_baseline_regret_rounded_to_4_decimals(): void
    {
        $rows = [];
        for ($i = 0; $i < 3; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p1', 0.9);
        }
        for ($i = 0; $i < 3; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p2', 0.1);
        }

        $result = $this->createAnalyzer($rows)->analyze();
        $rr = $result['random_baseline_regret'];

        $rrStr = (string) $rr;
        if (str_contains($rrStr, '.')) {
            $decimals = strlen(explode('.', $rrStr)[1]);
            $this->assertLessThanOrEqual(4, $decimals, 'random_baseline_regret must have at most 4 decimal places');
        }
    }

    // === personaCounts initial value 0 and increment by 1 ===

    public function test_persona_counts_correct_with_single_occurrence(): void
    {
        $rows = [
            $this->makeRow('PHISHING', 'p1', 0.5),
            $this->makeRow('PHISHING', 'p2', 0.6),
            $this->makeRow('PHISHING', 'p3', 0.7),
        ];

        $result = $this->createAnalyzer($rows)->analyze();
        $analyses = $result['scam_type_analyses'];
        $dist = $analyses[0]['persona_distribution'];

        // Each persona appears exactly once. Kills ?? -1 and ?? 1 and +2
        $this->assertSame(1, $dist['p1']);
        $this->assertSame(1, $dist['p2']);
        $this->assertSame(1, $dist['p3']);
    }

    public function test_persona_counts_correct_with_multiple_occurrences(): void
    {
        $rows = [
            $this->makeRow('PHISHING', 'p1', 0.5),
            $this->makeRow('PHISHING', 'p1', 0.6),
            $this->makeRow('PHISHING', 'p2', 0.7),
        ];

        $result = $this->createAnalyzer($rows)->analyze();
        $dist = $result['scam_type_analyses'][0]['persona_distribution'];

        // p1 appears 2 times. Kills +2 (would give 3), and ?? -1 (would give 0 then 1)
        $this->assertSame(2, $dist['p1']);
        $this->assertSame(1, $dist['p2']);
    }

    // === reward filtering: null excluded, numeric included, float cast ===

    public function test_null_rewards_excluded_from_stats(): void
    {
        $rows = [
            $this->makeRow('PHISHING', 'p1', null),
            $this->makeRow('PHISHING', 'p1', null),
            $this->makeRow('PHISHING', 'p1', 0.5),
        ];

        $result = $this->createAnalyzer($rows)->analyze();
        $stats = $result['scam_type_analyses'][0]['reward_stats'];

        // Only 1 reward value should be counted
        $this->assertSame(1, $stats['p1']['count']);
        $this->assertSame(0.5, $stats['p1']['mean']);
    }

    public function test_numeric_string_rewards_included(): void
    {
        $rows = [
            ['conv_id' => 'c-a', 'scam_type' => 'PHISHING', 'persona_code' => 'p1', 'reward_value' => '0.8', 'status' => 'closed', 'engagement_duration_sec' => 0, 'created_at' => '2026-03-01'],
            ['conv_id' => 'c-b', 'scam_type' => 'PHISHING', 'persona_code' => 'p1', 'reward_value' => '0.6', 'status' => 'closed', 'engagement_duration_sec' => 0, 'created_at' => '2026-03-01'],
            ['conv_id' => 'c-c', 'scam_type' => 'PHISHING', 'persona_code' => 'p1', 'reward_value' => 0.4, 'status' => 'closed', 'engagement_duration_sec' => 0, 'created_at' => '2026-03-01'],
        ];

        $result = $this->createAnalyzer($rows)->analyze();
        $stats = $result['scam_type_analyses'][0]['reward_stats'];

        // All 3 should be counted: is_numeric('0.8') is true
        $this->assertSame(3, $stats['p1']['count']);
        $this->assertSame(0.6, $stats['p1']['mean']);
    }

    // === arsort ensures dominant_persona is the most frequent ===

    public function test_dominant_persona_is_most_frequent(): void
    {
        $rows = [
            $this->makeRow('PHISHING', 'rare', 0.9),
            $this->makeRow('PHISHING', 'common', 0.5),
            $this->makeRow('PHISHING', 'common', 0.5),
            $this->makeRow('PHISHING', 'common', 0.5),
        ];

        $result = $this->createAnalyzer($rows)->analyze();
        // Without arsort, 'rare' could be first due to insertion order
        $this->assertSame('common', $result['scam_type_analyses'][0]['dominant_persona']);
    }

    // === dominantPct > 0.60 convergence threshold ===

    public function test_convergence_at_exactly_60_pct_is_not_converged(): void
    {
        // 6 out of 10 = 60% exactly. > 0.60 is false (kills > to >=)
        $rows = [];
        for ($i = 0; $i < 6; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p1', 0.8);
        }
        for ($i = 0; $i < 4; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p2', 0.3);
        }

        $result = $this->createAnalyzer($rows)->analyze();
        $this->assertFalse($result['scam_type_analyses'][0]['converged'], '60% exactly must NOT be converged (> 0.60 required)');
    }

    public function test_convergence_at_61_pct_is_converged(): void
    {
        // Need > 60% with >= 10 sessions
        // 7/11 = 63.6% > 60%
        $rows = [];
        for ($i = 0; $i < 7; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p1', 0.8);
        }
        for ($i = 0; $i < 4; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p2', 0.3);
        }

        $result = $this->createAnalyzer($rows)->analyze();
        $this->assertTrue($result['scam_type_analyses'][0]['converged']);
    }

    // === totalSessions >= 10 convergence minimum ===

    public function test_convergence_requires_at_least_10_sessions(): void
    {
        // 9 rows, all same persona (100% > 60%) but < 10 sessions
        $rows = [];
        for ($i = 0; $i < 9; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p1', 0.8);
        }

        $result = $this->createAnalyzer($rows)->analyze();
        $this->assertFalse($result['scam_type_analyses'][0]['converged'], '9 sessions (< 10) must NOT be converged');
    }

    public function test_convergence_at_exactly_10_sessions(): void
    {
        // 10 rows, 8 for p1 (80% > 60%), >= 10 sessions
        $rows = [];
        for ($i = 0; $i < 8; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p1', 0.8);
        }
        for ($i = 0; $i < 2; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p2', 0.3);
        }

        $result = $this->createAnalyzer($rows)->analyze();
        $this->assertTrue($result['scam_type_analyses'][0]['converged'], 'Exactly 10 sessions with 80% must be converged (>= 10)');
    }

    // === continue vs break in minSessions filter ===

    public function test_continue_not_break_in_min_sessions_filter(): void
    {
        // First scam type has 2 rows (skipped), second has 5 rows (included)
        // If break instead of continue, second type would also be skipped
        $rows = [
            $this->makeRow('AAA', 'p1', 0.5),
            $this->makeRow('AAA', 'p1', 0.5),
            $this->makeRow('ZZZ', 'p1', 0.5),
            $this->makeRow('ZZZ', 'p1', 0.5),
            $this->makeRow('ZZZ', 'p1', 0.5),
            $this->makeRow('ZZZ', 'p1', 0.5),
            $this->makeRow('ZZZ', 'p1', 0.5),
        ];

        $result = $this->createAnalyzer($rows)->analyze();
        // AAA has 2 (skipped), ZZZ has 5 (included)
        $this->assertSame(1, $result['active_scam_types'], 'Continue must skip AAA but still process ZZZ');
    }

    // === dominant_percentage rounded to 3 decimals ===

    public function test_dominant_percentage_rounded_to_3_decimals(): void
    {
        // 2 out of 3 = 0.6666... => round to 3 = 0.667
        $rows = [
            $this->makeRow('PHISHING', 'p1', 0.5),
            $this->makeRow('PHISHING', 'p1', 0.5),
            $this->makeRow('PHISHING', 'p2', 0.5),
        ];

        $result = $this->createAnalyzer($rows)->analyze();
        $pct = $result['scam_type_analyses'][0]['dominant_percentage'];
        // round(0.6666..., 3) = 0.667
        // round(0.6666..., 2) = 0.67
        // round(0.6666..., 4) = 0.6667
        $this->assertSame(0.667, $pct, 'dominant_percentage must be rounded to 3 decimal places');
    }

    // === cold start analysis ===

    public function test_cold_start_first_3_personas_captured(): void
    {
        $rows = [
            $this->makeRow('PHISHING', 'a', 0.5),
            $this->makeRow('PHISHING', 'b', 0.5),
            $this->makeRow('PHISHING', 'c', 0.5),
            $this->makeRow('PHISHING', 'd', 0.5),
        ];

        $result = $this->createAnalyzer($rows)->analyze();
        $cs = $result['cold_start_analysis']['PHISHING'];

        $this->assertCount(3, $cs['first_3_personas']);
        $this->assertSame(['a', 'b', 'c'], $cs['first_3_personas']);
    }

    public function test_cold_start_exploration_ratio(): void
    {
        $rows = [
            $this->makeRow('PHISHING', 'a', 0.5),
            $this->makeRow('PHISHING', 'a', 0.5),
            $this->makeRow('PHISHING', 'b', 0.5),
        ];

        $result = $this->createAnalyzer($rows)->analyze();
        $cs = $result['cold_start_analysis']['PHISHING'];

        // 2 unique personas out of 3 => ratio = 2/3
        $ratio = $cs['exploration_ratio'];
        $this->assertEqualsWithDelta(2.0 / 3.0, $ratio, 0.001);
    }

    public function test_cold_start_total_sessions_correct(): void
    {
        $rows = [];
        for ($i = 0; $i < 7; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p1', 0.5);
        }

        $result = $this->createAnalyzer($rows)->analyze();
        $this->assertSame(7, $result['cold_start_analysis']['PHISHING']['total_sessions']);
    }

    // === computeRewardStats ===

    public function test_reward_stats_mean_calculated_correctly(): void
    {
        $rows = [
            $this->makeRow('PHISHING', 'p1', 0.2),
            $this->makeRow('PHISHING', 'p1', 0.4),
            $this->makeRow('PHISHING', 'p1', 0.6),
        ];

        $result = $this->createAnalyzer($rows)->analyze();
        $stats = $result['scam_type_analyses'][0]['reward_stats']['p1'];

        $this->assertSame(3, $stats['count']);
        $this->assertSame(0.4, $stats['mean']);
    }

    public function test_reward_stats_stddev_with_single_value(): void
    {
        $rows = [
            $this->makeRow('PHISHING', 'p1', 0.5),
            $this->makeRow('PHISHING', 'p2', 0.3),
            $this->makeRow('PHISHING', 'p3', 0.7),
        ];

        $result = $this->createAnalyzer($rows)->analyze();
        // Each persona has 1 value => stddev = 0.0
        foreach ($result['scam_type_analyses'][0]['reward_stats'] as $stat) {
            $this->assertSame(0.0, $stat['stddev'], 'Single value stddev must be 0');
        }
    }

    public function test_reward_stats_stddev_with_multiple_values(): void
    {
        $rows = [
            $this->makeRow('PHISHING', 'p1', 0.2),
            $this->makeRow('PHISHING', 'p1', 0.8),
            $this->makeRow('PHISHING', 'p1', 0.5),
        ];

        $result = $this->createAnalyzer($rows)->analyze();
        $stats = $result['scam_type_analyses'][0]['reward_stats']['p1'];

        $this->assertGreaterThan(0.0, $stats['stddev'], 'Stddev with varied values must be > 0');
    }

    public function test_reward_stats_median_correct(): void
    {
        $rows = [
            $this->makeRow('PHISHING', 'p1', 0.1),
            $this->makeRow('PHISHING', 'p1', 0.5),
            $this->makeRow('PHISHING', 'p1', 0.9),
        ];

        $result = $this->createAnalyzer($rows)->analyze();
        $stats = $result['scam_type_analyses'][0]['reward_stats']['p1'];

        // median index = floor(3 * 0.50) = 1, sorted = [0.1, 0.5, 0.9], so median = 0.5
        $this->assertSame(0.5, $stats['median']);
    }

    public function test_reward_stats_quartiles_correct(): void
    {
        $rows = [];
        // 4 values: 0.2, 0.4, 0.6, 0.8
        $rows[] = $this->makeRow('PHISHING', 'p1', 0.2);
        $rows[] = $this->makeRow('PHISHING', 'p1', 0.4);
        $rows[] = $this->makeRow('PHISHING', 'p1', 0.6);
        $rows[] = $this->makeRow('PHISHING', 'p1', 0.8);

        $result = $this->createAnalyzer($rows)->analyze();
        $stats = $result['scam_type_analyses'][0]['reward_stats']['p1'];

        // q1 = rewards[floor(4*0.25)] = rewards[1] = 0.4
        $this->assertSame(0.4, $stats['q1']);
        // q3 = rewards[floor(4*0.75)] = rewards[3] = 0.8
        $this->assertSame(0.8, $stats['q3']);
    }

    // === computeRegret ===

    public function test_regret_zero_with_single_persona(): void
    {
        $rows = [];
        for ($i = 0; $i < 5; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p1', 0.5);
        }

        $result = $this->createAnalyzer($rows)->analyze();
        $this->assertSame(0.0, $result['scam_type_analyses'][0]['regret']);
    }

    public function test_regret_positive_with_suboptimal_choices(): void
    {
        $rows = [];
        // p1 has higher mean, but p2 has more sessions
        for ($i = 0; $i < 3; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p1', 0.9);
        }
        for ($i = 0; $i < 3; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p2', 0.1);
        }

        $result = $this->createAnalyzer($rows)->analyze();
        $this->assertGreaterThan(0.0, $result['scam_type_analyses'][0]['regret']);
    }

    // === generated_at present ===

    public function test_generated_at_present(): void
    {
        $result = $this->createAnalyzer([])->analyze();
        $this->assertArrayHasKey('generated_at', $result);
        $this->assertIsString($result['generated_at']);
        // DATE_ATOM format like 2026-03-01T00:00:00+00:00
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $result['generated_at']);
    }

    // === sessions_count correct ===

    public function test_sessions_count_equals_row_count(): void
    {
        $rows = [];
        for ($i = 0; $i < 7; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p1', 0.5);
        }

        $result = $this->createAnalyzer($rows)->analyze();
        $this->assertSame(7, $result['scam_type_analyses'][0]['sessions_count']);
    }

    // === scam_type field correct ===

    public function test_scam_type_field_matches_input(): void
    {
        $rows = [];
        for ($i = 0; $i < 3; ++$i) {
            $rows[] = $this->makeRow('PHISHING', 'p1', 0.5);
        }

        $result = $this->createAnalyzer($rows)->analyze();
        $this->assertSame('PHISHING', $result['scam_type_analyses'][0]['scam_type']);
    }

    // === Empty rewards => empty reward_stats ===

    public function test_all_null_rewards_produce_empty_stats(): void
    {
        $rows = [
            $this->makeRow('PHISHING', 'p1', null),
            $this->makeRow('PHISHING', 'p1', null),
            $this->makeRow('PHISHING', 'p1', null),
        ];

        $result = $this->createAnalyzer($rows)->analyze();
        $this->assertEmpty($result['scam_type_analyses'][0]['reward_stats']);
    }
}

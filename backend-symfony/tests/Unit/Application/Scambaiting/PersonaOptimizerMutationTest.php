<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Scambaiting;

use App\Application\Scambaiting\PersonaOptimizer;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Domain\Scambaiting\PersonaPerformance;
use App\Infrastructure\Doctrine\Repository\PersonaPerformanceStatsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Mutation-killing tests for PersonaOptimizer.
 *
 * Targets:
 * - EPSILON = 0.20 constant
 * - COLD_START_THRESHOLD = 3
 * - CONVERGENCE_THRESHOLD = 0.60
 * - MIN_SESSIONS_FOR_CONVERGENCE = 10
 * - CONVERGED_EPSILON = 0.05
 * - EXPLORATION_BONUS_C = 0.5
 * - getBanditConfig exact values
 * - selectPersona returns null when no scam type
 * - selectPersona returns null when no active personas
 * - Cold start detection (all personas < 3 sessions)
 * - selectBestPersona UCB1 ordering
 * - isConvergedFromPerformances logic
 */
final class PersonaOptimizerMutationTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PersonaPerformanceStatsRepository&MockObject $statsRepo;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->statsRepo = $this->createMock(PersonaPerformanceStatsRepository::class);
    }

    private function createOptimizer(): PersonaOptimizer
    {
        return new PersonaOptimizer(
            $this->statsRepo,
            $this->em,
            new NullLogger(),
        );
    }

    // === getBanditConfig exact values ===

    public function test_bandit_config_strategy(): void
    {
        $config = $this->createOptimizer()->getBanditConfig();
        $this->assertSame('epsilon-greedy', $config['strategy']);
    }

    public function test_bandit_config_epsilon(): void
    {
        $config = $this->createOptimizer()->getBanditConfig();
        $this->assertSame(0.20, $config['epsilon']);
    }

    public function test_bandit_config_cold_start_threshold(): void
    {
        $config = $this->createOptimizer()->getBanditConfig();
        $this->assertSame(3, $config['cold_start_threshold']);
    }

    public function test_bandit_config_convergence_threshold(): void
    {
        $config = $this->createOptimizer()->getBanditConfig();
        $this->assertSame(0.60, $config['convergence_threshold']);
    }

    public function test_bandit_config_min_sessions_for_convergence(): void
    {
        $config = $this->createOptimizer()->getBanditConfig();
        $this->assertSame(10, $config['min_sessions_for_convergence']);
    }

    public function test_bandit_config_converged_epsilon(): void
    {
        $config = $this->createOptimizer()->getBanditConfig();
        $this->assertSame(0.05, $config['converged_epsilon']);
    }

    public function test_bandit_config_reward_weights_duration(): void
    {
        $config = $this->createOptimizer()->getBanditConfig();
        $this->assertSame(0.40, $config['reward_weights']['duration']);
    }

    public function test_bandit_config_reward_weights_iocs_total(): void
    {
        $config = $this->createOptimizer()->getBanditConfig();
        $this->assertSame(0.25, $config['reward_weights']['iocs_total']);
    }

    public function test_bandit_config_reward_weights_iocs_sensibles(): void
    {
        $config = $this->createOptimizer()->getBanditConfig();
        $this->assertSame(0.25, $config['reward_weights']['iocs_sensibles']);
    }

    public function test_bandit_config_reward_weights_completion(): void
    {
        $config = $this->createOptimizer()->getBanditConfig();
        $this->assertSame(0.10, $config['reward_weights']['completion']);
    }

    public function test_bandit_config_reward_weights_sum_to_1(): void
    {
        $config = $this->createOptimizer()->getBanditConfig();
        $sum = array_sum($config['reward_weights']);
        $this->assertEqualsWithDelta(1.0, $sum, 0.001);
    }

    // === selectPersona: null when scam type not found ===

    public function test_select_persona_null_when_scam_type_not_found(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $this->em->method('getRepository')->willReturn($repo);

        $result = $this->createOptimizer()->selectPersona('NONEXISTENT');
        $this->assertNull($result);
    }

    public function test_select_persona_with_strategy_null_when_scam_type_not_found(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $this->em->method('getRepository')->willReturn($repo);

        $result = $this->createOptimizer()->selectPersonaWithStrategy('NONEXISTENT');
        $this->assertNull($result['persona_code']);
        $this->assertNull($result['strategy']);
    }

    // === selectPersona: null when no active personas ===

    public function test_select_persona_null_when_no_active_personas(): void
    {
        $scamType = $this->createMock(ScamType::class);
        $scamTypeRepo = $this->createMock(EntityRepository::class);
        $scamTypeRepo->method('findOneBy')->willReturn($scamType);

        $personaRepo = $this->createMock(EntityRepository::class);
        $personaRepo->method('findBy')->willReturn([]);

        $this->em->method('getRepository')->willReturnCallback(function (string $class) use ($scamTypeRepo, $personaRepo) {
            if ($class === ScamType::class) {
                return $scamTypeRepo;
            }
            return $personaRepo;
        });

        $result = $this->createOptimizer()->selectPersona('PHISHING');
        $this->assertNull($result);
    }

    // === Cold start detection ===

    public function test_all_cold_start_returns_cold_start_strategy(): void
    {
        $scamType = $this->createMock(ScamType::class);
        $scamTypeRepo = $this->createMock(EntityRepository::class);
        $scamTypeRepo->method('findOneBy')->willReturn($scamType);

        $persona1 = $this->createMock(Persona::class);
        $persona1->method('getPersonaCode')->willReturn('p1');
        $persona2 = $this->createMock(Persona::class);
        $persona2->method('getPersonaCode')->willReturn('p2');

        $personaRepo = $this->createMock(EntityRepository::class);
        $personaRepo->method('findBy')->willReturn([$persona1, $persona2]);

        $this->em->method('getRepository')->willReturnCallback(function (string $class) use ($scamTypeRepo, $personaRepo) {
            if ($class === ScamType::class) {
                return $scamTypeRepo;
            }
            return $personaRepo;
        });

        // No stats => all in cold start (0 sessions < 3)
        $this->statsRepo->method('findAllByScamType')->willReturn([]);

        $result = $this->createOptimizer()->selectPersonaWithStrategy('PHISHING');
        $this->assertNotNull($result['persona_code']);
        $this->assertSame('cold_start', $result['strategy']);
    }

    // === isConverged: returns false when scam type not found ===

    public function test_is_converged_false_when_scam_type_not_found(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $this->em->method('getRepository')->willReturn($repo);

        $result = $this->createOptimizer()->isConverged('NONEXISTENT');
        $this->assertFalse($result);
    }

    // === getSelectionStats: error when scam type not found ===

    public function test_selection_stats_error_when_scam_type_not_found(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $this->em->method('getRepository')->willReturn($repo);

        $result = $this->createOptimizer()->getSelectionStats('NONEXISTENT');
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('ScamType not found', $result['error']);
        $this->assertSame('NONEXISTENT', $result['scam_type_code']);
        $this->assertSame(0, $result['total_personas']);
        $this->assertSame(0, $result['cold_start_count']);
        $this->assertSame(0.20, $result['epsilon']);
        $this->assertSame(3, $result['cold_start_threshold']);
        $this->assertNull($result['best_persona']);
        $this->assertEmpty($result['top_5']);
    }

    // === PersonaPerformance cold start threshold ===

    public function test_persona_performance_cold_start_threshold_is_3(): void
    {
        // PersonaPerformance with 2 sessions should be in cold start
        $perf2 = new PersonaPerformance('p1', 'PHISHING', 2, 0.8);
        $this->assertTrue($perf2->isInColdStart(), '2 sessions must be cold start');

        // PersonaPerformance with 3 sessions should NOT be in cold start
        $perf3 = new PersonaPerformance('p1', 'PHISHING', 3, 0.8);
        $this->assertFalse($perf3->isInColdStart(), '3 sessions must NOT be cold start');
    }
}

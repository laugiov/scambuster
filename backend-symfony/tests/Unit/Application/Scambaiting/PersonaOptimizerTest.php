<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Scambaiting;

use App\Application\Scambaiting\PersonaOptimizer;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Domain\Scambaiting\PersonaPerformance;
use App\Infrastructure\Doctrine\Entity\PersonaPerformanceStatsEntity;
use App\Infrastructure\Doctrine\Repository\PersonaPerformanceStatsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PersonaOptimizerTest extends TestCase
{
    private PersonaPerformanceStatsRepository $statsRepository;
    private EntityManagerInterface $em;
    private LoggerInterface $logger;
    private PersonaOptimizer $optimizer;

    protected function setUp(): void
    {
        $this->statsRepository = $this->createMock(PersonaPerformanceStatsRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->optimizer = new PersonaOptimizer(
            $this->statsRepository,
            $this->em,
            $this->logger
        );
    }

    public function testSelectPersonaReturnsNullWhenNoActivePersonas(): void
    {
        // Arrange
        $scamType = $this->createMockScamType('PHISHING', []);

        $scamTypeRepo = $this->createMock(EntityRepository::class);
        $scamTypeRepo->method('findOneBy')->willReturn($scamType);

        $personaRepo = $this->createMock(EntityRepository::class);
        $personaRepo->method('findBy')->willReturn([]); // No active personas

        $this->em->method('getRepository')
            ->willReturnCallback(function ($entityClass) use ($scamTypeRepo, $personaRepo) {
                if ($entityClass === ScamType::class) {
                    return $scamTypeRepo;
                }
                if ($entityClass === Persona::class) {
                    return $personaRepo;
                }
                throw new \RuntimeException("Unexpected entity class: $entityClass");
            });

        // Act
        $result = $this->optimizer->selectPersona('PHISHING');

        // Assert
        $this->assertNull($result, 'Should return null when no active personas exist');
    }

    public function testSelectPersonaColdStartReturnsRandomPersona(): void
    {
        // Arrange: All personas have <3 sessions (cold start)
        $personas = [
            $this->createMockPersona('persona_1'),
            $this->createMockPersona('persona_2'),
            $this->createMockPersona('persona_3'),
        ];

        $scamType = $this->createMockScamType('PHISHING', $personas);

        $scamTypeRepo = $this->createMock(EntityRepository::class);
        $scamTypeRepo->method('findOneBy')->willReturn($scamType);

        $personaRepo = $this->createMock(EntityRepository::class);
        $personaRepo->method('findBy')->willReturn($personas);

        $this->em->method('getRepository')
            ->willReturnCallback(function ($entityClass) use ($scamTypeRepo, $personaRepo) {
                if ($entityClass === ScamType::class) {
                    return $scamTypeRepo;
                }
                if ($entityClass === Persona::class) {
                    return $personaRepo;
                }
                throw new \RuntimeException("Unexpected entity class: $entityClass");
            });

        // All personas in cold start (< 3 sessions)
        $stats = [
            $this->createMockStats($personas[0], $scamType, 0, 0.0, 0.0),
            $this->createMockStats($personas[1], $scamType, 1, 0.5, 0.5),
            $this->createMockStats($personas[2], $scamType, 2, 0.8, 0.4),
        ];

        $this->statsRepository->method('findAllByScamType')
            ->willReturn($stats);

        // Act: Run multiple times to verify randomness
        $results = [];
        for ($i = 0; $i < 30; $i++) {
            $selectedPersonaCode = $this->optimizer->selectPersona('PHISHING');
            $this->assertNotNull($selectedPersonaCode);
            $results[] = $selectedPersonaCode;
        }

        // Assert: At least 2 different personas selected (proves pure exploration)
        $uniquePersonas = array_unique($results);
        $this->assertGreaterThanOrEqual(2, count($uniquePersonas),
            'Cold start should use uniform random selection across multiple runs'
        );
    }

    public function testSelectPersonaExploitationSelectsBestPerformer(): void
    {
        // Arrange: Mix of cold start and trained personas
        $personas = [
            $this->createMockPersona('persona_weak'),    // 0.3 avg
            $this->createMockPersona('persona_strong'),  // 0.9 avg (best)
            $this->createMockPersona('persona_medium'),  // 0.6 avg
        ];

        $scamType = $this->createMockScamType('PHISHING', $personas);

        $scamTypeRepo = $this->createMock(EntityRepository::class);
        $scamTypeRepo->method('findOneBy')->willReturn($scamType);

        $personaRepo = $this->createMock(EntityRepository::class);
        $personaRepo->method('findBy')->willReturn($personas);

        $this->em->method('getRepository')
            ->willReturnCallback(function ($entityClass) use ($scamTypeRepo, $personaRepo) {
                if ($entityClass === ScamType::class) {
                    return $scamTypeRepo;
                }
                if ($entityClass === Persona::class) {
                    return $personaRepo;
                }
                throw new \RuntimeException("Unexpected entity class: $entityClass");
            });

        // Stats with enough sessions to exit cold start
        $stats = [
            $this->createMockStats($personas[0], $scamType, 10, 3.0, 0.3),
            $this->createMockStats($personas[1], $scamType, 15, 13.5, 0.9),  // Best performer
            $this->createMockStats($personas[2], $scamType, 8, 4.8, 0.6),
        ];

        $this->statsRepository->method('findAllByScamType')
            ->willReturn($stats);

        // Act: Run multiple times (80% should be exploitation = persona_strong)
        $results = [];
        for ($i = 0; $i < 100; $i++) {
            $selectedPersonaCode = $this->optimizer->selectPersona('PHISHING');
            $results[] = $selectedPersonaCode;
        }

        // Assert: persona_strong should be selected most often (>60% due to ε=0.20)
        $strongCount = count(array_filter($results, fn($code) => $code === 'persona_strong'));
        $strongRatio = $strongCount / 100;

        $this->assertGreaterThan(0.6, $strongRatio,
            'Best performer should be selected >60% of the time (ε=0.20 means 80% exploitation)'
        );
    }

    public function testSelectPersonaHandlesExAequoWithRandomSelection(): void
    {
        // Arrange: Two personas with identical reward_avg
        $personas = [
            $this->createMockPersona('persona_a'),
            $this->createMockPersona('persona_b'),
        ];

        $scamType = $this->createMockScamType('PHISHING', $personas);

        $scamTypeRepo = $this->createMock(EntityRepository::class);
        $scamTypeRepo->method('findOneBy')->willReturn($scamType);

        $personaRepo = $this->createMock(EntityRepository::class);
        $personaRepo->method('findBy')->willReturn($personas);

        $this->em->method('getRepository')
            ->willReturnCallback(function ($entityClass) use ($scamTypeRepo, $personaRepo) {
                if ($entityClass === ScamType::class) {
                    return $scamTypeRepo;
                }
                if ($entityClass === Persona::class) {
                    return $personaRepo;
                }
                throw new \RuntimeException("Unexpected entity class: $entityClass");
            });

        // Both personas have same reward_avg AND same sessions (true ex-aequo)
        $stats = [
            $this->createMockStats($personas[0], $scamType, 10, 7.5, 0.75),
            $this->createMockStats($personas[1], $scamType, 10, 7.5, 0.75),
        ];

        $this->statsRepository->method('findAllByScamType')
            ->willReturn($stats);

        // Act: Run 200 times to ensure random tiebreaker selects both
        $results = [];
        for ($i = 0; $i < 200; $i++) {
            $selectedPersonaCode = $this->optimizer->selectPersona('PHISHING');
            if ($selectedPersonaCode !== null) {
                $results[] = $selectedPersonaCode;
            }
        }

        // Assert: Both personas should appear in results (ex-aequo random tiebreaker)
        $uniquePersonas = array_unique($results);
        $this->assertGreaterThanOrEqual(2, count($uniquePersonas),
            'Ex-aequo personas should both be selected via random tiebreaker'
        );
    }

    public function testGetSelectionStatsReturnsCorrectMetrics(): void
    {
        // Arrange
        $personas = [
            $this->createMockPersona('persona_1'),
            $this->createMockPersona('persona_2'),
            $this->createMockPersona('persona_3'),
        ];

        $scamType = $this->createMockScamType('PHISHING', $personas);

        $scamTypeRepo = $this->createMock(EntityRepository::class);
        $scamTypeRepo->method('findOneBy')->willReturn($scamType);

        $personaRepo = $this->createMock(EntityRepository::class);
        $personaRepo->method('findBy')->willReturn($personas);

        $this->em->method('getRepository')
            ->willReturnCallback(function ($entityClass) use ($scamTypeRepo, $personaRepo) {
                if ($entityClass === ScamType::class) {
                    return $scamTypeRepo;
                }
                if ($entityClass === Persona::class) {
                    return $personaRepo;
                }
                throw new \RuntimeException("Unexpected entity class: $entityClass");
            });

        $stats = [
            $this->createMockStats($personas[0], $scamType, 2, 0.8, 0.4),   // cold start
            $this->createMockStats($personas[1], $scamType, 10, 9.0, 0.9),  // best
            $this->createMockStats($personas[2], $scamType, 5, 3.0, 0.6),
        ];

        $this->statsRepository->method('findAllByScamType')
            ->willReturn($stats);

        // Mock methods used by getSelectionStats
        $this->statsRepository->method('countColdStartPersonas')
            ->willReturn(1); // persona_1 has 2 sessions < 3

        $this->statsRepository->method('findBestPerformingPersona')
            ->willReturn($stats[1]); // persona_2 with reward_avg=0.9

        $this->statsRepository->method('findTopPerformingPersonas')
            ->willReturn($stats); // All 3 personas

        // Act
        $result = $this->optimizer->getSelectionStats('PHISHING');

        // Assert
        $this->assertIsArray($result);
        $this->assertSame('PHISHING', $result['scam_type_code']);
        $this->assertSame(3, $result['total_personas']);
        $this->assertSame(1, $result['cold_start_count']);
        // epsilon depends on convergence: persona_2 has 10/15 eligible sessions (66% > 60%)
        // so this scam type IS converged, epsilon should be 0.05
        $this->assertSame(0.05, $result['epsilon']);
        $this->assertTrue($result['converged']);
        $this->assertSame(0.60, $result['convergence_threshold']);
        $this->assertSame(3, $result['cold_start_threshold']);

        // best_persona is an array with persona details
        $this->assertIsArray($result['best_persona']);
        $this->assertSame('persona_2', $result['best_persona']['persona_code']);
        $this->assertSame(0.9, $result['best_persona']['reward_avg']);
        $this->assertSame(10, $result['best_persona']['sessions_count']);

        $this->assertCount(3, $result['top_5']);
    }

    public function testExplorationBonusDoesNotOverrideClearWinner(): void
    {
        // A clearly dominant persona (0.9 reward, 15 sessions) should still win
        // even though a weaker persona (0.3 reward, 3 sessions) gets a UCB1 bonus
        $personas = [
            $this->createMockPersona('persona_strong'),
            $this->createMockPersona('persona_weak'),
        ];

        $scamType = $this->createMockScamType('PHISHING', $personas);

        $scamTypeRepo = $this->createMock(EntityRepository::class);
        $scamTypeRepo->method('findOneBy')->willReturn($scamType);

        $personaRepo = $this->createMock(EntityRepository::class);
        $personaRepo->method('findBy')->willReturn($personas);

        $this->em->method('getRepository')
            ->willReturnCallback(function ($entityClass) use ($scamTypeRepo, $personaRepo) {
                if ($entityClass === ScamType::class) {
                    return $scamTypeRepo;
                }
                if ($entityClass === Persona::class) {
                    return $personaRepo;
                }
                throw new \RuntimeException("Unexpected entity class: $entityClass");
            });

        $stats = [
            $this->createMockStats($personas[0], $scamType, 15, 13.5, 0.9),
            $this->createMockStats($personas[1], $scamType, 3, 0.9, 0.3),
        ];

        $this->statsRepository->method('findAllByScamType')->willReturn($stats);

        // Run 100 selections: strong persona should still dominate
        $results = [];
        for ($i = 0; $i < 100; $i++) {
            $results[] = $this->optimizer->selectPersona('PHISHING');
        }

        $strongCount = count(array_filter($results, fn($code) => $code === 'persona_strong'));
        $this->assertGreaterThan(55, $strongCount,
            'UCB1 bonus should not override a clearly dominant persona (0.9 vs 0.3 reward)'
        );
    }

    public function testIsConvergedReturnsFalseWhenAllColdStart(): void
    {
        $personas = [
            $this->createMockPersona('persona_1'),
            $this->createMockPersona('persona_2'),
        ];

        $scamType = $this->createMockScamType('PHISHING', $personas);

        $scamTypeRepo = $this->createMock(EntityRepository::class);
        $scamTypeRepo->method('findOneBy')->willReturn($scamType);

        $personaRepo = $this->createMock(EntityRepository::class);
        $personaRepo->method('findBy')->willReturn($personas);

        $this->em->method('getRepository')
            ->willReturnCallback(function ($entityClass) use ($scamTypeRepo, $personaRepo) {
                if ($entityClass === ScamType::class) {
                    return $scamTypeRepo;
                }
                if ($entityClass === Persona::class) {
                    return $personaRepo;
                }
                throw new \RuntimeException("Unexpected entity class: $entityClass");
            });

        $stats = [
            $this->createMockStats($personas[0], $scamType, 1, 0.5, 0.5),
            $this->createMockStats($personas[1], $scamType, 2, 0.8, 0.4),
        ];

        $this->statsRepository->method('findAllByScamType')->willReturn($stats);

        $this->assertFalse($this->optimizer->isConverged('PHISHING'));
    }

    public function testIsConvergedReturnsFalseWithInsufficientSessions(): void
    {
        $personas = [
            $this->createMockPersona('persona_1'),
            $this->createMockPersona('persona_2'),
        ];

        $scamType = $this->createMockScamType('PHISHING', $personas);

        $scamTypeRepo = $this->createMock(EntityRepository::class);
        $scamTypeRepo->method('findOneBy')->willReturn($scamType);

        $personaRepo = $this->createMock(EntityRepository::class);
        $personaRepo->method('findBy')->willReturn($personas);

        $this->em->method('getRepository')
            ->willReturnCallback(function ($entityClass) use ($scamTypeRepo, $personaRepo) {
                if ($entityClass === ScamType::class) {
                    return $scamTypeRepo;
                }
                if ($entityClass === Persona::class) {
                    return $personaRepo;
                }
                throw new \RuntimeException("Unexpected entity class: $entityClass");
            });

        // Both have enough for cold start exit but best has < 10 sessions
        $stats = [
            $this->createMockStats($personas[0], $scamType, 5, 4.5, 0.9),
            $this->createMockStats($personas[1], $scamType, 4, 1.6, 0.4),
        ];

        $this->statsRepository->method('findAllByScamType')->willReturn($stats);

        $this->assertFalse($this->optimizer->isConverged('PHISHING'));
    }

    public function testIsConvergedReturnsTrueWhenDominantPersonaExists(): void
    {
        $personas = [
            $this->createMockPersona('persona_dominant'),
            $this->createMockPersona('persona_weak'),
        ];

        $scamType = $this->createMockScamType('PHISHING', $personas);

        $scamTypeRepo = $this->createMock(EntityRepository::class);
        $scamTypeRepo->method('findOneBy')->willReturn($scamType);

        $personaRepo = $this->createMock(EntityRepository::class);
        $personaRepo->method('findBy')->willReturn($personas);

        $this->em->method('getRepository')
            ->willReturnCallback(function ($entityClass) use ($scamTypeRepo, $personaRepo) {
                if ($entityClass === ScamType::class) {
                    return $scamTypeRepo;
                }
                if ($entityClass === Persona::class) {
                    return $personaRepo;
                }
                throw new \RuntimeException("Unexpected entity class: $entityClass");
            });

        // Dominant persona: 15 sessions out of 20 total = 75% > 60% threshold
        $stats = [
            $this->createMockStats($personas[0], $scamType, 15, 13.5, 0.9),
            $this->createMockStats($personas[1], $scamType, 5, 1.5, 0.3),
        ];

        $this->statsRepository->method('findAllByScamType')->willReturn($stats);

        $this->assertTrue($this->optimizer->isConverged('PHISHING'));
    }

    public function testConvergedReducesEpsilon(): void
    {
        $personas = [
            $this->createMockPersona('persona_dominant'),
            $this->createMockPersona('persona_weak'),
        ];

        $scamType = $this->createMockScamType('PHISHING', $personas);

        $scamTypeRepo = $this->createMock(EntityRepository::class);
        $scamTypeRepo->method('findOneBy')->willReturn($scamType);

        $personaRepo = $this->createMock(EntityRepository::class);
        $personaRepo->method('findBy')->willReturn($personas);

        $this->em->method('getRepository')
            ->willReturnCallback(function ($entityClass) use ($scamTypeRepo, $personaRepo) {
                if ($entityClass === ScamType::class) {
                    return $scamTypeRepo;
                }
                if ($entityClass === Persona::class) {
                    return $personaRepo;
                }
                throw new \RuntimeException("Unexpected entity class: $entityClass");
            });

        // Converged: dominant has 80% of sessions
        $stats = [
            $this->createMockStats($personas[0], $scamType, 40, 36.0, 0.9),
            $this->createMockStats($personas[1], $scamType, 10, 3.0, 0.3),
        ];

        $this->statsRepository->method('findAllByScamType')->willReturn($stats);
        $this->statsRepository->method('countColdStartPersonas')->willReturn(0);
        $this->statsRepository->method('findBestPerformingPersona')->willReturn($stats[0]);
        $this->statsRepository->method('findTopPerformingPersonas')->willReturn($stats);

        // Verify convergence detected
        $this->assertTrue($this->optimizer->isConverged('PHISHING'));

        // Run selections: with converged epsilon=0.05, dominant should be selected >90%
        $results = [];
        for ($i = 0; $i < 200; $i++) {
            $results[] = $this->optimizer->selectPersona('PHISHING');
        }

        $dominantCount = count(array_filter($results, fn($code) => $code === 'persona_dominant'));
        $dominantRatio = $dominantCount / 200;

        $this->assertGreaterThan(0.85, $dominantRatio,
            'Converged category should select dominant persona >85% of the time (ε=0.05)'
        );

        // Verify stats report convergence
        $selectionStats = $this->optimizer->getSelectionStats('PHISHING');
        $this->assertTrue($selectionStats['converged']);
        $this->assertSame(0.60, $selectionStats['convergence_threshold']);
        $this->assertSame(0.05, $selectionStats['epsilon']);
    }

    private function createMockScamType(string $code, array $personas): ScamType
    {
        $scamType = $this->createMock(ScamType::class);
        $scamType->method('getCode')->willReturn($code);
        $scamType->method('getPersonas')->willReturn(new ArrayCollection($personas));
        return $scamType;
    }

    private function createMockPersona(string $code): Persona
    {
        $persona = $this->createMock(Persona::class);
        $persona->method('getPersonaCode')->willReturn($code);
        $persona->method('isActive')->willReturn(true);
        return $persona;
    }

    private function createMockStats(
        Persona $persona,
        ScamType $scamType,
        int $sessionsCount,
        float $rewardSum,
        float $rewardAvg
    ): PersonaPerformanceStatsEntity {
        $stats = $this->createMock(PersonaPerformanceStatsEntity::class);
        $stats->method('getPersona')->willReturn($persona);
        $stats->method('getScamType')->willReturn($scamType);
        $stats->method('getSessionsCount')->willReturn($sessionsCount);
        $stats->method('getRewardSum')->willReturn($rewardSum);
        $stats->method('getRewardAvg')->willReturn($rewardAvg);

        // Mock toPersonaPerformance() to return a real PersonaPerformance instance
        $performance = new PersonaPerformance(
            personaCode: $persona->getPersonaCode(),
            scamTypeCode: $scamType->getCode(),
            sessionsCount: $sessionsCount,
            rewardAvg: $rewardAvg
        );
        $stats->method('toPersonaPerformance')->willReturn($performance);

        return $stats;
    }
}

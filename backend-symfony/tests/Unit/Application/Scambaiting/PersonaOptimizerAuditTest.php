<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Scambaiting;

use App\Application\Audit\AuditLogger;
use App\Application\Scambaiting\PersonaOptimizer;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditLog;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Domain\Scambaiting\PersonaPerformance;
use App\Infrastructure\Doctrine\Entity\PersonaPerformanceStatsEntity;
use App\Infrastructure\Doctrine\Repository\PersonaPerformanceStatsRepository;
use App\Infrastructure\Siem\Adapter\NullSiemExporter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Spec 095 Fix #14 — verify PersonaOptimizer emits BANDIT_DECISION audit
 * row on every selectPersonaWithStrategy call, carrying the full decision
 * context (selected persona + ALL candidates + UCB1 scores + random_value
 * + epsilon).
 *
 * Uses a real AuditLogger with a mocked EntityManager as spy (canonical
 * pattern, see BudgetThresholdNotifierTest / RetryCoordinatorAuditTest).
 */
final class PersonaOptimizerAuditTest extends TestCase
{
    private PersonaPerformanceStatsRepository&MockObject $statsRepository;
    private EntityManagerInterface&MockObject $em;
    private NullLogger $logger;

    /** @var list<AuditLog> Captured audit_log entities emitted during the test */
    private array $emittedAuditLogs = [];

    protected function setUp(): void
    {
        $this->statsRepository = $this->createMock(PersonaPerformanceStatsRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->logger = new NullLogger();
        $this->emittedAuditLogs = [];
    }

    private function createAuditLoggerSpy(): AuditLogger
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($entity): void {
            if ($entity instanceof AuditLog) {
                $this->emittedAuditLogs[] = $entity;
            }
        });

        return new AuditLogger($em, new NullLogger(), new RequestStack(), new NullSiemExporter());
    }

    private function createOptimizer(?AuditLogger $auditLogger = null): PersonaOptimizer
    {
        return new PersonaOptimizer(
            $this->statsRepository,
            $this->em,
            $this->logger,
            $auditLogger,
        );
    }

    /**
     * @return list<AuditLog>
     */
    private function auditLogsOfType(AuditEventType $type): array
    {
        return array_values(array_filter(
            $this->emittedAuditLogs,
            static fn (AuditLog $log): bool => $log->getEventType() === $type->value,
        ));
    }

    private function wireRepositories(array $personas, ScamType $scamType): void
    {
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

    private function createMockStats(Persona $persona, ScamType $scamType, int $sessionsCount, float $rewardAvg): PersonaPerformanceStatsEntity
    {
        $stats = $this->createMock(PersonaPerformanceStatsEntity::class);
        $stats->method('getPersona')->willReturn($persona);
        $stats->method('getScamType')->willReturn($scamType);
        $stats->method('getSessionsCount')->willReturn($sessionsCount);
        $stats->method('getRewardAvg')->willReturn($rewardAvg);

        $performance = new PersonaPerformance(
            personaCode: $persona->getPersonaCode(),
            scamTypeCode: $scamType->getCode(),
            sessionsCount: $sessionsCount,
            rewardAvg: $rewardAvg,
        );
        $stats->method('toPersonaPerformance')->willReturn($performance);

        return $stats;
    }

    // ====================================================================
    // BANDIT_DECISION on each strategy branch
    // ====================================================================

    public function testBanditDecisionEmittedOnColdStartBranch_Fix14(): void
    {
        // All 3 personas with <3 sessions → cold_start strategy
        $personas = [
            $this->createMockPersona('persona_alpha'),
            $this->createMockPersona('persona_beta'),
            $this->createMockPersona('persona_gamma'),
        ];
        $scamType = $this->createMockScamType('PHISHING', $personas);
        $this->wireRepositories($personas, $scamType);

        $stats = [
            $this->createMockStats($personas[0], $scamType, 0, 0.0),
            $this->createMockStats($personas[1], $scamType, 1, 0.5),
            $this->createMockStats($personas[2], $scamType, 2, 0.8),
        ];
        $this->statsRepository->method('findAllByScamType')->willReturn($stats);

        $audit = $this->createAuditLoggerSpy();
        $this->createOptimizer($audit)->selectPersona('PHISHING');

        $events = $this->auditLogsOfType(AuditEventType::BANDIT_DECISION);
        $this->assertCount(1, $events, 'Cold start branch must emit exactly 1 BANDIT_DECISION row');

        $details = $events[0]->getDetails();
        $this->assertSame('cold_start', $details['strategy']);
        $this->assertNull($details['random_value'], 'random_value must be null in cold_start (no RNG roll happens)');
        $this->assertSame('PHISHING', $details['scam_type_code']);
        $this->assertIsArray($details['candidates']);
        $this->assertCount(3, $details['candidates']);

        // Exactly 1 candidate has was_selected=true
        $selectedCount = count(array_filter($details['candidates'], static fn (array $c): bool => $c['was_selected'] === true));
        $this->assertSame(1, $selectedCount);
    }

    public function testBanditDecisionEmittedOnExploitationBranch_Fix14(): void
    {
        // Dominant persona scenario: persona_strong always wins exploitation
        $personas = [
            $this->createMockPersona('persona_strong'),
            $this->createMockPersona('persona_weak'),
            $this->createMockPersona('persona_medium'),
        ];
        $scamType = $this->createMockScamType('PHISHING', $personas);
        $this->wireRepositories($personas, $scamType);

        $stats = [
            $this->createMockStats($personas[0], $scamType, 30, 0.92),  // dominant
            $this->createMockStats($personas[1], $scamType, 10, 0.20),
            $this->createMockStats($personas[2], $scamType, 10, 0.40),
        ];
        $this->statsRepository->method('findAllByScamType')->willReturn($stats);

        // Run many iterations to ensure ≥1 exploitation branch fires (80 % of the time)
        $audit = $this->createAuditLoggerSpy();
        $optimizer = $this->createOptimizer($audit);
        for ($i = 0; $i < 30; $i++) {
            $optimizer->selectPersona('PHISHING');
        }

        $events = $this->auditLogsOfType(AuditEventType::BANDIT_DECISION);
        $exploitationEvents = array_values(array_filter(
            $events,
            static fn (AuditLog $log): bool => ($log->getDetails()['strategy'] ?? null) === 'exploitation',
        ));
        $this->assertGreaterThan(0, count($exploitationEvents), 'Expected ≥1 exploitation emission across 30 iterations');

        $details = $exploitationEvents[0]->getDetails();
        $this->assertIsFloat($details['random_value']);
        $this->assertGreaterThanOrEqual($details['epsilon'], $details['random_value'], 'Exploitation branch fires when random_value >= epsilon');
        $this->assertNotNull($details['selected']['ucb1_score'], 'Exploitation must surface the UCB1 score');
        // Epsilon is 0.20 normally, or 0.05 when the bandit has converged on
        // the dominant persona (≥60 % of selections + ≥10 sessions). Both
        // values are valid exploitation contexts.
        $this->assertContains($details['epsilon'], [0.20, 0.05]);
        $this->assertIsArray($details['candidates']);

        // Validate selected candidate is also in the candidates array with was_selected=true
        $selectedCandidate = array_values(array_filter(
            $details['candidates'],
            static fn (array $c): bool => $c['was_selected'] === true,
        ));
        $this->assertCount(1, $selectedCandidate);
        $this->assertSame($details['selected']['persona_code'], $selectedCandidate[0]['persona_code']);
    }

    public function testBanditDecisionEmittedOnExplorationBranch_Fix14(): void
    {
        // Keep both personas BELOW MIN_SESSIONS_FOR_CONVERGENCE (=10) so the
        // bandit stays at epsilon=0.20 (un-converged) — otherwise epsilon
        // drops to 0.05 and the test becomes flaky at low iteration counts.
        $personas = [
            $this->createMockPersona('persona_strong'),
            $this->createMockPersona('persona_weak'),
        ];
        $scamType = $this->createMockScamType('PHISHING', $personas);
        $this->wireRepositories($personas, $scamType);

        $stats = [
            $this->createMockStats($personas[0], $scamType, 8, 0.92),  // <10 sessions → no convergence
            $this->createMockStats($personas[1], $scamType, 5, 0.20),
        ];
        $this->statsRepository->method('findAllByScamType')->willReturn($stats);

        $audit = $this->createAuditLoggerSpy();
        $optimizer = $this->createOptimizer($audit);
        // 200 iters × 0.20 = ~40 expected exploration events. P(0) = 0.80^200
        // ≈ 4 × 10⁻²⁰ → statistically impossible.
        for ($i = 0; $i < 200; $i++) {
            $optimizer->selectPersona('PHISHING');
        }

        $events = $this->auditLogsOfType(AuditEventType::BANDIT_DECISION);
        $explorationEvents = array_values(array_filter(
            $events,
            static fn (AuditLog $log): bool => ($log->getDetails()['strategy'] ?? null) === 'exploration',
        ));
        $this->assertGreaterThan(0, count($explorationEvents), 'Expected ≥1 exploration emission across 200 iterations (eps=0.20, non-converged)');

        $details = $explorationEvents[0]->getDetails();
        $this->assertIsFloat($details['random_value']);
        $this->assertLessThan(0.20, $details['random_value'], 'Exploration branch only fires when random_value < epsilon');
        $this->assertSame(0.20, $details['epsilon']);
        $this->assertFalse($details['converged']);
        $this->assertIsArray($details['candidates']);
    }

    // ====================================================================
    // Backward compat — PERSONA_SELECTED still fires alongside
    // ====================================================================

    public function testBanditDecisionAndPersonaSelectedBothFire_Fix14(): void
    {
        $personas = [$this->createMockPersona('persona_solo')];
        $scamType = $this->createMockScamType('PHISHING', $personas);
        $this->wireRepositories($personas, $scamType);

        $this->statsRepository->method('findAllByScamType')->willReturn([
            $this->createMockStats($personas[0], $scamType, 15, 0.75),
        ]);

        $audit = $this->createAuditLoggerSpy();
        $this->createOptimizer($audit)->selectPersona('PHISHING');

        $personaSelected = $this->auditLogsOfType(AuditEventType::PERSONA_SELECTED);
        $banditDecision = $this->auditLogsOfType(AuditEventType::BANDIT_DECISION);

        $this->assertCount(1, $personaSelected, 'Existing PERSONA_SELECTED must still fire (backward compat)');
        $this->assertCount(1, $banditDecision, 'New BANDIT_DECISION must also fire (Fix #14)');

        // Both events agree on the selected persona
        $this->assertSame(
            $personaSelected[0]->getResourceId(),
            $banditDecision[0]->getDetails()['selected']['persona_code'],
        );
    }
}

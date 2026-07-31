<?php

declare(strict_types=1);

namespace App\Application\Scambaiting;

use App\Application\Audit\AuditLogger;
use App\Domain\Communication\ConversationRepositoryInterface;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Domain\Scambaiting\PersonaPerformance;
use App\Infrastructure\Doctrine\Repository\PersonaPerformanceStatsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Business service for optimized persona selection.
 * Implements a contextual epsilon-greedy algorithm (1 bandit per scam_type).
 *
 * Algorithme :
 * 1. If ALL personas are in cold start (<3 sessions) -> Uniform random selection
 * 2. Otherwise with probability epsilon=0.20 -> Exploration (random selection)
 * 3. Otherwise with probability 1-epsilon=0.80 -> Exploitation (best reward_avg)
 */
final readonly class PersonaOptimizer
{
    private const EPSILON = 0.20;
    private const COLD_START_THRESHOLD = 3;
    private const CONVERGENCE_THRESHOLD = 0.60;
    private const MIN_SESSIONS_FOR_CONVERGENCE = 10;
    private const CONVERGED_EPSILON = 0.05;
    private const EXPLORATION_BONUS_C = 0.5;

    public function __construct(
        private PersonaPerformanceStatsRepository $statsRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private ?AuditLogger $auditLogger = null,
        private ?ConversationRepositoryInterface $convRepository = null,
    ) {
    }

    /**
     * @return array{strategy: string, epsilon: float, cold_start_threshold: int, convergence_threshold: float, min_sessions_for_convergence: int, converged_epsilon: float, reward_weights: array<string, float>}
     */
    public function getBanditConfig(): array
    {
        return [
            'strategy' => 'epsilon-greedy',
            'epsilon' => self::EPSILON,
            'cold_start_threshold' => self::COLD_START_THRESHOLD,
            'convergence_threshold' => self::CONVERGENCE_THRESHOLD,
            'min_sessions_for_convergence' => self::MIN_SESSIONS_FOR_CONVERGENCE,
            'converged_epsilon' => self::CONVERGED_EPSILON,
            'reward_weights' => [
                'duration' => 0.40,
                'iocs_total' => 0.25,
                'iocs_sensibles' => 0.25,
                'completion' => 0.10,
            ],
        ];
    }

    /**
     * Selects the optimal persona for a given scam_type.
     * Returns the persona_code of the selected persona.
     *
     * @param string $scamTypeCode Scam type code (e.g. 'PHISHING')
     *
     * @return string|null persona_code of the selected persona, or null if no active persona
     */
    public function selectPersona(string $scamTypeCode): ?string
    {
        $result = $this->selectPersonaWithStrategy($scamTypeCode);

        return $result['persona_code'] ?? null;
    }

    /**
     * Selects the optimal persona for a given scam_type.
     * Returns the persona_code AND the strategy used.
     *
     * @param string $scamTypeCode Scam type code (e.g. 'PHISHING')
     *
     * @return array{persona_code: string|null, strategy: string|null}
     */
    public function selectPersonaWithStrategy(string $scamTypeCode): array
    {
        // 1. Retrieve the ScamType
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy(['code' => $scamTypeCode]);

        if ($scamType === null) {
            $this->logger->error('ScamType not found', ['scam_type_code' => $scamTypeCode]);

            return ['persona_code' => null, 'strategy' => null];
        }

        // 2. Retrieve all active personas
        $allPersonas = $this->em->getRepository(Persona::class)->findBy(['isActive' => true]);

        if ($allPersonas === []) {
            $this->logger->error('No active personas found');

            return ['persona_code' => null, 'strategy' => null];
        }

        // 3. Retrieve performance stats for this scam_type
        $statsEntities = $this->statsRepository->findAllByScamType($scamType);

        // 4. Convert to a persona_code => PersonaPerformance map
        $statsMap = [];

        foreach ($statsEntities as $statsEntity) {
            $performance = $statsEntity->toPersonaPerformance();
            $statsMap[$performance->getPersonaCode()] = $performance;
        }

        // Fetch in-flight pull counts (OPEN convs per persona on
        // this scam_type) to feed the UCB1 effective N. Without this, a burst
        // of selections on the same persona never deflates the exploration
        // bonus until conv outcomes arrive, causing the "stuck persona"
        // feedback loop.
        $inFlightCounts = $this->convRepository?->countOpenByPersonaForScamType($scamType) ?? [];

        // 5. Build complete list with cold start for personas without stats
        $performances = [];

        foreach ($allPersonas as $persona) {
            $personaCode = $persona->getPersonaCode();
            $inFlight = $inFlightCounts[$personaCode] ?? 0;

            if (isset($statsMap[$personaCode])) {
                // Rebuild the VO with the in-flight count attached.
                // toPersonaPerformance() doesn't know about in-flight (factory
                // lives in the Infrastructure layer and stays closed-only).
                $existing = $statsMap[$personaCode];
                $performances[] = new PersonaPerformance(
                    personaCode: $existing->getPersonaCode(),
                    scamTypeCode: $existing->getScamTypeCode(),
                    sessionsCount: $existing->getSessionsCount(),
                    rewardAvg: $existing->getRewardAvg(),
                    inFlightSessions: $inFlight,
                );
            } else {
                // Persona with no stats = cold start (0 sessions)
                $performances[] = new PersonaPerformance(
                    personaCode: $personaCode,
                    scamTypeCode: $scamTypeCode,
                    sessionsCount: 0,
                    rewardAvg: 0.0,
                    inFlightSessions: $inFlight,
                );
            }
        }

        // 6. Check if ALL personas are in cold start
        $allInColdStart = true;

        foreach ($performances as $perf) {
            if (!$perf->isInColdStart()) {
                $allInColdStart = false;

                break;
            }
        }

        // Total effective N (closed + in-flight) for audit log
        // traceability. Surfaced via 'effective_total_sessions' in payloads
        // so operators can correlate bandit decisions with the in-flight
        // state at the moment of selection.
        $totalEffectiveN = array_sum(array_map(
            static fn (PersonaPerformance $p): int => $p->getEffectiveN(),
            $performances
        ));

        // 7. Selection based on strategy
        if ($allInColdStart) {
            // ALL in cold start -> Uniform random selection (pure exploration)
            $selectedPersona = $this->selectRandomPersona($performances);

            $this->logger->info('Persona selected: ALL COLD START', [
                'scam_type_code' => $scamTypeCode,
                'selected_persona' => $selectedPersona->getPersonaCode(),
                'strategy' => 'cold_start',
                'cold_start_count' => count($performances),
            ]);

            $this->auditLogger?->log(
                \App\Domain\Audit\AuditEventType::PERSONA_SELECTED,
                'system',
                'select_persona',
                'success',
                'persona',
                $selectedPersona->getPersonaCode(),
                [
                    'scam_type_code' => $scamTypeCode,
                    'strategy' => 'cold_start',
                    'effective_total_sessions' => $totalEffectiveN,
                ],
            );

            // Rich introspection event
            $this->emitBanditDecision(
                $scamTypeCode,
                'cold_start',
                $selectedPersona,
                $performances,
                self::EPSILON,
                false,
                $totalEffectiveN,
                null,
                null,
            );

            return ['persona_code' => $selectedPersona->getPersonaCode(), 'strategy' => 'cold_start'];
        }

        // 8. Check convergence to reduce exploration when a dominant persona exists
        $converged = $this->isConvergedFromPerformances($performances);
        $effectiveEpsilon = $converged ? self::CONVERGED_EPSILON : self::EPSILON;

        // 9. ε-greedy : exploration vs exploitation
        $random = mt_rand() / mt_getrandmax();

        if ($random < $effectiveEpsilon) {
            // EXPLORATION (20%): Random selection
            $selectedPersona = $this->selectRandomPersona($performances);

            $this->logger->info('Persona selected: EXPLORATION', [
                'scam_type_code' => $scamTypeCode,
                'selected_persona' => $selectedPersona->getPersonaCode(),
                'strategy' => 'exploration',
                'epsilon' => $effectiveEpsilon,
                'converged' => $converged,
                'random_value' => $random,
            ]);

            $this->auditLogger?->log(
                \App\Domain\Audit\AuditEventType::PERSONA_SELECTED,
                'system',
                'select_persona',
                'success',
                'persona',
                $selectedPersona->getPersonaCode(),
                [
                    'scam_type_code' => $scamTypeCode,
                    'strategy' => 'exploration',
                    'epsilon' => $effectiveEpsilon,
                    'converged' => $converged,
                    'effective_total_sessions' => $totalEffectiveN,
                ],
            );

            // Rich introspection event
            $this->emitBanditDecision(
                $scamTypeCode,
                'exploration',
                $selectedPersona,
                $performances,
                $effectiveEpsilon,
                $converged,
                $totalEffectiveN,
                $random,
                null,
            );

            return ['persona_code' => $selectedPersona->getPersonaCode(), 'strategy' => 'exploration'];
        }

        // EXPLOITATION (80%) : Meilleur reward_avg
        $selectedPersona = $this->selectBestPersona($performances);

        $this->logger->info('Persona selected: EXPLOITATION', [
            'scam_type_code' => $scamTypeCode,
            'selected_persona' => $selectedPersona->getPersonaCode(),
            'strategy' => 'exploitation',
            'reward_avg' => $selectedPersona->getRewardAvg(),
            'sessions_count' => $selectedPersona->getSessionsCount(),
            'converged' => $converged,
            'epsilon' => $effectiveEpsilon,
            'random_value' => $random,
        ]);

        $this->auditLogger?->log(
            \App\Domain\Audit\AuditEventType::PERSONA_SELECTED,
            'system',
            'select_persona',
            'success',
            'persona',
            $selectedPersona->getPersonaCode(),
            [
                'scam_type_code' => $scamTypeCode,
                'strategy' => 'exploitation',
                'reward_avg' => $selectedPersona->getRewardAvg(),
                'epsilon' => $effectiveEpsilon,
                'converged' => $converged,
                'effective_total_sessions' => $totalEffectiveN,
                'in_flight_sessions' => $selectedPersona->getInFlightSessions(),
            ],
        );

        // Rich introspection event. Compute the selected
        // UCB1 score from the same eligible pool selectBestPersona used.
        $selectedUcb1 = $selectedPersona->isInColdStart()
            ? null
            : $selectedPersona->getAdjustedScore(
                array_sum(array_map(
                    static fn (PersonaPerformance $p): int => $p->getEffectiveN(),
                    array_filter($performances, static fn (PersonaPerformance $p): bool => !$p->isInColdStart()),
                )),
                self::EXPLORATION_BONUS_C,
            );

        $this->emitBanditDecision(
            $scamTypeCode,
            'exploitation',
            $selectedPersona,
            $performances,
            $effectiveEpsilon,
            $converged,
            $totalEffectiveN,
            $random,
            $selectedUcb1,
        );

        return ['persona_code' => $selectedPersona->getPersonaCode(), 'strategy' => 'exploitation'];
    }

    /**
     * Emit BANDIT_DECISION audit row carrying the FULL
     * decision context: selected persona + ALL candidates (with UCB1 scores
     * when applicable) + random_value + epsilon + converged flag.
     *
     * Complements (does not replace) PERSONA_SELECTED. Use case: research-grade
     * introspection of the bandit's behavior over the post-TRUNCATE learning
     * window. Lets operators answer "why was X picked over Y at decision T?"
     * from a single SQL query.
     *
     * @param PersonaPerformance[] $performances All active personas with their state
     */
    private function emitBanditDecision(
        string $scamTypeCode,
        string $strategy,
        PersonaPerformance $selected,
        array $performances,
        float $epsilon,
        bool $converged,
        int $totalEffectiveN,
        ?float $randomValue,
        ?float $selectedUcb1Score,
    ): void {
        if (!$this->auditLogger instanceof AuditLogger) {
            return;
        }

        // UCB1 denominator uses only non-cold-start personas (mirrors
        // selectBestPersona). For the candidate listing we report UCB1 only
        // for eligible (non-cold-start) personas; cold_start entries get null.
        $eligibleTotalN = array_sum(array_map(
            static fn (PersonaPerformance $p): int => $p->getEffectiveN(),
            array_filter($performances, static fn (PersonaPerformance $p): bool => !$p->isInColdStart()),
        ));

        $candidates = array_map(
            function (PersonaPerformance $p) use ($selected, $eligibleTotalN): array {
                $isColdStart = $p->isInColdStart();

                return [
                    'persona_code' => $p->getPersonaCode(),
                    'reward_avg' => $p->getRewardAvg(),
                    'sessions_count' => $p->getSessionsCount(),
                    'in_flight_sessions' => $p->getInFlightSessions(),
                    'ucb1_score' => $isColdStart ? null : $p->getAdjustedScore($eligibleTotalN, self::EXPLORATION_BONUS_C),
                    'is_cold_start' => $isColdStart,
                    'was_selected' => $p->getPersonaCode() === $selected->getPersonaCode(),
                ];
            },
            $performances,
        );

        // Sort candidates by UCB1 desc (cold_start entries last)
        usort($candidates, static function (array $a, array $b): int {
            $aScore = $a['ucb1_score'] ?? -INF;
            $bScore = $b['ucb1_score'] ?? -INF;

            return $bScore <=> $aScore;
        });

        $this->auditLogger->log(
            \App\Domain\Audit\AuditEventType::BANDIT_DECISION,
            'system',
            'select_persona',
            'success',
            'persona',
            $selected->getPersonaCode(),
            [
                'scam_type_code' => $scamTypeCode,
                'strategy' => $strategy,
                'epsilon' => $epsilon,
                'converged' => $converged,
                'random_value' => $randomValue,
                'effective_total_sessions' => $totalEffectiveN,
                'selected' => [
                    'persona_code' => $selected->getPersonaCode(),
                    'reward_avg' => $selected->getRewardAvg(),
                    'sessions_count' => $selected->getSessionsCount(),
                    'in_flight_sessions' => $selected->getInFlightSessions(),
                    'ucb1_score' => $selectedUcb1Score,
                    'is_cold_start' => $selected->isInColdStart(),
                ],
                'candidates' => $candidates,
            ],
        );
    }

    /**
     * Selects a random persona (uniform distribution).
     *
     * @param PersonaPerformance[] $performances
     */
    private function selectRandomPersona(array $performances): PersonaPerformance
    {
        if ($performances === []) {
            throw new \RuntimeException('Cannot select persona from empty list');
        }

        $randomIndex = array_rand($performances);

        return $performances[$randomIndex];
    }

    /**
     * Selects the persona with the best reward_avg.
     * In case of tie, selects the one with the most sessions (higher confidence).
     * If perfect tie, selects randomly among tied candidates.
     *
     * @param PersonaPerformance[] $performances
     */
    private function selectBestPersona(array $performances): PersonaPerformance
    {
        if ($performances === []) {
            throw new \RuntimeException('Cannot select best persona from empty list');
        }

        // Filter personas in cold start (cannot be exploited)
        $eligiblePerformances = array_filter($performances, fn (PersonaPerformance $perf): bool => !$perf->isInColdStart());

        // If ALL are in cold start (should not reach here, but safety check)
        if ($eligiblePerformances === []) {
            return $this->selectRandomPersona($performances);
        }

        // Compute total sessions for UCB1 bonus calculation.
        // Uses effective N (closed + in-flight) so the bonus
        // denominator and the ln(total) numerator stay coherent.
        $totalSessions = array_sum(array_map(
            static fn (PersonaPerformance $p): int => $p->getEffectiveN(),
            $eligiblePerformances
        ));

        // Sort by UCB1 adjusted score DESC (reward_avg + exploration bonus)
        usort($eligiblePerformances, function (PersonaPerformance $a, PersonaPerformance $b) use ($totalSessions): int {
            $scoreA = $a->getAdjustedScore($totalSessions, self::EXPLORATION_BONUS_C);
            $scoreB = $b->getAdjustedScore($totalSessions, self::EXPLORATION_BONUS_C);

            $scoreDiff = $scoreB <=> $scoreA;

            if ($scoreDiff !== 0) {
                return $scoreDiff;
            }

            return $b->getSessionsCount() <=> $a->getSessionsCount();
        });

        // Handle ex-aequo on adjusted score
        $bestScore = $eligiblePerformances[0]->getAdjustedScore($totalSessions, self::EXPLORATION_BONUS_C);
        $bestPerformances = array_filter($eligiblePerformances, fn (PersonaPerformance $perf): bool => abs($perf->getAdjustedScore($totalSessions, self::EXPLORATION_BONUS_C) - $bestScore) < 0.0001);

        // If multiple tied, random selection
        if (count($bestPerformances) > 1) {
            $randomIndex = array_rand($bestPerformances);

            return $bestPerformances[$randomIndex];
        }

        return $bestPerformances[0];
    }

    /**
     * Check if the bandit has converged for a given scam type.
     * Convergence = best persona would be selected >= CONVERGENCE_THRESHOLD of the time.
     */
    public function isConverged(string $scamTypeCode): bool
    {
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy(['code' => $scamTypeCode]);

        if ($scamType === null) {
            return false;
        }

        $allPersonas = $this->em->getRepository(Persona::class)->findBy(['isActive' => true]);
        $statsEntities = $this->statsRepository->findAllByScamType($scamType);

        $statsMap = [];

        foreach ($statsEntities as $entity) {
            $perf = $entity->toPersonaPerformance();
            $statsMap[$perf->getPersonaCode()] = $perf;
        }

        $performances = [];

        foreach ($allPersonas as $persona) {
            $code = $persona->getPersonaCode();
            $performances[] = $statsMap[$code] ?? new PersonaPerformance($code, $scamTypeCode, 0, 0.0);
        }

        return $this->isConvergedFromPerformances($performances);
    }

    /**
     * Internal convergence check from a pre-built performance list.
     *
     * @param PersonaPerformance[] $performances
     */
    private function isConvergedFromPerformances(array $performances): bool
    {
        $eligible = array_filter($performances, static fn (PersonaPerformance $p): bool => !$p->isInColdStart());

        if (count($eligible) < 2) {
            return false;
        }

        usort($eligible, static fn (PersonaPerformance $a, PersonaPerformance $b): int => $b->getRewardAvg() <=> $a->getRewardAvg());

        $best = $eligible[0];

        if ($best->getSessionsCount() < self::MIN_SESSIONS_FOR_CONVERGENCE) {
            return false;
        }

        $totalSessions = array_sum(array_map(static fn (PersonaPerformance $p): int => $p->getSessionsCount(), $eligible));

        if ($totalSessions === 0) {
            return false;
        }

        $selectionShare = $best->getSessionsCount() / $totalSessions;

        return $selectionShare >= self::CONVERGENCE_THRESHOLD;
    }

    /**
     * Returns selection statistics for a scam_type (for debugging/monitoring).
     *
     * @param string $scamTypeCode Scam type code
     *
     * @return array{
     *     scam_type_code: string,
     *     total_personas: int,
     *     cold_start_count: int,
     *     epsilon: float,
     *     cold_start_threshold: int,
     *     converged: bool,
     *     convergence_threshold: float,
     *     best_persona: array{persona_code: string, reward_avg: float, sessions_count: int}|null,
     *     top_5: array<array{persona_code: string, reward_avg: float, sessions_count: int}>
     * }|array{error: string, scam_type_code: string, total_personas: int, cold_start_count: int, epsilon: float, cold_start_threshold: int, best_persona: null, top_5: array<never>}
     */
    public function getSelectionStats(string $scamTypeCode): array
    {
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy(['code' => $scamTypeCode]);

        if ($scamType === null) {
            return [
                'error' => 'ScamType not found',
                'scam_type_code' => $scamTypeCode,
                'total_personas' => 0,
                'cold_start_count' => 0,
                'epsilon' => self::EPSILON,
                'cold_start_threshold' => self::COLD_START_THRESHOLD,
                'best_persona' => null,
                'top_5' => [],
            ];
        }

        $allPersonas = $this->em->getRepository(Persona::class)->findBy(['isActive' => true]);
        $coldStartCount = $this->statsRepository->countColdStartPersonas($scamType, self::COLD_START_THRESHOLD);

        $bestEntity = $this->statsRepository->findBestPerformingPersona($scamType);
        $top5Entities = $this->statsRepository->findTopPerformingPersonas($scamType, 5);

        $bestPersona = null;

        if ($bestEntity instanceof \App\Infrastructure\Doctrine\Entity\PersonaPerformanceStatsEntity) {
            $bestPerf = $bestEntity->toPersonaPerformance();
            $bestPersona = [
                'persona_code' => $bestPerf->getPersonaCode(),
                'reward_avg' => $bestPerf->getRewardAvg(),
                'sessions_count' => $bestPerf->getSessionsCount(),
            ];
        }

        $top5 = array_map(static function ($entity): array {
            $perf = $entity->toPersonaPerformance();

            return [
                'persona_code' => $perf->getPersonaCode(),
                'reward_avg' => $perf->getRewardAvg(),
                'sessions_count' => $perf->getSessionsCount(),
            ];
        }, $top5Entities);

        $converged = $this->isConverged($scamTypeCode);

        return [
            'scam_type_code' => $scamTypeCode,
            'total_personas' => count($allPersonas),
            'cold_start_count' => $coldStartCount,
            'epsilon' => $converged ? self::CONVERGED_EPSILON : self::EPSILON,
            'cold_start_threshold' => self::COLD_START_THRESHOLD,
            'converged' => $converged,
            'convergence_threshold' => self::CONVERGENCE_THRESHOLD,
            'best_persona' => $bestPersona,
            'top_5' => $top5,
        ];
    }
}

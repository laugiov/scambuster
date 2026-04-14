<?php

declare(strict_types=1);

namespace App\Application\Scambaiting;

use App\Application\Audit\AuditLogger;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Domain\Scambaiting\PersonaPerformance;
use App\Infrastructure\Doctrine\Repository\PersonaPerformanceStatsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service métier pour la sélection optimisée de persona.
 * Implémente un algorithme ε-greedy contextuel (1 bandit par scam_type).
 *
 * Algorithme :
 * 1. Si TOUS les personas sont en cold start (<3 sessions) → Sélection aléatoire uniforme
 * 2. Sinon avec probabilité ε=0.20 → Exploration (sélection aléatoire)
 * 3. Sinon avec probabilité 1-ε=0.80 → Exploitation (meilleur reward_avg)
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
     * Sélectionne le persona optimal pour un scam_type donné.
     * Retourne le persona_code du persona sélectionné.
     *
     * @param string $scamTypeCode Code du scam type (ex: 'PHISHING')
     *
     * @return string|null persona_code du persona sélectionné, ou null si aucun persona actif
     */
    public function selectPersona(string $scamTypeCode): ?string
    {
        $result = $this->selectPersonaWithStrategy($scamTypeCode);

        return $result['persona_code'] ?? null;
    }

    /**
     * Sélectionne le persona optimal pour un scam_type donné.
     * Retourne le persona_code ET la stratégie utilisée.
     *
     * @param string $scamTypeCode Code du scam type (ex: 'PHISHING')
     *
     * @return array{persona_code: string|null, strategy: string|null}
     */
    public function selectPersonaWithStrategy(string $scamTypeCode): array
    {
        // 1. Récupérer le ScamType
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy(['code' => $scamTypeCode]);

        if ($scamType === null) {
            $this->logger->error('ScamType not found', ['scam_type_code' => $scamTypeCode]);

            return ['persona_code' => null, 'strategy' => null];
        }

        // 2. Récupérer tous les personas actifs
        $allPersonas = $this->em->getRepository(Persona::class)->findBy(['isActive' => true]);

        if ($allPersonas === []) {
            $this->logger->error('No active personas found');

            return ['persona_code' => null, 'strategy' => null];
        }

        // 3. Récupérer les stats de performance pour ce scam_type
        $statsEntities = $this->statsRepository->findAllByScamType($scamType);

        // 4. Convertir en map persona_code => PersonaPerformance
        $statsMap = [];

        foreach ($statsEntities as $statsEntity) {
            $performance = $statsEntity->toPersonaPerformance();
            $statsMap[$performance->getPersonaCode()] = $performance;
        }

        // 5. Construire la liste complète avec cold start pour personas sans stats
        $performances = [];

        foreach ($allPersonas as $persona) {
            $personaCode = $persona->getPersonaCode();

            if (isset($statsMap[$personaCode])) {
                $performances[] = $statsMap[$personaCode];
            } else {
                // Persona sans stats = cold start (0 sessions)
                $performances[] = new PersonaPerformance(
                    personaCode: $personaCode,
                    scamTypeCode: $scamTypeCode,
                    sessionsCount: 0,
                    rewardAvg: 0.0
                );
            }
        }

        // 6. Vérifier si TOUS les personas sont en cold start
        $allInColdStart = true;

        foreach ($performances as $perf) {
            if (!$perf->isInColdStart()) {
                $allInColdStart = false;

                break;
            }
        }

        // 7. Sélection selon la stratégie
        if ($allInColdStart) {
            // TOUS en cold start → Sélection aléatoire uniforme (pure exploration)
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
                ],
            );

            return ['persona_code' => $selectedPersona->getPersonaCode(), 'strategy' => 'cold_start'];
        }

        // 8. Check convergence to reduce exploration when a dominant persona exists
        $converged = $this->isConvergedFromPerformances($performances);
        $effectiveEpsilon = $converged ? self::CONVERGED_EPSILON : self::EPSILON;

        // 9. ε-greedy : exploration vs exploitation
        $random = mt_rand() / mt_getrandmax();

        if ($random < $effectiveEpsilon) {
            // EXPLORATION (20%) : Sélection aléatoire
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
                ],
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
            ],
        );

        return ['persona_code' => $selectedPersona->getPersonaCode(), 'strategy' => 'exploitation'];
    }

    /**
     * Sélectionne un persona aléatoire (distribution uniforme).
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
     * Sélectionne le persona avec le meilleur reward_avg.
     * En cas d'égalité, sélectionne celui avec le plus de sessions (plus de confiance).
     * Si égalité parfaite, sélectionne aléatoirement parmi les ex-aequo.
     *
     * @param PersonaPerformance[] $performances
     */
    private function selectBestPersona(array $performances): PersonaPerformance
    {
        if ($performances === []) {
            throw new \RuntimeException('Cannot select best persona from empty list');
        }

        // Filtrer les personas en cold start (ne peuvent pas être exploités)
        $eligiblePerformances = array_filter($performances, fn (PersonaPerformance $perf): bool => !$perf->isInColdStart());

        // Si TOUS sont en cold start (ne devrait pas arriver ici, mais sécurité)
        if ($eligiblePerformances === []) {
            return $this->selectRandomPersona($performances);
        }

        // Compute total sessions for UCB1 bonus calculation
        $totalSessions = array_sum(array_map(
            static fn (PersonaPerformance $p): int => $p->getSessionsCount(),
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

        // Si plusieurs ex-aequo, sélection aléatoire
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
     * Retourne les statistiques de sélection pour un scam_type (pour debugging/monitoring).
     *
     * @param string $scamTypeCode Code du scam type
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

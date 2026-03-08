<?php

declare(strict_types=1);

namespace App\Application\Scambaiting;

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
final class PersonaOptimizer
{
    // Epsilon : probabilité d'exploration (20% = 0.20)
    private const EPSILON = 0.20;

    // Cold start : minimum de sessions avant d'activer l'exploitation
    private const COLD_START_THRESHOLD = 3;

    public function __construct(
        private readonly PersonaPerformanceStatsRepository $statsRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Sélectionne le persona optimal pour un scam_type donné.
     * Retourne le persona_code du persona sélectionné.
     *
     * @param string $scamTypeCode Code du scam type (ex: 'PHISHING')
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

        if (empty($allPersonas)) {
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

            return ['persona_code' => $selectedPersona->getPersonaCode(), 'strategy' => 'cold_start'];
        }

        // 8. ε-greedy : exploration vs exploitation
        $random = mt_rand() / mt_getrandmax(); // Génère [0.0, 1.0]

        if ($random < self::EPSILON) {
            // EXPLORATION (20%) : Sélection aléatoire
            $selectedPersona = $this->selectRandomPersona($performances);

            $this->logger->info('Persona selected: EXPLORATION', [
                'scam_type_code' => $scamTypeCode,
                'selected_persona' => $selectedPersona->getPersonaCode(),
                'strategy' => 'exploration',
                'epsilon' => self::EPSILON,
                'random_value' => $random,
            ]);

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
            'random_value' => $random,
        ]);

        return ['persona_code' => $selectedPersona->getPersonaCode(), 'strategy' => 'exploitation'];
    }

    /**
     * Sélectionne un persona aléatoire (distribution uniforme).
     *
     * @param PersonaPerformance[] $performances
     * @return PersonaPerformance
     */
    private function selectRandomPersona(array $performances): PersonaPerformance
    {
        if (empty($performances)) {
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
     * @return PersonaPerformance
     */
    private function selectBestPersona(array $performances): PersonaPerformance
    {
        if (empty($performances)) {
            throw new \RuntimeException('Cannot select best persona from empty list');
        }

        // Filtrer les personas en cold start (ne peuvent pas être exploités)
        $eligiblePerformances = array_filter($performances, function (PersonaPerformance $perf) {
            return !$perf->isInColdStart();
        });

        // Si TOUS sont en cold start (ne devrait pas arriver ici, mais sécurité)
        if (empty($eligiblePerformances)) {
            return $this->selectRandomPersona($performances);
        }

        // Trier par reward_avg DESC, puis par sessions_count DESC
        usort($eligiblePerformances, function (PersonaPerformance $a, PersonaPerformance $b) {
            $rewardDiff = $b->getRewardAvg() <=> $a->getRewardAvg();

            if ($rewardDiff !== 0) {
                return $rewardDiff;
            }

            // Égalité de reward : départager par sessions_count
            return $b->getSessionsCount() <=> $a->getSessionsCount();
        });

        // Prendre tous les personas avec le meilleur reward_avg (gestion des ex-aequo)
        $bestReward = $eligiblePerformances[0]->getRewardAvg();
        $bestPerformances = array_filter($eligiblePerformances, function (PersonaPerformance $perf) use ($bestReward) {
            return abs($perf->getRewardAvg() - $bestReward) < 0.0001; // Tolérance float
        });

        // Si plusieurs ex-aequo, sélection aléatoire
        if (count($bestPerformances) > 1) {
            $randomIndex = array_rand($bestPerformances);
            return $bestPerformances[$randomIndex];
        }

        return $bestPerformances[0];
    }

    /**
     * Retourne les statistiques de sélection pour un scam_type (pour debugging/monitoring).
     *
     * @param string $scamTypeCode Code du scam type
     * @return array{
     *     scam_type_code: string,
     *     total_personas: int,
     *     cold_start_count: int,
     *     epsilon: float,
     *     cold_start_threshold: int,
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
        if ($bestEntity !== null) {
            $bestPerf = $bestEntity->toPersonaPerformance();
            $bestPersona = [
                'persona_code' => $bestPerf->getPersonaCode(),
                'reward_avg' => $bestPerf->getRewardAvg(),
                'sessions_count' => $bestPerf->getSessionsCount(),
            ];
        }

        $top5 = array_map(static function ($entity) {
            $perf = $entity->toPersonaPerformance();
            return [
                'persona_code' => $perf->getPersonaCode(),
                'reward_avg' => $perf->getRewardAvg(),
                'sessions_count' => $perf->getSessionsCount(),
            ];
        }, $top5Entities);

        return [
            'scam_type_code' => $scamTypeCode,
            'total_personas' => count($allPersonas),
            'cold_start_count' => $coldStartCount,
            'epsilon' => self::EPSILON,
            'cold_start_threshold' => self::COLD_START_THRESHOLD,
            'best_persona' => $bestPersona,
            'top_5' => $top5,
        ];
    }
}

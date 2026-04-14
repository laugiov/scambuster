<?php

declare(strict_types=1);

namespace App\Domain\Scambaiting;

/**
 * Value Object représentant les performances d'un persona pour un scam_type donné.
 * Utilisé par l'algorithme ε-greedy pour la sélection de persona.
 */
final readonly class PersonaPerformance implements \Stringable
{
    // Cold start : minimum 3 sessions avant d'activer l'exploitation
    private const COLD_START_THRESHOLD = 3;

    /**
     * @param string $personaCode   Code du persona (ex: 'elderly_person')
     * @param string $scamTypeCode  Code du scam type (ex: 'PHISHING')
     * @param int    $sessionsCount Nombre de sessions complétées (>= 0)
     * @param float  $rewardAvg     Reward moyen [0.0, 1.0]
     *
     * @throws \InvalidArgumentException Si les valeurs sont invalides
     */
    public function __construct(
        private string $personaCode,
        private string $scamTypeCode,
        private int $sessionsCount,
        private float $rewardAvg
    ) {
        $this->validate();
    }

    /**
     * Calcule la nouvelle moyenne mobile après ajout d'un nouveau reward.
     * Formule : reward_avg_new = (reward_avg_old × sessions_count + reward_new) / (sessions_count + 1)
     *
     * @param float $newReward Nouveau reward à intégrer [0.0, 1.0]
     *
     * @return self Nouvelle instance avec stats mises à jour
     */
    public function withNewReward(float $newReward): self
    {
        if ($newReward < 0.0 || $newReward > 1.0) {
            throw new \InvalidArgumentException(
                "New reward must be in [0.0, 1.0], got {$newReward}"
            );
        }

        $newSessionsCount = $this->sessionsCount + 1;
        $newRewardAvg = ($this->rewardAvg * $this->sessionsCount + $newReward) / $newSessionsCount;

        return new self(
            personaCode: $this->personaCode,
            scamTypeCode: $this->scamTypeCode,
            sessionsCount: $newSessionsCount,
            rewardAvg: $newRewardAvg
        );
    }

    /**
     * Détermine si le persona est en phase de cold start.
     * Un persona en cold start doit être sélectionné de manière uniforme (pure exploration).
     *
     * @return bool True si sessionsCount < COLD_START_THRESHOLD
     */
    public function isInColdStart(): bool
    {
        return $this->sessionsCount < self::COLD_START_THRESHOLD;
    }

    /**
     * Valide les contraintes métier.
     *
     * @throws \InvalidArgumentException Si une contrainte est violée
     */
    private function validate(): void
    {
        if ($this->personaCode === '' || $this->personaCode === '0') {
            throw new \InvalidArgumentException('Persona code cannot be empty');
        }

        if ($this->scamTypeCode === '' || $this->scamTypeCode === '0') {
            throw new \InvalidArgumentException('Scam type code cannot be empty');
        }

        if ($this->sessionsCount < 0) {
            throw new \InvalidArgumentException(
                "Sessions count must be >= 0, got {$this->sessionsCount}"
            );
        }

        if ($this->rewardAvg < 0.0 || $this->rewardAvg > 1.0) {
            throw new \InvalidArgumentException(
                "Reward average must be in [0.0, 1.0], got {$this->rewardAvg}"
            );
        }
    }

    // Getters (readonly properties)

    public function getPersonaCode(): string
    {
        return $this->personaCode;
    }

    public function getScamTypeCode(): string
    {
        return $this->scamTypeCode;
    }

    public function getSessionsCount(): int
    {
        return $this->sessionsCount;
    }

    public function getRewardAvg(): float
    {
        return $this->rewardAvg;
    }

    /**
     * UCB1 adjusted score: reward_avg + C * sqrt(ln(totalSessions) / personaSessions).
     * Gives an exploration bonus to underexplored arms that decays with more observations.
     */
    public function getAdjustedScore(int $totalSessions, float $explorationC): float
    {
        if ($this->sessionsCount === 0 || $totalSessions <= 1) {
            return $this->rewardAvg;
        }

        $bonus = $explorationC * sqrt(log($totalSessions) / $this->sessionsCount);

        return $this->rewardAvg + $bonus;
    }

    public static function getColdStartThreshold(): int
    {
        return self::COLD_START_THRESHOLD;
    }

    /**
     * Représentation textuelle pour debugging.
     */
    public function __toString(): string
    {
        $coldStartStatus = $this->isInColdStart() ? ' [COLD START]' : '';

        return sprintf(
            'PersonaPerformance(persona=%s, scamType=%s, sessions=%d, rewardAvg=%.4f%s)',
            $this->personaCode,
            $this->scamTypeCode,
            $this->sessionsCount,
            $this->rewardAvg,
            $coldStartStatus
        );
    }
}

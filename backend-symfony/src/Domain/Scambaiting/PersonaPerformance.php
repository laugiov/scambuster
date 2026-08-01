<?php

declare(strict_types=1);

namespace App\Domain\Scambaiting;

/**
 * Value Object representing performance for a persona for a given scam_type.
 * Used by the epsilon-greedy algorithm for persona selection.
 */
final readonly class PersonaPerformance implements \Stringable
{
    // Cold start : minimum 3 sessions avant d'activer l'exploitation
    private const COLD_START_THRESHOLD = 3;

    /**
     * @param string $personaCode      Persona code (e.g. 'elderly_person')
     * @param string $scamTypeCode     Scam type code (e.g. 'PHISHING')
     * @param int    $sessionsCount    Number of CLOSED sessions (>= 0). Drives reward_avg and cold-start gate.
     * @param float  $rewardAvg        Average reward [0.0, 1.0]
     * @param int    $inFlightSessions Number of OPEN conversations on (persona, scam_type)
     *                                 not yet folded into reward_avg. Defaults to 0 for backward
     *                                 compatibility. Only inflates the UCB1 exploration bonus denominator;
     *                                 does NOT affect reward_avg, cold-start gate, or convergence detection.
     *
     * @throws \InvalidArgumentException If the values are invalid
     */
    public function __construct(
        private string $personaCode,
        private string $scamTypeCode,
        private int $sessionsCount,
        private float $rewardAvg,
        private int $inFlightSessions = 0,
    ) {
        $this->validate();
    }

    /**
     * Computes the new moving average after adding a new reward.
     * Formule : reward_avg_new = (reward_avg_old × sessions_count + reward_new) / (sessions_count + 1)
     *
     * @param float $newReward New reward to integrate [0.0, 1.0]
     *
     * @return self New instance with updated stats
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

        // Invariant: withNewReward() is the closure-side
        // formula. In-flight tracking is read-side only; the new instance
        // preserves whatever in-flight count was on the original.
        return new self(
            personaCode: $this->personaCode,
            scamTypeCode: $this->scamTypeCode,
            sessionsCount: $newSessionsCount,
            rewardAvg: $newRewardAvg,
            inFlightSessions: $this->inFlightSessions,
        );
    }

    /**
     * Determines if the persona is in cold start phase.
     * A persona in cold start must be selected uniformly (pure exploration).
     *
     * @return bool True si sessionsCount < COLD_START_THRESHOLD
     */
    public function isInColdStart(): bool
    {
        return $this->sessionsCount < self::COLD_START_THRESHOLD;
    }

    /**
     * Validates business constraints.
     *
     * @throws \InvalidArgumentException If a constraint is violated
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

        if ($this->inFlightSessions < 0) {
            throw new \InvalidArgumentException(
                "In-flight sessions count must be >= 0, got {$this->inFlightSessions}"
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
     * In-flight pull count (OPEN conversations on (persona, scam_type)
     * not yet folded into reward_avg). Read-side concept used to deflate the UCB1
     * exploration bonus and avoid the "stuck persona" feedback loop on async pulls.
     */
    public function getInFlightSessions(): int
    {
        return $this->inFlightSessions;
    }

    /**
     * Effective sample size for UCB1: closed + in-flight. Used in the
     * exploration bonus denominator. Reward_avg and cold-start gate remain
     * closed-only.
     */
    public function getEffectiveN(): int
    {
        return $this->sessionsCount + $this->inFlightSessions;
    }

    /**
     * UCB1 adjusted score: reward_avg + C * sqrt(ln(totalSessions) / effectiveN).
     * Gives an exploration bonus to underexplored arms that decays with more
     * observations. effectiveN counts both closed AND in-flight pulls
     * so a burst of async selections naturally deflates the bonus before reward
     * outcomes arrive.
     */
    public function getAdjustedScore(int $totalSessions, float $explorationC): float
    {
        $effectiveN = $this->getEffectiveN();

        if ($effectiveN === 0 || $totalSessions <= 1) {
            return $this->rewardAvg;
        }

        $bonus = $explorationC * sqrt(log($totalSessions) / $effectiveN);

        return $this->rewardAvg + $bonus;
    }

    public static function getColdStartThreshold(): int
    {
        return self::COLD_START_THRESHOLD;
    }

    /**
     * String representation for debugging.
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

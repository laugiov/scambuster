<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Entity;

use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Domain\Scambaiting\PersonaPerformance;
use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine entity for the persona_performance_stats table.
 * Stores performance statistics for a persona for a given scam_type.
 *
 * IMPORTANT: This entity is in Infrastructure/, NOT in Domain/,
 * car elle contient des annotations Doctrine (violation de la pure business logic).
 */
#[ORM\Entity(repositoryClass: \App\Infrastructure\Doctrine\Repository\PersonaPerformanceStatsRepository::class)]
#[ORM\Table(name: 'persona_performance_stats')]
#[ORM\Index(name: 'idx_persona_performance_reward', columns: ['reward_avg'])]
#[ORM\Index(name: 'idx_persona_performance_scam_type', columns: ['scam_type_id'])]
class PersonaPerformanceStatsEntity
{
    #[ORM\Column(name: 'reward_sum', type: 'decimal', precision: 10, scale: 4, nullable: false, options: ['default' => '0.0000'])]
    private string $rewardSum = '0.0000'; // Doctrine utilise string pour DECIMAL

    #[ORM\Column(name: 'reward_avg', type: 'decimal', precision: 5, scale: 4, nullable: false, options: ['default' => '0.0000'])]
    private string $rewardAvg = '0.0000';

    /**
     * Constructor with required parameters.
     */
    public function __construct(
        /**
         * Composite key: (persona_id, scam_type_id)
         * Doctrine requires the #[ORM\Id] annotation on both columns.
         */
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: Persona::class)]
        #[ORM\JoinColumn(name: 'persona_id', referencedColumnName: 'persona_id', nullable: false, onDelete: 'CASCADE')]
        private Persona $persona,
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: ScamType::class)]
        #[ORM\JoinColumn(name: 'scam_type_id', referencedColumnName: 'scam_type_id', nullable: false, onDelete: 'CASCADE')]
        private ScamType $scamType,
        #[ORM\Column(name: 'sessions_count', type: 'integer', nullable: false, options: ['default' => 0])]
        private int $sessionsCount = 0,
        float $rewardSum = 0.0,
        float $rewardAvg = 0.0,
        #[ORM\Column(name: 'last_updated', type: 'datetime_immutable', nullable: false)]
        private \DateTimeImmutable $lastUpdated = new \DateTimeImmutable()
    ) {
        $this->rewardSum = number_format($rewardSum, 4, '.', ''); // Convert float → string
        $this->rewardAvg = number_format($rewardAvg, 4, '.', '');
    }

    /**
     * Converts this entity to a PersonaPerformance Value Object (Domain layer).
     * Permet de passer du monde Infrastructure au monde Domain.
     */
    public function toPersonaPerformance(): PersonaPerformance
    {
        return new PersonaPerformance(
            personaCode: $this->persona->getPersonaCode(),
            scamTypeCode: $this->scamType->getCode(),
            sessionsCount: $this->sessionsCount,
            rewardAvg: (float) $this->rewardAvg
        );
    }

    /**
     * Updates stats with a new reward (moving average).
     * This method is MUTABLE (modifies the entity) because it is a Doctrine entity.
     *
     * @param float $newReward Nouveau reward [0.0, 1.0]
     *
     * @throws \InvalidArgumentException Si reward invalide
     */
    public function addReward(float $newReward): void
    {
        if ($newReward < 0.0 || $newReward > 1.0) {
            throw new \InvalidArgumentException(
                "Reward must be in [0.0, 1.0], got {$newReward}"
            );
        }

        // Moyenne mobile : reward_avg_new = (reward_avg_old × sessions + reward_new) / (sessions + 1)
        $currentRewardAvg = (float) $this->rewardAvg;
        $newSessionsCount = $this->sessionsCount + 1;
        $newRewardAvg = ($currentRewardAvg * $this->sessionsCount + $newReward) / $newSessionsCount;

        $this->sessionsCount = $newSessionsCount;
        $this->rewardSum = number_format((float) $this->rewardSum + $newReward, 4, '.', '');
        $this->rewardAvg = number_format($newRewardAvg, 4, '.', '');
        $this->lastUpdated = new \DateTimeImmutable();
    }

    // Getters

    public function getPersona(): Persona
    {
        return $this->persona;
    }

    public function getScamType(): ScamType
    {
        return $this->scamType;
    }

    public function getSessionsCount(): int
    {
        return $this->sessionsCount;
    }

    public function getRewardSum(): float
    {
        return (float) $this->rewardSum;
    }

    public function getRewardAvg(): float
    {
        return (float) $this->rewardAvg;
    }

    public function getLastUpdated(): \DateTimeImmutable
    {
        return $this->lastUpdated;
    }

    // Setters (pour Doctrine hydration)

    public function setSessionsCount(int $sessionsCount): void
    {
        $this->sessionsCount = $sessionsCount;
    }

    public function setRewardSum(float $rewardSum): void
    {
        $this->rewardSum = number_format($rewardSum, 4, '.', '');
    }

    public function setRewardAvg(float $rewardAvg): void
    {
        $this->rewardAvg = number_format($rewardAvg, 4, '.', '');
    }

    public function setLastUpdated(\DateTimeImmutable $lastUpdated): void
    {
        $this->lastUpdated = $lastUpdated;
    }
}

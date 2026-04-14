<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Entity;

use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Domain\Scambaiting\PersonaPerformance;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entité Doctrine pour la table persona_performance_stats.
 * Stocke les statistiques de performance d'un persona pour un scam_type donné.
 *
 * ⚠️ IMPORTANT : Cette entité est dans Infrastructure/, PAS dans Domain/,
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
     * Constructeur avec paramètres requis.
     */
    public function __construct(
        /**
         * Clé composite : (persona_id, scam_type_id)
         * Doctrine nécessite l'annotation #[ORM\Id] sur les deux colonnes.
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
     * Convertit cette entité en Value Object PersonaPerformance (Domain layer).
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
     * Met à jour les stats avec un nouveau reward (moyenne mobile).
     * Cette méthode est MUTABLE (modifie l'entité) car c'est une entité Doctrine.
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

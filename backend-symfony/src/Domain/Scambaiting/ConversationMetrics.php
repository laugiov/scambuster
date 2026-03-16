<?php

declare(strict_types=1);

namespace App\Domain\Scambaiting;

/**
 * Value Object représentant les métriques d'une conversation terminée.
 * Calcule le reward selon la formule multi-critères définie dans les specs.
 */
final readonly class ConversationMetrics
{
    // Constantes de normalisation (valeurs max observées)
    private const MAX_DURATION_SEC = 86400; // 24 heures (aligné avec specs)
    private const MAX_IOCS_TOTAL = 50;
    private const MAX_IOCS_SENSIBLES = 10;

    // Poids de la formule de reward
    private const WEIGHT_DURATION = 0.40;
    private const WEIGHT_IOCS_TOTAL = 0.25;
    private const WEIGHT_IOCS_SENSIBLES = 0.25;
    private const WEIGHT_COMPLETION = 0.10;

    /**
     * @param int  $durationSec   Durée de la conversation en secondes (>= 0)
     * @param int  $iocsTotal     Nombre total d'IOCs capturés (>= 0)
     * @param int  $iocsSensibles Nombre d'IOCs haute valeur (IBAN, phone, crypto) (>= 0)
     * @param bool $isCompleted   True si la conversation s'est terminée normalement
     *
     * @throws \InvalidArgumentException Si les valeurs sont invalides
     */
    public function __construct(
        private int $durationSec,
        private int $iocsTotal,
        private int $iocsSensibles,
        private bool $isCompleted
    ) {
        $this->validate();
    }

    /**
     * Calcule le reward normalisé [0.0, 1.0]
     */
    public function calculateReward(): float
    {
        $durationNorm = $this->normalize($this->durationSec, 0, self::MAX_DURATION_SEC);
        $iocsTotalNorm = $this->normalize($this->iocsTotal, 0, self::MAX_IOCS_TOTAL);
        $iocsSensiblesNorm = $this->normalize($this->iocsSensibles, 0, self::MAX_IOCS_SENSIBLES);
        $completionSignal = $this->isCompleted ? 1.0 : 0.0;

        $reward =
            (self::WEIGHT_DURATION * $durationNorm) +
            (self::WEIGHT_IOCS_TOTAL * $iocsTotalNorm) +
            (self::WEIGHT_IOCS_SENSIBLES * $iocsSensiblesNorm) +
            (self::WEIGHT_COMPLETION * $completionSignal);

        // Garantir que reward ∈ [0.0, 1.0] (protection contre erreurs de calcul)
        return max(0.0, min(1.0, $reward));
    }

    /**
     * Normalise une valeur dans [0, 1] selon min-max scaling.
     *
     * @param int|float $value Valeur à normaliser
     * @param int|float $min   Valeur minimale
     * @param int|float $max   Valeur maximale
     *
     * @return float Valeur normalisée [0.0, 1.0]
     */
    private function normalize(int|float $value, int|float $min, int|float $max): float
    {
        if ($max === $min) {
            return 0.0; // Éviter division par zéro
        }

        $normalized = ($value - $min) / ($max - $min);

        return max(0.0, min(1.0, $normalized)); // Clamping
    }

    /**
     * Valide les contraintes métier.
     *
     * @throws \InvalidArgumentException Si une contrainte est violée
     */
    private function validate(): void
    {
        if ($this->durationSec < 0) {
            throw new \InvalidArgumentException(
                "Duration must be >= 0, got {$this->durationSec}"
            );
        }

        if ($this->iocsTotal < 0) {
            throw new \InvalidArgumentException(
                "IOCs total must be >= 0, got {$this->iocsTotal}"
            );
        }

        if ($this->iocsSensibles < 0) {
            throw new \InvalidArgumentException(
                "IOCs sensibles must be >= 0, got {$this->iocsSensibles}"
            );
        }

        if ($this->iocsSensibles > $this->iocsTotal) {
            throw new \InvalidArgumentException(
                "IOCs sensibles ({$this->iocsSensibles}) cannot exceed IOCs total ({$this->iocsTotal})"
            );
        }
    }

    // Getters (readonly properties)

    public function getDurationSec(): int
    {
        return $this->durationSec;
    }

    public function getIocsTotal(): int
    {
        return $this->iocsTotal;
    }

    public function getIocsSensibles(): int
    {
        return $this->iocsSensibles;
    }

    public function isCompleted(): bool
    {
        return $this->isCompleted;
    }

    /**
     * Représentation textuelle pour debugging.
     */
    public function __toString(): string
    {
        $reward = $this->calculateReward();

        return sprintf(
            'ConversationMetrics(duration=%ds, iocs=%d/%d, completed=%s, reward=%.4f)',
            $this->durationSec,
            $this->iocsSensibles,
            $this->iocsTotal,
            $this->isCompleted ? 'yes' : 'no',
            $reward
        );
    }
}

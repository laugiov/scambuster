<?php

declare(strict_types=1);

namespace App\Application\Communication;

/**
 * Result of scam classification
 */
final class ClassificationResult
{
    /**
     * @param array{label_en: string, label_fr: string}|null $personaData New type labels (deprecated old persona data)
     * @param string[]|null $suggestedPersonaCodes List of suggested persona codes for new scam types
     */
    public function __construct(
        public readonly string $scamTypeCode,
        public readonly float $confidence,
        public readonly bool $isNewType,
        public readonly bool $isNewPersona,
        public readonly ?string $personaCode,
        public readonly string $reasoning,
        public readonly ?array $personaData = null,
        public readonly ?array $suggestedPersonaCodes = null
    ) {
    }

    /**
     * Check if classification should be applied (confidence threshold)
     */
    public function shouldApply(float $minConfidence = 0.75): bool
    {
        return $this->confidence >= $minConfidence;
    }

    /**
     * Get persona data if new persona was created (deprecated - use suggestedPersonaCodes)
     *
     * @return array{label_en: string, label_fr: string}|null
     */
    public function getPersonaData(): ?array
    {
        return $this->personaData;
    }

    /**
     * Get suggested persona codes for new scam type
     *
     * @return string[]|null
     */
    public function getSuggestedPersonaCodes(): ?array
    {
        return $this->suggestedPersonaCodes;
    }
}

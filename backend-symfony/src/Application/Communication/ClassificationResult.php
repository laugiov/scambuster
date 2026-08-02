<?php

declare(strict_types=1);

namespace App\Application\Communication;

/**
 * Result of scam classification
 */
final readonly class ClassificationResult
{
    /**
     * @param array{label_en?: string, label_fr?: string, persona_code?: string, persona_label?: string, persona_tone?: string, system_prompt?: string}|null $personaData           New type labels and optional persona data
     * @param string[]|null                                                                                                                                  $suggestedPersonaCodes List of suggested persona codes for new scam types
     * @param array<int, array{code: string, confidence: float}>|null                                                                                        $secondaryTypes        Secondary scam type classifications with confidence scores
     */
    public function __construct(
        public string $scamTypeCode,
        public float $confidence,
        public bool $isNewType,
        public bool $isNewPersona,
        public ?string $personaCode,
        public string $reasoning,
        public ?array $personaData = null,
        public ?array $suggestedPersonaCodes = null,
        public string $detectedLanguage = 'en',
        public ?array $secondaryTypes = null,
    ) {
    }

    /**
     * Check if classification should be applied (confidence threshold).
     *
     * Default threshold lowered from 0.75 to 0.55.
     */
    public function shouldApply(float $minConfidence = 0.55): bool
    {
        return $this->confidence >= $minConfidence;
    }

    /**
     * Get persona data if new persona was created (deprecated - use suggestedPersonaCodes)
     *
     * @return array{label_en?: string, label_fr?: string, persona_code?: string, persona_label?: string, persona_tone?: string, system_prompt?: string}|null
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

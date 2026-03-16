<?php

declare(strict_types=1);

namespace App\Domain\Communication;

/**
 * Value Object representing the result of a prompt injection forensic analysis on an inbound message.
 *
 * Two-layer detection:
 *   Layer 1 (PatternMatcher): deterministic regex pre-filter
 *   Layer 2 (LLM-as-Judge): semantic analysis via secondary LLM call
 */
final readonly class PromptInjectionAnalysis
{
    /**
     * @param float                                                                    $riskScore          Overall risk score [0.0, 1.0]
     * @param array<int, array{technique: string, evidence: string, severity: string}> $detectedTechniques List of detected techniques
     * @param float                                                                    $confidence         Analysis confidence [0.0, 1.0]
     * @param string                                                                   $summary            Brief human-readable explanation
     * @param array<int, string>                                                       $patternMatches     Layer 1 regex matches (may be empty)
     * @param string                                                                   $modelVersion       LLM model used for Layer 2 (empty if Layer 2 skipped)
     * @param \DateTimeImmutable                                                       $analyzedAt         Timestamp of analysis
     *
     * @throws \InvalidArgumentException If scores are out of [0.0, 1.0] range
     */
    public function __construct(
        private float $riskScore,
        private array $detectedTechniques,
        private float $confidence,
        private string $summary,
        private array $patternMatches,
        private string $modelVersion,
        private \DateTimeImmutable $analyzedAt,
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if ($this->riskScore < 0.0 || $this->riskScore > 1.0) {
            throw new \InvalidArgumentException(
                sprintf('Risk score must be in [0.0, 1.0], got %.4f', $this->riskScore)
            );
        }

        if ($this->confidence < 0.0 || $this->confidence > 1.0) {
            throw new \InvalidArgumentException(
                sprintf('Confidence must be in [0.0, 1.0], got %.4f', $this->confidence)
            );
        }
    }

    public function getRiskScore(): float
    {
        return $this->riskScore;
    }

    /** @return array<int, array{technique: string, evidence: string, severity: string}> */
    public function getDetectedTechniques(): array
    {
        return $this->detectedTechniques;
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    /** @return array<int, string> */
    public function getPatternMatches(): array
    {
        return $this->patternMatches;
    }

    public function getModelVersion(): string
    {
        return $this->modelVersion;
    }

    public function getAnalyzedAt(): \DateTimeImmutable
    {
        return $this->analyzedAt;
    }

    public function isHighRisk(): bool
    {
        return $this->riskScore >= 0.7;
    }

    /**
     * Serialize to array for JSON storage in message.injection_analysis column.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'risk_score' => $this->riskScore,
            'detected_techniques' => $this->detectedTechniques,
            'confidence' => $this->confidence,
            'summary' => $this->summary,
            'pattern_matches' => $this->patternMatches,
            'model_version' => $this->modelVersion,
            'analyzed_at' => $this->analyzedAt->format(\DATE_ATOM),
        ];
    }

    /**
     * Reconstruct from stored JSON array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var float $riskScore */
        $riskScore = $data['risk_score'] ?? 0.0;
        /** @var float $confidence */
        $confidence = $data['confidence'] ?? 0.0;
        /** @var string $summary */
        $summary = $data['summary'] ?? '';
        /** @var string $modelVersion */
        $modelVersion = $data['model_version'] ?? '';

        return new self(
            riskScore: (float) $riskScore,
            detectedTechniques: $data['detected_techniques'] ?? [],
            confidence: (float) $confidence,
            summary: (string) $summary,
            patternMatches: $data['pattern_matches'] ?? [],
            modelVersion: (string) $modelVersion,
            analyzedAt: new \DateTimeImmutable($data['analyzed_at'] ?? 'now'),
        );
    }
}

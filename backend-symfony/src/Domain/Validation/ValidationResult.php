<?php

declare(strict_types=1);

namespace App\Domain\Validation;

/**
 * Multi-criteria validation result for LLM-generated replies.
 *
 * Three quality dimensions scored 1-5:
 * - naturalness: Does this read like a human wrote it?
 * - persona_fit: Does this match the assigned persona's voice?
 * - ti_value: Does this advance threat intelligence collection?
 *
 * Plus a binary security gate (pass/fail).
 */
final class ValidationResult
{
    public function __construct(
        public readonly bool $approved,
        public readonly int $naturalness,
        public readonly int $personaFit,
        public readonly int $tiValue,
        public readonly bool $securityPass,
        public readonly string $feedback,
        /** @var array<string> */
        public readonly array $reasons = [],
        public readonly ?string $fixSuggestion = null,
    ) {
    }

    /**
     * Average quality score across the 3 dimensions.
     */
    public function averageQualityScore(): float
    {
        return round(($this->naturalness + $this->personaFit + $this->tiValue) / 3, 2);
    }

    /**
     * Parse from LLM JSON response.
     *
     * Expected JSON structure:
     * {
     *   "naturalness": 4, "naturalness_reasoning": "...",
     *   "persona_fit": 3, "persona_fit_reasoning": "...",
     *   "ti_value": 4, "ti_value_reasoning": "...",
     *   "security_pass": true, "security_reasoning": "...",
     *   "feedback": "...",
     *   "fix_suggestion": "..." (optional)
     * }
     *
     * @param array<string, mixed> $data Decoded JSON from LLM
     */
    public static function fromLLMResponse(array $data): self
    {
        $naturalness = self::clampScore($data['naturalness'] ?? 1);
        $personaFit = self::clampScore($data['persona_fit'] ?? 1);
        $tiValue = self::clampScore($data['ti_value'] ?? 1);
        $securityPass = (bool) ($data['security_pass'] ?? false);

        /** @var string $feedback */
        $feedback = $data['feedback'] ?? '';
        /** @var string|null $fixSuggestion */
        $fixSuggestion = isset($data['fix_suggestion']) && $data['fix_suggestion'] !== ''
            ? $data['fix_suggestion']
            : null;

        // Build reasons from chain-of-thought reasoning
        $reasons = [];

        foreach (['naturalness_reasoning', 'persona_fit_reasoning', 'ti_value_reasoning', 'security_reasoning'] as $key) {
            if (!empty($data[$key])) {
                /** @var string $reason */
                $reason = $data[$key];
                $reasons[] = $reason;
            }
        }

        // Compute verdict: reject if security fails, naturalness < 2, or avg < 2.5
        $avgScore = ($naturalness + $personaFit + $tiValue) / 3;
        $approved = $securityPass && $naturalness >= 2 && $avgScore >= 2.5;

        return new self(
            approved: $approved,
            naturalness: $naturalness,
            personaFit: $personaFit,
            tiValue: $tiValue,
            securityPass: $securityPass,
            feedback: $feedback,
            reasons: $reasons,
            fixSuggestion: $fixSuggestion,
        );
    }

    /**
     * Convert to legacy array format for backward compatibility.
     *
     * @return array{approved: bool, reasons: array<string>, fix_suggestion: string|null}
     */
    public function toLegacyArray(): array
    {
        return [
            'approved' => $this->approved,
            'reasons' => $this->reasons,
            'fix_suggestion' => $this->fixSuggestion,
        ];
    }

    /**
     * Clamp score to 1-5 range.
     */
    private static function clampScore(mixed $value): int
    {
        /** @var int $score */
        $score = $value;

        return max(1, min(5, (int) $score));
    }
}

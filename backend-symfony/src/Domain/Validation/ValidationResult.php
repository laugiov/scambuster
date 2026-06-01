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
final readonly class ValidationResult
{
    public function __construct(
        public bool $approved,
        public int $naturalness,
        public int $personaFit,
        public int $tiValue,
        public bool $securityPass,
        public string $feedback,
        /** @var array<string> */
        public array $reasons = [],
        public ?string $fixSuggestion = null,
        // Spec 080 §3 — structured correction (problem_span / replacement /
        // rationale) emitted by the validator when it can pinpoint a diff.
        // Null when the validator didn't emit it, or when fromLLMResponse
        // was called without a $generatedText argument (legacy callers).
        public ?StructuredCorrection $correction = null,
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
     * @param array<string, mixed> $data          Decoded JSON from LLM
     * @param string|null          $generatedText Original LLM output that was
     *                                            validated. When provided, an
     *                                            optional `correction` object
     *                                            in $data is parsed into a
     *                                            StructuredCorrection. When
     *                                            null (legacy 1-arg callers),
     *                                            correction is forced to null.
     */
    public static function fromLLMResponse(array $data, ?string $generatedText = null): self
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

        // Compute verdict: reject if security fails, naturalness < 2, avg < 2.5,
        // OR ti_value < 3 (passive/dead-end replies fail TI mission).
        // Spec 095 Fix #3 — see specs/095-pipeline-audit/fix-03-block-low-ti-value/spec.md
        $avgScore = ($naturalness + $personaFit + $tiValue) / 3;
        $approved = $securityPass && $naturalness >= 2 && $avgScore >= 2.5 && $tiValue >= 3;

        // Spec 080 §3 — parse optional structured correction.
        /** @var array<string, mixed>|null $correctionData */
        $correctionData = \is_array($data['correction'] ?? null) ? $data['correction'] : null;
        $correction = StructuredCorrection::fromLLMResponse($correctionData, $generatedText);

        return new self(
            approved: $approved,
            naturalness: $naturalness,
            personaFit: $personaFit,
            tiValue: $tiValue,
            securityPass: $securityPass,
            feedback: $feedback,
            reasons: $reasons,
            fixSuggestion: $fixSuggestion,
            correction: $correction,
        );
    }

    /**
     * Convert to legacy array format for backward compatibility.
     *
     * @return array{approved: bool, reasons: array<string>, fix_suggestion: string|null, correction: array{problem_span: string, replacement: string, rationale: string}|null}
     */
    public function toLegacyArray(): array
    {
        return [
            'approved' => $this->approved,
            'reasons' => $this->reasons,
            'fix_suggestion' => $this->fixSuggestion,
            'correction' => $this->correction?->toArray(),
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

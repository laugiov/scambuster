<?php

declare(strict_types=1);

namespace App\Domain\Validation;

/**
 * Spec 080 §3 — Structured correction returned by the ReplyValidator
 * when it rejects a draft and can express the fix as a precise diff.
 *
 * Three fields:
 *  - problemSpan: verbatim substring of the rejected text identifying
 *    the segment that must be replaced.
 *  - replacement: text that should replace problemSpan. Empty string
 *    is allowed (= deletion).
 *  - rationale: one-sentence justification, surfaced to the next
 *    generation attempt and in audit logs.
 *
 * Constructed via the static factory {@see fromLLMResponse()} which
 * validates the shape and ensures problemSpan is a substring of the
 * generated text.
 */
final readonly class StructuredCorrection
{
    public function __construct(
        public string $problemSpan,
        public string $replacement,
        public string $rationale,
    ) {
    }

    /**
     * Parse a `correction` object from the LLM validator's JSON response.
     *
     * Returns null when:
     *  - $data is null or not an array
     *  - any of problem_span / replacement / rationale is missing or not a string
     *  - $generatedText is null (legacy 2-arg ValidationResult calls)
     *  - problem_span is not a substring of $generatedText (LLM hallucination guard)
     *
     * @param array<string, mixed>|null $data
     */
    public static function fromLLMResponse(?array $data, ?string $generatedText): ?self
    {
        if ($data === null || $generatedText === null) {
            return null;
        }

        $problemSpan = $data['problem_span'] ?? null;
        $replacement = $data['replacement'] ?? null;
        $rationale = $data['rationale'] ?? null;

        if (!\is_string($problemSpan) || !\is_string($replacement) || !\is_string($rationale)) {
            return null;
        }

        // Hallucination guard: the LLM may emit a problem_span that is NOT
        // a verbatim substring of the text it was asked to validate. Such
        // a correction is unusable for patch-mode retries, so we discard.
        if ($problemSpan === '' || !str_contains($generatedText, $problemSpan)) {
            return null;
        }

        return new self($problemSpan, $replacement, $rationale);
    }

    /**
     * @return array{problem_span: string, replacement: string, rationale: string}
     */
    public function toArray(): array
    {
        return [
            'problem_span' => $this->problemSpan,
            'replacement' => $this->replacement,
            'rationale' => $this->rationale,
        ];
    }
}

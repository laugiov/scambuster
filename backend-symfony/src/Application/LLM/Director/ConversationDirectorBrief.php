<?php

declare(strict_types=1);

namespace App\Application\LLM\Director;

/**
 * A reasoned, LLM-produced strategic brief for the current turn of a scam-baiting
 * conversation. It replaces the previous code heuristics (regex question extraction,
 * static scam-type OBJECTIVE table) with a single judgement made by the conversation
 * director (see {@see \App\Application\LLM\ConversationAnalyzer}).
 *
 * Every field degrades safely: a malformed or missing brief yields {@see self::default()},
 * which preserves the pipeline's prior behaviour rather than blocking replies.
 */
final readonly class ConversationDirectorBrief
{
    /**
     * @param list<string> $alreadyObtained intel categories the mark has already given
     *                                      (semantic, LLM-judged) — the persona must not
     *                                      ask for any of these again
     */
    public function __construct(
        public array $alreadyObtained,
        public MarkState $markState,
        public string $objective,
        public Progress $progress,
        public string $nextMove,
        public string $styleDirective,
        public bool $shouldContinue,
        public string $stopReason,
    ) {
    }

    /**
     * Safe default used when the analyzer is unavailable or its output is unusable:
     * keep going, nothing obtained yet, no override of the fallback objective.
     */
    public static function default(): self
    {
        return new self([], MarkState::COOPERATIVE, '', Progress::ADVANCING, '', '', true, '');
    }

    /**
     * Build from the decoded `director` object of the analyzer's JSON response.
     * Unknown/malformed fields fall back to their safe defaults.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $obtained = is_array($data['already_obtained'] ?? null)
            ? array_values(array_filter($data['already_obtained'], 'is_string'))
            : [];

        return new self(
            $obtained,
            MarkState::fromLoose(is_string($data['mark_state'] ?? null) ? $data['mark_state'] : null),
            is_string($data['objective'] ?? null) ? trim($data['objective']) : '',
            Progress::fromLoose(is_string($data['progress'] ?? null) ? $data['progress'] : null),
            is_string($data['next_move'] ?? null) ? trim($data['next_move']) : '',
            is_string($data['style_directive'] ?? null) ? trim($data['style_directive']) : '',
            array_key_exists('should_continue', $data) ? (bool) $data['should_continue'] : true,
            is_string($data['stop_reason'] ?? null) ? trim($data['stop_reason']) : '',
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Communication;

use Psr\Log\LoggerInterface;

/**
 * Layer 1: Deterministic pattern-based pre-filter for prompt injection detection.
 *
 * Screens inbound messages for known injection signatures using regex patterns.
 * Fast (<1ms), zero cost, catches trivially identifiable attacks.
 * Complements the LLM-as-judge (Layer 2) for novel/subtle techniques.
 */
final class PromptInjectionPatternMatcher
{
    /** @var array<string, string> Pattern name => regex */
    private const INSTRUCTION_OVERRIDE_PATTERNS = [
        'ignore_previous' => '/\bignore\s+(?:all\s+)?(?:previous|prior|above|earlier)\s+(?:instructions?|prompts?|rules?|guidelines?)\b/i',
        'disregard_instructions' => '/\bdisregard\s+(?:all\s+)?(?:previous|prior|above|earlier)?\s*(?:instructions?|prompts?|rules?)\b/i',
        'forget_instructions' => '/\bforget\s+(?:all\s+)?(?:previous|prior|your)\s+(?:instructions?|prompts?|rules?)\b/i',
        'new_instructions' => '/\b(?:new|updated|revised)\s+instructions?\s*:/i',
        'override_system' => '/\b(?:override|overwrite|replace)\s+(?:system|your)\s+(?:prompt|instructions?|rules?)\b/i',
    ];

    /** @var array<string, string> */
    private const ROLE_MANIPULATION_PATTERNS = [
        'you_are_now' => '/\byou\s+are\s+now\s+(?:a|an|the|my)\b/i',
        'act_as' => '/\bact\s+as\s+(?:a|an|the|my|if)\b/i',
        'pretend_to_be' => '/\bpretend\s+(?:to\s+be|you\s+are)\b/i',
        'roleplay_as' => '/\b(?:roleplay|role-play|role\s+play)\s+as\b/i',
        'dan_jailbreak' => '/\b(?:DAN|Do\s+Anything\s+Now)\b/',
        'developer_mode' => '/\b(?:developer|dev)\s+mode\s+(?:enabled|on|activated)\b/i',
    ];

    /** @var array<string, string> */
    private const PROMPT_EXTRACTION_PATTERNS = [
        'repeat_instructions' => '/\b(?:repeat|show|display|print|output|reveal)\s+(?:your|the|system)\s+(?:instructions?|prompt|rules?|guidelines?)\b/i',
        'what_instructions' => '/\bwhat\s+(?:are|were)\s+your\s+(?:instructions?|prompt|rules?|guidelines?)\b/i',
        'system_prompt' => '/\bsystem\s+prompt\b/i',
        'initial_prompt' => '/\b(?:initial|original|first)\s+prompt\b/i',
    ];

    /** @var array<string, string> */
    private const DELIMITER_PATTERNS = [
        'markdown_delimiter' => '/^```(?:system|prompt|instructions?)/mi',
        'inst_tag' => '/\[INST\]/i',
        'im_start_tag' => '/<\|im_start\|>/i',
        'system_tag' => '/<\|system\|>/i',
        'hash_delimiter' => '/^#{3,}\s*(?:system|prompt|instructions?|new\s+task)/mi',
        'xml_system_tag' => '/<system>/i',
    ];

    /** @var array<string, string> */
    private const ENCODING_PATTERNS = [
        'base64_instruction' => '/(?:aWdub3Jl|SWdub3Jl|ZGlzcmVnYXJk|Zm9yZ2V0)/', // base64 of ignore/Ignore/disregard/forget
        'zero_width_chars' => '/[\x{200B}\x{200C}\x{200D}\x{FEFF}]{3,}/u',
        'unicode_escape' => '/\\\\u00[0-9a-fA-F]{2}(?:\\\\u00[0-9a-fA-F]{2}){3,}/i',
    ];

    /** @var array<string, string> */
    private const JAILBREAK_PATTERNS = [
        'jailbreak_keyword' => '/\bjailbreak\b/i',
        'bypass_filter' => '/\bbypass\s+(?:the\s+)?(?:filter|safety|restriction|guardrail|content\s+policy)\b/i',
        'unrestricted_mode' => '/\bunrestricted\s+(?:mode|ai|version)\b/i',
        'no_restrictions' => '/\b(?:without|no|remove)\s+(?:any\s+)?restrictions?\b/i',
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Scan text for known prompt injection patterns.
     *
     * @return array{matches: array<string>, score: float}
     */
    public function scan(string $text): array
    {
        $matches = [];

        $patternGroups = [
            'instruction_override' => self::INSTRUCTION_OVERRIDE_PATTERNS,
            'role_manipulation' => self::ROLE_MANIPULATION_PATTERNS,
            'prompt_extraction' => self::PROMPT_EXTRACTION_PATTERNS,
            'delimiter_injection' => self::DELIMITER_PATTERNS,
            'encoding_obfuscation' => self::ENCODING_PATTERNS,
            'jailbreak' => self::JAILBREAK_PATTERNS,
        ];

        foreach ($patternGroups as $groupName => $patterns) {
            foreach ($patterns as $patternName => $regex) {
                if (preg_match($regex, $text, $match)) {
                    $matchLabel = "{$groupName}:{$patternName}";
                    $matches[] = $matchLabel;

                    $this->logger->debug('[PromptInjectionPatternMatcher] Pattern matched', [
                        'pattern' => $matchLabel,
                        'matched_text' => mb_substr($match[0], 0, 100),
                    ]);
                }
            }
        }

        $score = $this->calculateScore($matches);

        if (count($matches) > 0) {
            $this->logger->info('[PromptInjectionPatternMatcher] Injection patterns detected', [
                'matches_count' => count($matches),
                'score' => $score,
                'matches' => $matches,
            ]);
        }

        return [
            'matches' => $matches,
            'score' => $score,
        ];
    }

    /**
     * Calculate a preliminary risk score based on the number and type of pattern matches.
     *
     * @param array<string> $matches
     */
    private function calculateScore(array $matches): float
    {
        if (count($matches) === 0) {
            return 0.0;
        }

        // High-weight categories (direct attack indicators)
        $highWeight = ['instruction_override', 'prompt_extraction', 'jailbreak'];
        // Medium-weight categories
        $mediumWeight = ['role_manipulation', 'delimiter_injection'];
        // Low-weight categories (supplementary signals)
        $lowWeight = ['encoding_obfuscation'];

        $score = 0.0;

        foreach ($matches as $match) {
            $group = explode(':', $match)[0];

            if (in_array($group, $highWeight, true)) {
                $score += 0.4;
            } elseif (in_array($group, $mediumWeight, true)) {
                $score += 0.25;
            } elseif (in_array($group, $lowWeight, true)) {
                $score += 0.15;
            }
        }

        return min(1.0, $score);
    }
}

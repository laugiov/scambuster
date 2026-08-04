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
final readonly class PromptInjectionPatternMatcher
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
        // A single invisible character wedged between two alphanumerics is a
        // classic word-splitting evasion ("igno<ZWJ>re previous instructions").
        // Restricting the surrounding chars to letters/digits avoids matching
        // legitimate emoji ZWJ sequences (emoji are \p{So}, not \p{L}/\p{N}).
        'zero_width_in_word' => '/[\p{L}\p{N}][\x{200B}\x{200C}\x{200D}\x{FEFF}\x{2060}\x{00AD}][\p{L}\p{N}]/u',
        'unicode_escape' => '/\\\\u00[0-9a-fA-F]{2}(?:\\\\u00[0-9a-fA-F]{2}){3,}/i',
    ];

    /**
     * Upper bound on the number of bytes fed to the regex engine.
     *
     * scan() runs P patterns over the whole text (O(P*N)); an unbounded N lets a
     * caller turn a single large message into sustained CPU pressure. Messages
     * this long are anomalous for a honeypot conversation, so we truncate and
     * flag rather than reject (best-effort detection remains).
     */
    private const MAX_SCAN_BYTES = 1_048_576; // 1 MB

    /**
     * Zero-width / bidirectional / invisible formatting characters stripped
     * before homoglyph folding so split words collapse back together.
     */
    private const INVISIBLE_CHARS = '/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{2060}\x{00AD}\x{180E}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /** @var array<string, string> */
    private const JAILBREAK_PATTERNS = [
        'jailbreak_keyword' => '/\bjailbreak\b/i',
        'bypass_filter' => '/\bbypass\s+(?:the\s+)?(?:filter|safety|restriction|guardrail|content\s+policy)\b/i',
        'unrestricted_mode' => '/\bunrestricted\s+(?:mode|ai|version)\b/i',
        'no_restrictions' => '/\b(?:without|no|remove)\s+(?:any\s+)?restrictions?\b/i',
    ];

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Scan text for known prompt injection patterns.
     *
     * @return array{matches: array<string>, score: float}
     */
    public function scan(string $text): array
    {
        // DoS guard: bound the amount of text the regex engine ever sees.
        if (strlen($text) > self::MAX_SCAN_BYTES) {
            $this->logger->warning('[PromptInjectionPatternMatcher] Input exceeds scan cap, truncating', [
                'original_bytes' => strlen($text),
                'cap_bytes' => self::MAX_SCAN_BYTES,
            ]);

            // mb_strcut keeps the cut on a UTF-8 boundary so the truncated tail
            // is not a broken multibyte sequence that could break /u patterns.
            $text = mb_strcut($text, 0, self::MAX_SCAN_BYTES, 'UTF-8');
        }

        $matches = $this->matchPatterns($text);

        // Homoglyph / zero-width evasion: a Cyrillic "і" or an embedded joiner
        // sails past ASCII regexes. Re-scan a normalized ASCII skeleton and union
        // the results, so obfuscated variants are caught without weakening the
        // literal-match pass. Pure-ASCII text cannot hide such tricks, so the
        // (relatively expensive) normalization pass is skipped for it.
        if ($this->hasNonAscii($text)) {
            $normalized = $this->normalizeForMatching($text);

            if ($normalized !== $text) {
                foreach ($this->matchPatterns($normalized) as $label) {
                    if (!in_array($label, $matches, true)) {
                        $matches[] = $label;
                    }
                }
            }
        }

        $score = $this->calculateScore($matches);

        if ($matches !== []) {
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
     * Run every pattern group over one text and return the matched labels.
     *
     * @return array<int, string> Labels in "group:pattern" form
     */
    private function matchPatterns(string $text): array
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

        return $matches;
    }

    /**
     * Whether the text contains any non-ASCII byte. Pure-ASCII text cannot
     * carry homoglyphs or invisible characters, so it needs no normalization.
     */
    private function hasNonAscii(string $text): bool
    {
        return (bool) preg_match('/[^\x00-\x7F]/', $text);
    }

    /**
     * Produce an ASCII "skeleton" of the text for confusable-aware matching:
     * strip invisible formatting characters, apply NFKC, then fold homoglyphs
     * to their Latin/ASCII equivalents. Runs on a throwaway copy — the original
     * message is never mutated.
     */
    private function normalizeForMatching(string $text): string
    {
        // 1. Drop zero-width / bidi / invisible characters that split words.
        $stripped = preg_replace(self::INVISIBLE_CHARS, '', $text);

        if (is_string($stripped)) {
            $text = $stripped;
        }

        // 2. NFKC compatibility normalization (full-width forms, ligatures, ...).
        if (class_exists(\Normalizer::class)) {
            $nfkc = \Normalizer::normalize($text, \Normalizer::FORM_KC);

            if (is_string($nfkc)) {
                $text = $nfkc;
            }
        }

        // 3. Fold homoglyphs / confusables onto an ASCII skeleton so Cyrillic /
        //    Greek look-alikes ("іgnore", "reveаl") collapse to their Latin form.
        $translit = $this->confusableTransliterator();

        if ($translit !== null) {
            $ascii = $translit->transliterate($text);

            if (is_string($ascii)) {
                $text = $ascii;
            }
        }

        return $text;
    }

    /**
     * Lazily build (and cache) the confusable-folding transliterator. Returns
     * null when the intl extension is unavailable, so detection degrades to the
     * literal pass instead of failing.
     */
    private function confusableTransliterator(): ?\Transliterator
    {
        static $resolved = false;
        static $translit = null;

        if (!$resolved) {
            $resolved = true;
            $translit = class_exists(\Transliterator::class)
                ? \Transliterator::create('Any-Latin; Latin-ASCII')
                : null;
        }

        return $translit;
    }

    /**
     * Calculate a preliminary risk score based on the number and type of pattern matches.
     *
     * @param array<string> $matches
     */
    private function calculateScore(array $matches): float
    {
        if ($matches === []) {
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

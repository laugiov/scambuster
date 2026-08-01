<?php

declare(strict_types=1);

namespace App\Application\LLM;

/**
 * The single pipeline-wide definition of "how many words does this
 * reply have": Unicode whitespace tokenization.
 *
 * The generator is instructed with "Target length: N-M words" and LLMs
 * (like humans) count whitespace-separated tokens. Enforcement and
 * instrumentation must count the same way, or replies the generator
 * believes are compliant get rejected as too short. Production shipped
 * fallback placeholders on French replies because str_word_count
 * ignores digit tokens and uppercase accented initials, undercounting
 * by 2-4 words right at the floor.
 *
 * Digits, elisions, hyphenated compounds and any non-Latin script all
 * count as the tokens a reader would count; the rule degrades to plain
 * whitespace splitting on scripts it has never seen.
 */
final class WordCounter
{
    public static function count(string $text): int
    {
        $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? 0 : \count($tokens);
    }
}

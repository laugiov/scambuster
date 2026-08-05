<?php

declare(strict_types=1);

namespace App\Application\Stix;

/**
 * Single source of truth for escaping a value into a STIX 2.1 pattern string
 * literal.
 *
 * STIX 2.1 comparison patterns use single-quoted string literals in which the
 * backslash is the escape character (STIX 2.1 §9, ABNF `StringLiteral`). Four
 * emitters previously carried their own `str_replace("'", "\\'", $value)`, which
 * escaped the quote but left the backslash untouched — so a value ending in `\`
 * escaped the closing quote and corrupted the pattern. Escaping order matters:
 * the backslash must be doubled BEFORE the quote is escaped, otherwise the
 * backslash added in front of the quote would itself be doubled.
 */
final class StixPatternValue
{
    /**
     * Escape a raw value for inclusion between the single quotes of a STIX
     * pattern string literal. The caller supplies the surrounding quotes:
     * `"[domain-name:value = '" . StixPatternValue::escape($v) . "']"`.
     */
    public static function escape(string $value): string
    {
        // Order is load-bearing: `\` → `\\` first, then `'` → `\'`. str_replace
        // applies the pairs left to right on the running result, and never
        // re-scans a replacement it just wrote, so the backslash placed in front
        // of a quote is not itself doubled.
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}

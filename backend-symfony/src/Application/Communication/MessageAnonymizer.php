<?php

declare(strict_types=1);

namespace App\Application\Communication;

/**
 * Anonymizes PII in message text before sending to LLM.
 *
 * Replaces emails, IBANs, phone numbers, and crypto wallets with placeholders.
 * URLs are kept as-is because they are the IOCs the LLM needs to analyze.
 */
final class MessageAnonymizer
{
    /**
     * PII patterns and their replacement placeholders.
     *
     * Order matters: more specific patterns must come before more general ones.
     * URLs are intentionally NOT included (they are IOCs needed by the LLM).
     *
     * @var array<string, string>
     */
    private const PII_PATTERNS = [
        // Email (before phone/IBAN to avoid partial matches)
        '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '[EMAIL]',

        // IBAN (common European formats)
        '/\b[A-Z]{2}[0-9]{2}[A-Z0-9]{1,30}\b/' => '[IBAN]',

        // Crypto wallets - BTC (bc1, 1..., 3...)
        '/\b(bc1|[13])[a-zA-HJ-NP-Z0-9]{25,62}\b/' => '[WALLET]',

        // Crypto wallets - ETH (0x...)
        '/\b0x[a-fA-F0-9]{40}\b/' => '[WALLET]',

        // Phone (international and French formats)
        '/\b(?:\+?\d{1,3}[-.\s]?)?\(?\d{2,4}\)?[-.\s]?\d{2,4}[-.\s]?\d{2,4}(?:[-.\s]?\d{2,4})?\b/' => '[PHONE]',
    ];

    /**
     * Replace PII in text with placeholders.
     *
     * URLs are preserved because they are IOCs needed for LLM analysis.
     */
    public function anonymize(string $text): string
    {
        foreach (self::PII_PATTERNS as $pattern => $replacement) {
            $result = preg_replace($pattern, $replacement, $text);

            if ($result !== null) {
                $text = $result;
            }
        }

        return $text;
    }

    /**
     * Check if text contains any PII patterns.
     *
     * Used for post-validation of LLM output (e.g., context_excerpt).
     */
    public function containsPii(string $text): bool
    {
        foreach (self::PII_PATTERNS as $pattern => $_) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}

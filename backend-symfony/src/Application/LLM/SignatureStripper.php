<?php

declare(strict_types=1);

namespace App\Application\LLM;

use App\Application\Audit\AuditLogger;
use Psr\Log\LoggerInterface;

/**
 * Spec 080 — deterministic signature/signoff stripper.
 *
 * Removes trailing signature blocks from LLM-generated reply text. Runs
 * immediately after the generator and before {@see PolicyGuard} so the text
 * persisted in the database is the same as the text sent over SMTP.
 *
 * Three pattern classes are matched (case-insensitive, multi-line aware):
 *   1. Standalone-line signoff words in 7 languages (EN/FR/ES/DE/IT/PT/NL).
 *      The signoff line plus all lines after it are removed (the signature
 *      block, name, title, phone, etc.).
 *   2. Standalone-line bracketed placeholders like `[Your Name]`.
 *   3. RFC 3676 §4.3 separator (`--` on its own line) and everything after.
 *
 * The stripper does NOT match signoff words inline mid-sentence (e.g.
 * "Best regards, please send the IBAN" stays intact). Edge cases not
 * covered by these regexes (informal English, typos, mid-body name drops)
 * are delegated to the LLM-based validator coherence check (spec 080 §2).
 *
 * Gated by the `REPLY_SIGNATURE_STRIP_ENABLED` env var (bound via
 * `$signatureStripEnabled` autowire scalar). When disabled, returns the
 * input unchanged.
 *
 * @see specs/080-validator-coherence-and-signature-strip/spec.md §1
 */
final readonly class SignatureStripper
{
    /**
     * Multilingual signoff words / phrases (EN + FR + ES + DE + IT + PT + NL).
     * Ordered LONGEST-FIRST so the regex alternation prefers the most
     * specific match (e.g. "Best regards" over "Best" when both candidate,
     * "Com os melhores cumprimentos" over "Cumprimentos").
     *
     * @var list<string>
     */
    private const SIGNOFFS = [
        // --- Portuguese (longest first) ---
        'Com os melhores cumprimentos',
        // --- German (umlauts via /u flag) ---
        'Mit freundlichen Grüßen',
        'Freundliche Grüße',
        'Beste Grüße',
        'Viele Grüße',
        // --- English (18) ---
        'Yours faithfully',
        'Yours sincerely',
        'Yours truly',
        'Best regards',
        'Best wishes',
        'Kind regards',
        'Warm regards',
        'Warm wishes',
        'Many thanks',
        'All the best',
        'Thank you',
        'Sincerely',
        'Cordially',
        'Regards',
        'Warmly',
        'Cheers',
        'Thanks',
        'Best',
        // --- French ---
        'Bien cordialement',
        'Cordialement',
        'Bonne journée',
        'Bien à vous',
        'Salutations',
        // --- Spanish ---
        'Cordialmente',
        'Atentamente',
        'Un saludo',
        'Saludos',
        // --- Italian ---
        'Cordiali saluti',
        'Distinti saluti',
        'Saluti',
        // --- Portuguese (shorter) ---
        'Atenciosamente',
        'Cumprimentos',
        // --- Dutch ---
        'Met vriendelijke groet',
        'Vriendelijke groet',
        'Groeten',
    ];

    public function __construct(
        private bool $signatureStripEnabled,
        private LoggerInterface $logger,
        private AuditLogger $auditLogger,
    ) {
    }

    public function strip(string $text, string $convId): StripResult
    {
        $matched = [];
        $stripped = $text;

        // Pattern 1 — multilingual signoff block: a standalone line whose
        // first token is one of the documented signoff phrases (EN/FR/ES/
        // DE/IT/PT/NL), followed by optional punctuation, end of line, and
        // everything that follows (the signature block — name, title, etc.).
        //
        // Flags:
        //   s — DOTALL, so `.*` consumes newlines (the signature block)
        //   u — UTF-8 awareness (mandatory for German umlauts, French é, etc.)
        //   i — case-insensitive (LLM casing can vary)
        $alternation = implode('|', self::SIGNOFFS);
        $patternSignoff = '/\n+(?:' . $alternation . ')[,!.]?\s*\n.*$/sui';

        $afterSignoff = preg_replace($patternSignoff, "\n", $stripped);

        if (\is_string($afterSignoff) && $afterSignoff !== $stripped) {
            $matched[] = 'signoff_multilingual';
            $stripped = $afterSignoff;
        }

        // Pattern 2 — standalone bracketed placeholders: a trailing block of
        // one or more lines, each of the shape `[Word ...]` (LLM template
        // leak markers like `[Your Name]`, `[Company Name]`, `[Date]`).
        //
        // Match anchored at end-of-string so we only strip when the block
        // is at the very end of the text (the signature position). A mid-body
        // bracketed word is intentionally left alone.
        $patternBracketed = '/\n+\[[A-Za-z][A-Za-z ]+\]\s*(?:\n\s*\[[A-Za-z][A-Za-z ]+\]\s*)*$/u';

        $afterBracketed = preg_replace($patternBracketed, "\n", $stripped);

        if (\is_string($afterBracketed) && $afterBracketed !== $stripped) {
            $matched[] = 'bracketed_placeholder';
            $stripped = $afterBracketed;
        }

        // Pattern 3 — RFC 3676 §4.3 signature separator: a line containing
        // only "--" or "--" + space (the canonical form) marks the start of
        // the signature. We also accept "---" (3+ dashes) for LLM output
        // variability. Strip the separator line and everything after.
        //
        // `[ ]*` (not `\s*`) before the newline so we don't eat the newline
        // itself — we want \n to act as the line terminator.
        $patternRfc3676 = '/\n+-{2,}[ ]*\n.*$/s';

        $afterRfc3676 = preg_replace($patternRfc3676, "\n", $stripped);

        if (\is_string($afterRfc3676) && $afterRfc3676 !== $stripped) {
            $matched[] = 'rfc3676_separator';
            $stripped = $afterRfc3676;
        }

        $bytesRemoved = \strlen($text) - \strlen($stripped);

        if ($bytesRemoved > 0) {
            $this->logger->info('[SignatureStripper] strip', [
                'conv_id' => $convId,
                'enabled' => $this->signatureStripEnabled,
                'bytes_removed' => $bytesRemoved,
                'patterns' => $matched,
                'audit_target' => $this->auditLogger::class,
            ]);
        }

        return new StripResult(
            textAfter: $stripped,
            bytesRemoved: $bytesRemoved,
            matchedPatterns: $matched,
        );
    }
}

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
     * English signoff words / phrases. Ordered LONGEST-FIRST so the regex
     * alternation prefers the most specific match (e.g. "Best regards"
     * over "Best" when both are candidates).
     *
     * @var list<string>
     */
    private const ENGLISH_SIGNOFFS = [
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

        // Pattern 1 — English signoff block: a standalone line whose first
        // token is one of the documented signoff words, followed by an
        // optional punctuation (comma/exclamation/period), end of line, and
        // everything that follows (the signature block).
        //
        // Flags:
        //   s — DOTALL, so `.*` consumes newlines (the signature block)
        //   u — UTF-8 awareness (no-op for pure ASCII English, mandatory
        //       once we add multilingual variants in Green #2b)
        //   i — case-insensitive (LLM casing can vary)
        $alternation = implode('|', self::ENGLISH_SIGNOFFS);
        $patternEn = '/\n+(?:' . $alternation . ')[,!.]?\s*\n.*$/sui';

        $afterEn = preg_replace($patternEn, "\n", $stripped);

        if (\is_string($afterEn) && $afterEn !== $stripped) {
            $matched[] = 'signoff_en';
            $stripped = $afterEn;
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

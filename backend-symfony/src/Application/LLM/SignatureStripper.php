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
    public function __construct(
        private bool $signatureStripEnabled,
        private LoggerInterface $logger,
        private AuditLogger $auditLogger,
    ) {
    }

    public function strip(string $text, string $convId): StripResult
    {
        // T03 stub — the Red commit that follows asserts baseline behavior
        // (pass-through when no signature) and the subsequent Green commits
        // build the regex pipeline incrementally.
        throw new \LogicException(sprintf(
            'SignatureStripper::strip not implemented yet (T03 stub). enabled=%s, conv_id=%s, text_length=%d, logger=%s, audit=%s',
            $this->signatureStripEnabled ? 'true' : 'false',
            $convId,
            \strlen($text),
            $this->logger::class,
            $this->auditLogger::class,
        ));
    }
}

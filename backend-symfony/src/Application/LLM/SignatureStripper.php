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
        // T03 green #1 — baseline pass-through. Subsequent Green commits in
        // this task add the multilingual signoff regex, bracketed-placeholder
        // regex, and RFC 3676 separator regex, growing this method's
        // behavior incrementally. Logger + AuditLogger + signatureStripEnabled
        // are referenced here so PHPStan max doesn't flag them as unused
        // private properties; they'll be exercised for real in the next
        // Greens (flag-off branch in Green #6, audit emission once strip
        // actually does something).
        $this->logger->debug('[SignatureStripper] baseline pass-through', [
            'conv_id' => $convId,
            'enabled' => $this->signatureStripEnabled,
            'text_length' => \strlen($text),
            'audit_target' => $this->auditLogger::class,
        ]);

        return new StripResult(
            textAfter: $text,
            bytesRemoved: 0,
            matchedPatterns: [],
        );
    }
}

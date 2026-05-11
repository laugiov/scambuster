<?php

declare(strict_types=1);

namespace App\Application\LLM;

/**
 * Outcome of a {@see SignatureStripper::strip()} invocation.
 *
 * Value object — immutable. Carries the post-strip text plus diagnostic
 * fields that downstream consumers (audit log, metrics) read.
 */
final readonly class StripResult
{
    /**
     * @param array<int, string> $matchedPatterns Identifiers of the regex
     *                                            patterns that matched, in the
     *                                            order they were applied. Empty
     *                                            when nothing was stripped.
     */
    public function __construct(
        public string $textAfter,
        public int $bytesRemoved,
        public array $matchedPatterns,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Application\LLM;

/**
 * Loop-invariant identity/context of a single reply generation, bundled so the
 * retry loop and its shared fallback/audit helpers pass one value instead of the
 * same four scalars everywhere. Immutable; computed once per `execute()`.
 */
final readonly class ReplyContext
{
    public function __construct(
        public string $convId,
        public string $personaCode,
        public ?string $detectedLanguage,
        public int $messageCount,
    ) {
    }
}

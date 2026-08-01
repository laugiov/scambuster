<?php

declare(strict_types=1);

namespace App\Application\LLM\Director;

/**
 * The mark's (correspondent's) reasoned state of mind at this point in the exchange,
 * as judged by the conversation director. Drives whether the persona keeps pressing,
 * changes tack, or the pipeline stops replying.
 */
enum MarkState: string
{
    case COOPERATIVE = 'cooperative';
    case STALLING = 'stalling';
    case SUSPICIOUS = 'suspicious';
    case ANTI_BOT_CHALLENGE = 'anti_bot_challenge';
    case HOSTILE = 'hostile';
    case DISENGAGED = 'disengaged';

    /**
     * Parse a loose LLM-supplied value, defaulting to COOPERATIVE so a malformed
     * brief never blocks reply generation.
     */
    public static function fromLoose(?string $value): self
    {
        if ($value === null) {
            return self::COOPERATIVE;
        }

        return self::tryFrom(strtolower(trim($value))) ?? self::COOPERATIVE;
    }
}

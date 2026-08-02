<?php

declare(strict_types=1);

namespace App\Application\LLM\Director;

/**
 * Whether the exchange is moving toward the extraction objective, as judged by the
 * conversation director.
 */
enum Progress: string
{
    case ADVANCING = 'advancing';
    case STALLED = 'stalled';
    case REGRESSING = 'regressing';

    /**
     * Parse a loose LLM-supplied value, defaulting to ADVANCING so a malformed brief
     * never blocks reply generation.
     */
    public static function fromLoose(?string $value): self
    {
        if ($value === null) {
            return self::ADVANCING;
        }

        return self::tryFrom(strtolower(trim($value))) ?? self::ADVANCING;
    }
}

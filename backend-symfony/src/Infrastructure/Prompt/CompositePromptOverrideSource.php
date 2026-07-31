<?php

declare(strict_types=1);

namespace App\Infrastructure\Prompt;

use App\Application\LLM\Prompt\PromptOverrideSource;

/**
 * Composes several {@see PromptOverrideSource}s into one, returning the FIRST non-null body.
 * Ordered head-to-tail: the ephemeral candidate holder (empty except during a validation run)
 * sits ahead of the database source, so validating an unsaved candidate wins over the saved
 * override for that key without persisting anything. With the ephemeral holder empty — every
 * normal process — resolution is exactly the database source, so live behaviour is unchanged.
 *
 * Fail-safe like every source: it never throws (each delegate is itself fail-safe).
 */
final readonly class CompositePromptOverrideSource implements PromptOverrideSource
{
    /**
     * @param iterable<PromptOverrideSource> $sources ordered; the first non-null body wins
     */
    public function __construct(
        private iterable $sources,
    ) {
    }

    public function get(string $key): ?string
    {
        foreach ($this->sources as $source) {
            try {
                $body = $source->get($key);
            } catch (\Throwable) {
                // A source is contractually fail-safe; if one breaks its contract, treat it as
                // absent and fall through so the composite honours its own "never throws".
                continue;
            }

            if ($body !== null) {
                return $body;
            }
        }

        return null;
    }
}

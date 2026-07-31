<?php

declare(strict_types=1);

namespace App\Application\Prompt;

use App\Application\LLM\Prompt\PromptCatalog;
use App\Application\Prompt\Exception\InvalidPromptOverrideException;
use App\Application\Prompt\Exception\UnknownPromptKeyException;

/**
 * The shared business rule for a prompt body — the key must be in the catalog, and the body
 * must be non-empty, within length, and keep every required placeholder for its key (the same
 * contract the runtime enforces). Used both when saving an override and when validating an
 * UNSAVED candidate for a canary run, so the two can never diverge.
 */
final readonly class PromptBodyValidator
{
    /**
     * Generous upper bound on a prompt body — well above any legitimate prompt, but a guard
     * against an accidental or malicious multi-megabyte body (LLM cost / DB bloat).
     */
    public const MAX_BODY_LENGTH = 20000;

    /**
     * @throws UnknownPromptKeyException      unknown key
     * @throws InvalidPromptOverrideException empty body, too long, or missing a required placeholder
     */
    public function validate(string $key, string $body): void
    {
        if (!PromptCatalog::isKnown($key)) {
            throw new UnknownPromptKeyException($key);
        }

        if (trim($body) === '') {
            throw new InvalidPromptOverrideException('Override body must not be empty.');
        }

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new InvalidPromptOverrideException(sprintf('Override body must be at most %d characters.', self::MAX_BODY_LENGTH));
        }

        $missing = [];

        foreach (PromptCatalog::requiredPlaceholders($key) as $placeholder) {
            if (!str_contains($body, $placeholder)) {
                $missing[] = $placeholder;
            }
        }

        if ($missing !== []) {
            throw new InvalidPromptOverrideException('Override is missing required placeholders: ' . implode(', ', $missing));
        }
    }
}

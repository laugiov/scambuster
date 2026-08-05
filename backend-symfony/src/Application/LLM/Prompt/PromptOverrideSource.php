<?php

declare(strict_types=1);

namespace App\Application\LLM\Prompt;

/**
 * Port for an operator-managed prompt-override store (e.g. the admin UI, backed by
 * the database). Resolved by {@see PromptProvider} ahead of the on-disk file and the
 * shipped inline default.
 *
 * An implementation returns the raw override body for a key when an ENABLED override
 * exists, or null otherwise. It must never throw — resolution is fail-safe, so any
 * backend error is treated as "no override" and falls through to the file/default.
 */
interface PromptOverrideSource
{
    public function get(string $key): ?string;
}

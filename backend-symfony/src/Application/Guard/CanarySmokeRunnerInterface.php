<?php

declare(strict_types=1);

namespace App\Application\Guard;

/**
 * Runs the real-LLM reply-objective smoke over the fixture set and returns the machine-readable
 * summary (fixtures + aggregate) that {@see PromptCanaryService} scores. Abstracted as a port so
 * the worker's orchestration can be unit-tested with a canned summary — the real adapter drives
 * the actual reply pipeline (and is exercised only in a real, paid end-to-end run).
 *
 * Any candidate override must already be active (via {@see \App\Application\LLM\Prompt\EphemeralPromptOverride})
 * when this is called; the runner itself is unaware of it.
 */
interface CanarySmokeRunnerInterface
{
    /**
     * @throws \RuntimeException if the smoke did not produce a readable summary
     *
     * @return array<string, mixed> the smoke summary (with `fixtures` and `aggregate` keys)
     */
    public function run(): array;
}

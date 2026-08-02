<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Application\Guard\CanarySmokeRunnerInterface;
use App\Application\LLM\Prompt\EphemeralPromptOverride;

/**
 * A {@see CanarySmokeRunnerInterface} that returns a canned summary instead of driving the real
 * LLM. It records what the ephemeral holder exposes for a watched key at run time, so a test can
 * prove the candidate is active while the smoke runs. Optionally throws, to exercise failure.
 */
final class FakeCanarySmokeRunner implements CanarySmokeRunnerInterface
{
    public ?string $candidateSeenDuringRun = null;
    public bool $ran = false;

    /**
     * @param array<string, mixed> $summary
     */
    public function __construct(
        private readonly EphemeralPromptOverride $ephemeral,
        private readonly string $watchKey,
        private readonly array $summary,
        private readonly ?\Throwable $throw = null,
    ) {
    }

    public function run(): array
    {
        $this->ran = true;
        $this->candidateSeenDuringRun = $this->ephemeral->get($this->watchKey);

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->summary;
    }
}

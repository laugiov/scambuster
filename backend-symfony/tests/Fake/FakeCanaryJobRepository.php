<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Domain\Prompt\PromptCanaryJob;
use App\Domain\Prompt\PromptCanaryJobRepositoryInterface;

/**
 * In-memory {@see PromptCanaryJobRepositoryInterface} for unit-testing the worker without a DB:
 * seed pending jobs, capture the last saved job, and stub the stale-sweep count.
 */
final class FakeCanaryJobRepository implements PromptCanaryJobRepositoryInterface
{
    public ?PromptCanaryJob $saved = null;
    public int $staleToFail = 0;
    /** Returned by findLatestByKey when its prompt key matches. */
    public ?PromptCanaryJob $latest = null;

    /** @var list<PromptCanaryJob> */
    private array $pending;

    public function __construct(PromptCanaryJob ...$pending)
    {
        $this->pending = array_values($pending);
    }

    public function find(int $id): ?PromptCanaryJob
    {
        return null;
    }

    public function findLatestByKey(string $key): ?PromptCanaryJob
    {
        return $this->latest !== null && $this->latest->getPromptKey() === $key ? $this->latest : null;
    }

    public function save(PromptCanaryJob $job): void
    {
        $this->saved = $job;
    }

    public function claimOldestPending(): ?PromptCanaryJob
    {
        $job = array_shift($this->pending);
        $job?->markRunning();

        return $job;
    }

    public function failStale(\DateTimeImmutable $threshold): int
    {
        return $this->staleToFail;
    }
}

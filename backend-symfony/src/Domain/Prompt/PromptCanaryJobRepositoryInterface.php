<?php

declare(strict_types=1);

namespace App\Domain\Prompt;

interface PromptCanaryJobRepositoryInterface
{
    public function find(int $id): ?PromptCanaryJob;

    /**
     * The most recent job for a prompt key (highest id), or null when none was ever requested.
     * Lets the UI re-attach to a running/recent validation after a reload — the client-side job
     * handle is otherwise lost, so a refresh would drop the in-progress run or the fresh verdict.
     */
    public function findLatestByKey(string $key): ?PromptCanaryJob;

    public function save(PromptCanaryJob $job): void;

    /**
     * Claim the oldest PENDING job — mark it RUNNING and return it — under a row lock, so the
     * single dedicated worker never re-takes a job and a job is never lost. Returns null when
     * there is no pending job. Assumes one worker: it uses a plain FOR UPDATE (no SKIP LOCKED),
     * which is correct but serializes under concurrency — running several workers would need
     * SKIP LOCKED (native SQL) first.
     */
    public function claimOldestPending(): ?PromptCanaryJob;

    /**
     * Fail every job stranded in RUNNING since before $threshold — a worker that crashed or was
     * killed between claiming and finishing — so it reaches a terminal state instead of the UI
     * polling it forever. $threshold must exceed the longest legitimate run. Returns the count.
     */
    public function failStale(\DateTimeImmutable $threshold): int;
}

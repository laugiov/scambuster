<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Prompt;

use App\Domain\Prompt\CanaryJobStatus;
use App\Domain\Prompt\PromptCanaryJob;
use PHPUnit\Framework\TestCase;

final class PromptCanaryJobTest extends TestCase
{
    public function testStartsPending(): void
    {
        $job = new PromptCanaryJob('reward_judge', 'candidate body', 'alice');

        self::assertSame(CanaryJobStatus::PENDING, $job->getStatus());
        self::assertSame('reward_judge', $job->getPromptKey());
        self::assertSame('candidate body', $job->getCandidateBody());
        self::assertSame('alice', $job->getRequestedBy());
        self::assertNull($job->getVerdict());
        self::assertNull($job->getStartedAt());
        self::assertNull($job->getFinishedAt());
    }

    public function testMarkRunningStampsStartedAt(): void
    {
        $job = new PromptCanaryJob('reward_judge', 'body');
        $at = new \DateTimeImmutable('2026-07-29 10:00:00');
        $job->markRunning($at);

        self::assertSame(CanaryJobStatus::RUNNING, $job->getStatus());
        self::assertSame($at, $job->getStartedAt());
        self::assertNull($job->getFinishedAt());
    }

    public function testMarkSucceededStoresVerdict(): void
    {
        $job = new PromptCanaryJob('reward_judge', 'body');
        $verdict = ['ok' => false, 'fingerprint_ok' => true, 'regressions' => [['signal' => 'crypto_wallet']]];
        $at = new \DateTimeImmutable('2026-07-29 10:35:00');
        $job->markSucceeded($verdict, $at);

        // SUCCEEDED means the canary ran to completion — the verdict may still report a regression.
        self::assertSame(CanaryJobStatus::SUCCEEDED, $job->getStatus());
        self::assertSame($verdict, $job->getVerdict());
        self::assertSame($at, $job->getFinishedAt());
        self::assertNull($job->getError());
    }

    public function testMarkFailedStoresError(): void
    {
        $job = new PromptCanaryJob('reward_judge', 'body');
        $at = new \DateTimeImmutable('2026-07-29 10:05:00');
        $job->markFailed('llm timeout', $at);

        self::assertSame(CanaryJobStatus::FAILED, $job->getStatus());
        self::assertSame('llm timeout', $job->getError());
        self::assertSame($at, $job->getFinishedAt());
        self::assertNull($job->getVerdict());
    }

    public function testTerminalStatesAreMutuallyExclusive(): void
    {
        $job = new PromptCanaryJob('reward_judge', 'body');

        // A verdict then a failure must not leave a stale verdict beside the error.
        $job->markSucceeded(['ok' => true, 'regressions' => []]);
        $job->markFailed('late error');
        self::assertNull($job->getVerdict());
        self::assertSame('late error', $job->getError());

        // ...and symmetrically.
        $job->markSucceeded(['ok' => true, 'regressions' => []]);
        self::assertNull($job->getError());
        self::assertSame(['ok' => true, 'regressions' => []], $job->getVerdict());
    }
}

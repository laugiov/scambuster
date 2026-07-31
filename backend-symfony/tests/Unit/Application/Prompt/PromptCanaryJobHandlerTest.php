<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Prompt;

use App\Application\Prompt\Exception\CanaryJobNotFoundException;
use App\Application\Prompt\Exception\InvalidPromptOverrideException;
use App\Application\Prompt\PromptBodyValidator;
use App\Application\Prompt\PromptCanaryJobHandler;
use App\Domain\Prompt\PromptCanaryJob;
use App\Domain\Prompt\PromptCanaryJobRepositoryInterface;
use App\Tests\Fake\FakeCanaryJobRepository;
use PHPUnit\Framework\TestCase;

final class PromptCanaryJobHandlerTest extends TestCase
{
    private function handler(PromptCanaryJobRepositoryInterface $repo): PromptCanaryJobHandler
    {
        return new PromptCanaryJobHandler($repo, new PromptBodyValidator());
    }

    public function testRequestValidatesThenEnqueuesAPendingJob(): void
    {
        $repo = new FakeCanaryJobRepository();
        $this->handler($repo)->request('reward_judge', 'MY CANDIDATE', 'alice');

        self::assertNotNull($repo->saved);
        self::assertSame('reward_judge', $repo->saved->getPromptKey());
        self::assertSame('MY CANDIDATE', $repo->saved->getCandidateBody());
        self::assertSame('alice', $repo->saved->getRequestedBy());
    }

    public function testRequestRejectsAnInvalidCandidateBeforeEnqueuing(): void
    {
        $repo = new FakeCanaryJobRepository();

        try {
            $this->handler($repo)->request('contextual_enrichment', 'no tokens', null);
            self::fail('expected validation to reject the candidate');
        } catch (InvalidPromptOverrideException) {
            // A rejected candidate must never be enqueued.
            self::assertNull($repo->saved);
        }
    }

    public function testViewReturnsThePolledJobShape(): void
    {
        $job = new PromptCanaryJob('reward_judge', 'body', 'alice');
        $repo = new class($job) implements PromptCanaryJobRepositoryInterface {
            public function __construct(private readonly PromptCanaryJob $job)
            {
            }

            public function find(int $id): ?PromptCanaryJob
            {
                return $id > 0 ? $this->job : null;
            }

            public function findLatestByKey(string $key): ?PromptCanaryJob
            {
                return $this->job->getPromptKey() === $key ? $this->job : null;
            }

            public function save(PromptCanaryJob $job): void
            {
            }

            public function claimOldestPending(): ?PromptCanaryJob
            {
                return null;
            }

            public function failStale(\DateTimeImmutable $threshold): int
            {
                return 0;
            }
        };

        $view = $this->handler($repo)->view(1);

        self::assertSame('reward_judge', $view['prompt_key']);
        self::assertSame('pending', $view['status']);
        self::assertNull($view['verdict']);
        self::assertArrayHasKey('created_at', $view);
    }

    public function testViewThrowsOnUnknownJob(): void
    {
        $this->expectException(CanaryJobNotFoundException::class);
        $this->handler(new FakeCanaryJobRepository())->view(424242);
    }

    public function testLatestForKeyReturnsThePolledShapePlusCandidateBody(): void
    {
        // candidate_body is added so the UI can tell whether a terminal verdict still matches the
        // saved override; the rest mirrors the poll view.
        $repo = new FakeCanaryJobRepository();
        $repo->latest = new PromptCanaryJob('persona_style_rules', 'MY CANDIDATE BODY', 'alice');

        $latest = $this->handler($repo)->latestForKey('persona_style_rules');

        self::assertNotNull($latest);
        self::assertSame('persona_style_rules', $latest['prompt_key']);
        self::assertSame('MY CANDIDATE BODY', $latest['candidate_body']);
        self::assertSame('pending', $latest['status']);
        self::assertArrayHasKey('verdict', $latest);
    }

    public function testLatestForKeyReturnsNullWhenTheKeyWasNeverValidated(): void
    {
        self::assertNull($this->handler(new FakeCanaryJobRepository())->latestForKey('persona_style_rules'));
    }
}

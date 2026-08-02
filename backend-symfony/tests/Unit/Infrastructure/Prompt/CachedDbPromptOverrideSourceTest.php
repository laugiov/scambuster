<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Prompt;

use App\Domain\Prompt\PromptOverride;
use App\Domain\Prompt\PromptOverrideRepositoryInterface;
use App\Infrastructure\Prompt\CachedDbPromptOverrideSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CachedDbPromptOverrideSourceTest extends TestCase
{
    /**
     * @param list<PromptOverride> $enabled
     */
    private function repo(array $enabled): PromptOverrideRepositoryInterface
    {
        $repo = $this->createMock(PromptOverrideRepositoryInterface::class);
        $repo->method('findAllEnabled')->willReturn($enabled);

        return $repo;
    }

    public function testReturnsEnabledOverrideBodyByKey(): void
    {
        $source = new CachedDbPromptOverrideSource(
            $this->repo([new PromptOverride('reward_judge', 'DB RUBRIC', true)]),
            new NullLogger(),
        );

        self::assertSame('DB RUBRIC', $source->get('reward_judge'));
    }

    public function testReturnsNullForAbsentKey(): void
    {
        $source = new CachedDbPromptOverrideSource(
            $this->repo([new PromptOverride('reward_judge', 'DB RUBRIC', true)]),
            new NullLogger(),
        );

        self::assertNull($source->get('contextual_enrichment'));
    }

    public function testCachesSoTheRepositoryIsQueriedOncePerRequest(): void
    {
        // The whole point of the cache: the reply pipeline resolves many prompts but
        // must hit the store at most once. findAllEnabled MUST be called exactly once.
        $repo = $this->createMock(PromptOverrideRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('findAllEnabled')
            ->willReturn([new PromptOverride('reward_judge', 'DB RUBRIC', true)]);

        $source = new CachedDbPromptOverrideSource($repo, new NullLogger());

        $source->get('reward_judge');
        $source->get('reward_judge');
        $source->get('contextual_enrichment');
    }

    public function testFailsSafeToNoOverridesWhenRepositoryThrows(): void
    {
        $repo = $this->createMock(PromptOverrideRepositoryInterface::class);
        $repo->method('findAllEnabled')->willThrowException(new \RuntimeException('db down'));

        $source = new CachedDbPromptOverrideSource($repo, new NullLogger());

        self::assertNull($source->get('reward_judge'), 'a store error must be treated as no override');
    }

    public function testRepositoryErrorIsCachedAndNotRetriedEveryCall(): void
    {
        $repo = $this->createMock(PromptOverrideRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('findAllEnabled')
            ->willThrowException(new \RuntimeException('db down'));

        $source = new CachedDbPromptOverrideSource($repo, new NullLogger());

        // Even after a failure, we do not hammer a down database on every lookup.
        self::assertNull($source->get('a'));
        self::assertNull($source->get('b'));
    }
}

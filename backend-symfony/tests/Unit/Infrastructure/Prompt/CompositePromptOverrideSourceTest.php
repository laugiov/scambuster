<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Prompt;

use App\Application\LLM\Prompt\EphemeralPromptOverride;
use App\Application\LLM\Prompt\PromptOverrideSource;
use App\Infrastructure\Prompt\CompositePromptOverrideSource;
use PHPUnit\Framework\TestCase;

final class CompositePromptOverrideSourceTest extends TestCase
{
    private function fixed(?string $body): PromptOverrideSource
    {
        return new class($body) implements PromptOverrideSource {
            public function __construct(private ?string $body)
            {
            }

            public function get(string $key): ?string
            {
                return $this->body;
            }
        };
    }

    public function testReturnsFirstNonNullInOrder(): void
    {
        $composite = new CompositePromptOverrideSource([$this->fixed(null), $this->fixed('db-body'), $this->fixed('never')]);

        self::assertSame('db-body', $composite->get('reward_judge'));
    }

    public function testAllNullYieldsNull(): void
    {
        $composite = new CompositePromptOverrideSource([$this->fixed(null), $this->fixed(null)]);

        self::assertNull($composite->get('reward_judge'));
    }

    public function testAThrowingSourceIsTreatedAsAbsent(): void
    {
        $throwing = new class implements PromptOverrideSource {
            public function get(string $key): ?string
            {
                throw new \RuntimeException('store down');
            }
        };
        $composite = new CompositePromptOverrideSource([$throwing, $this->fixed('db-body')]);

        // The composite honours its own "never throws" contract and falls through.
        self::assertSame('db-body', $composite->get('reward_judge'));
    }

    public function testEphemeralCandidateShadowsTheDbSource(): void
    {
        $ephemeral = new EphemeralPromptOverride();
        $db = $this->fixed('saved-db-body');
        $composite = new CompositePromptOverrideSource([$ephemeral, $db]);

        // Empty ephemeral → the DB source wins (normal-process behaviour, unchanged).
        self::assertSame('saved-db-body', $composite->get('reward_judge'));

        // A candidate set for the key wins over the saved override, without persisting anything.
        $ephemeral->set('reward_judge', 'unsaved-candidate');
        self::assertSame('unsaved-candidate', $composite->get('reward_judge'));

        // For a different key the DB source still wins.
        self::assertSame('saved-db-body', $composite->get('contextual_enrichment'));

        // Clearing restores the DB source for the key.
        $ephemeral->clear();
        self::assertSame('saved-db-body', $composite->get('reward_judge'));
    }
}

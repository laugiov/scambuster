<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM\Prompt;

use App\Application\LLM\Prompt\EphemeralPromptOverride;
use PHPUnit\Framework\TestCase;

final class EphemeralPromptOverrideTest extends TestCase
{
    public function testEmptyByDefault(): void
    {
        self::assertNull((new EphemeralPromptOverride())->get('reward_judge'));
    }

    public function testReturnsBodyForTheSetKeyOnly(): void
    {
        $holder = new EphemeralPromptOverride();
        $holder->set('reward_judge', 'candidate body');

        self::assertSame('candidate body', $holder->get('reward_judge'));
        self::assertNull($holder->get('contextual_enrichment'));
    }

    public function testClearResetsToEmpty(): void
    {
        $holder = new EphemeralPromptOverride();
        $holder->set('reward_judge', 'candidate body');
        $holder->clear();

        self::assertNull($holder->get('reward_judge'));
    }

    public function testSetReplacesThePreviousEntry(): void
    {
        $holder = new EphemeralPromptOverride();
        $holder->set('reward_judge', 'first');
        $holder->set('contextual_enrichment', 'second');

        // Only the most recent single entry is held.
        self::assertNull($holder->get('reward_judge'));
        self::assertSame('second', $holder->get('contextual_enrichment'));
    }

    public function testEmptyBodyIsTreatedAsAbsent(): void
    {
        $holder = new EphemeralPromptOverride();
        $holder->set('reward_judge', '');

        self::assertNull($holder->get('reward_judge'));
    }

    public function testWithCandidateActivatesForTheScopeThenClears(): void
    {
        $holder = new EphemeralPromptOverride();

        $seen = $holder->withCandidate('reward_judge', 'candidate body', static fn (): string => 'ran');

        self::assertSame('ran', $seen);
        // Cleared after the scope — nothing resident.
        self::assertNull($holder->get('reward_judge'));
    }

    public function testWithCandidateSeesTheCandidateInsideTheScope(): void
    {
        $holder = new EphemeralPromptOverride();

        $inside = $holder->withCandidate('reward_judge', 'candidate body', static fn () => $holder->get('reward_judge'));

        self::assertSame('candidate body', $inside);
        self::assertNull($holder->get('reward_judge'));
    }

    public function testWithCandidateClearsEvenWhenTheCallableThrows(): void
    {
        $holder = new EphemeralPromptOverride();

        try {
            $holder->withCandidate('reward_judge', 'candidate body', static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            // The exception must propagate out of withCandidate (not be swallowed by finally).
            self::assertSame('boom', $e->getMessage());
        }

        // The finally-clear ran despite the exception — no resident candidate to leak.
        self::assertNull($holder->get('reward_judge'));
    }
}

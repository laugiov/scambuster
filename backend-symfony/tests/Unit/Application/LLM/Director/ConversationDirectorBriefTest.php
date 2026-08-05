<?php

declare(strict_types=1);

namespace Tests\Unit\Application\LLM\Director;

use App\Application\LLM\Director\ConversationDirectorBrief;
use App\Application\LLM\Director\MarkState;
use App\Application\LLM\Director\Progress;
use PHPUnit\Framework\TestCase;

final class ConversationDirectorBriefTest extends TestCase
{
    public function testDefaultIsSafeAndContinues(): void
    {
        $b = ConversationDirectorBrief::default();

        self::assertSame([], $b->alreadyObtained);
        self::assertSame(MarkState::COOPERATIVE, $b->markState);
        self::assertSame(Progress::ADVANCING, $b->progress);
        self::assertSame('', $b->objective);
        self::assertSame('', $b->nextMove);
        self::assertSame('', $b->styleDirective);
        self::assertTrue($b->shouldContinue);
        self::assertSame('', $b->stopReason);
    }

    public function testFromArrayParsesAWellFormedBrief(): void
    {
        $b = ConversationDirectorBrief::fromArray([
            'already_obtained' => ['postal address', 'registration number', 42, 'client references'],
            'mark_state' => 'HOSTILE',
            'objective' => '  drive to upfront payment  ',
            'progress' => 'stalled',
            'next_move' => 'stop verifying and ask for the rate',
            'style_directive' => '  answer only, ask nothing this turn  ',
            'should_continue' => false,
            'stop_reason' => 'mark called us a bot',
        ]);

        // non-strings filtered out of the obtained list
        self::assertSame(['postal address', 'registration number', 'client references'], $b->alreadyObtained);
        self::assertSame(MarkState::HOSTILE, $b->markState);
        self::assertSame(Progress::STALLED, $b->progress);
        self::assertSame('drive to upfront payment', $b->objective);
        self::assertSame('stop verifying and ask for the rate', $b->nextMove);
        self::assertSame('answer only, ask nothing this turn', $b->styleDirective);
        self::assertFalse($b->shouldContinue);
        self::assertSame('mark called us a bot', $b->stopReason);
    }

    public function testFromArrayDegradesOnGarbage(): void
    {
        $b = ConversationDirectorBrief::fromArray([
            'already_obtained' => 'not-an-array',
            'mark_state' => 'nonsense_state',
            'progress' => null,
        ]);

        self::assertSame([], $b->alreadyObtained);
        self::assertSame(MarkState::COOPERATIVE, $b->markState, 'unknown mark_state → cooperative');
        self::assertSame(Progress::ADVANCING, $b->progress, 'missing progress → advancing');
        self::assertTrue($b->shouldContinue, 'missing should_continue → true (keep going)');
    }

    public function testEnumLooseParsingIsCaseInsensitive(): void
    {
        self::assertSame(MarkState::ANTI_BOT_CHALLENGE, MarkState::fromLoose('Anti_Bot_Challenge'));
        self::assertSame(MarkState::COOPERATIVE, MarkState::fromLoose(null));
        self::assertSame(Progress::REGRESSING, Progress::fromLoose(' regressing '));
    }
}

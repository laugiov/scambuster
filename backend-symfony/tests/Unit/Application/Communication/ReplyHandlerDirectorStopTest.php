<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Communication;

use App\Application\Communication\ReplyHandler;
use App\Application\LLM\ConversationAnalyzer;
use App\Application\LLM\Port\LLMClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The director stop-gate in ReplyHandler decides whether a burned conversation
 * should be closed instead of answered. Tested in isolation via reflection: the
 * method only depends on the injected ConversationAnalyzer, and ReplyHandler's
 * other collaborators (ReplyOrchestrator, ConversationClosureService) are final
 * and irrelevant to this decision.
 */
final class ReplyHandlerDirectorStopTest extends TestCase
{
    /** @param array<string, mixed> $director */
    private function handlerWithDirector(array $director): ReplyHandler
    {
        $response = json_encode([
            'strategic_analysis' => 'x',
            'repetitions_detected' => [],
            'tone_recommendation' => 'suspicious',
            'strategic_suggestions' => [],
            'instructions' => ['interdictions' => [], 'obligations' => []],
            'director' => $director,
        ], JSON_THROW_ON_ERROR);

        $llm = $this->createMock(LLMClientInterface::class);
        $llm->method('chat')->willReturn($response);
        $analyzer = new ConversationAnalyzer($llm, new NullLogger());

        $ref = new \ReflectionClass(ReplyHandler::class);
        $handler = $ref->newInstanceWithoutConstructor();
        $ref->getProperty('conversationAnalyzer')->setValue($handler, $analyzer);
        $ref->getProperty('logger')->setValue($handler, new NullLogger());

        return $handler;
    }

    /** @param array<string, mixed> $context */
    private function callStop(ReplyHandler $handler, array $context): ?string
    {
        $m = new \ReflectionMethod($handler, 'directorStopReason');
        $m->setAccessible(true);

        /** @var string|null $r */
        $r = $m->invoke($handler, $context, 'small_business_owner');

        return $r;
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'conv_id' => 'c1',
            'scam_type' => 'COLD_SERVICE_SPAM',
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'I gave it. Are you a bot?', 'ts_msg' => '2026-01-01T00:00:00+00:00'],
                ['direction' => 'out', 'body_text' => 'Could you share your address?', 'ts_msg' => '2026-01-01T01:00:00+00:00'],
            ],
            'extracted_iocs' => [],
        ];
    }

    public function testReturnsStopReasonWhenDirectorSaysStop(): void
    {
        $handler = $this->handlerWithDirector([
            'should_continue' => false,
            'stop_reason' => 'mark called us a bot and threatened to block',
        ]);

        self::assertSame(
            'mark called us a bot and threatened to block',
            $this->callStop($handler, $this->context()),
        );
    }

    public function testReturnsNullWhenDirectorSaysContinue(): void
    {
        $handler = $this->handlerWithDirector(['should_continue' => true]);

        self::assertNull($this->callStop($handler, $this->context()));
    }

    public function testReturnsGenericReasonWhenStopReasonMissing(): void
    {
        $handler = $this->handlerWithDirector(['should_continue' => false, 'stop_reason' => '']);

        self::assertSame('conversation burned', $this->callStop($handler, $this->context()));
    }

    public function testReturnsNullWhenTooFewMessages(): void
    {
        $handler = $this->handlerWithDirector(['should_continue' => false, 'stop_reason' => 'x']);
        $ctx = $this->context();
        $ctx['last_messages'] = [$ctx['last_messages'][0]];

        self::assertNull($this->callStop($handler, $ctx), 'under 2 messages → never gate');
    }

    public function testReturnsNullWhenAnalyzerAbsent(): void
    {
        $ref = new \ReflectionClass(ReplyHandler::class);
        $handler = $ref->newInstanceWithoutConstructor();
        $ref->getProperty('conversationAnalyzer')->setValue($handler, null);
        $ref->getProperty('logger')->setValue($handler, new NullLogger());

        self::assertNull($this->callStop($handler, $this->context()));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Ttp;

use App\Application\Audit\AuditLoggerInterface;
use App\Application\Communication\TtpManager;
use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\Prompt\PromptProvider;
use App\Application\LLM\TtpExtractor;
use App\Application\Ttp\Exception\OutgoingMessageException;
use App\Application\Ttp\Exception\TtpExtractionDisabledException;
use App\Application\Ttp\TtpHandler;
use App\Application\Ttp\TtpObservationUpsertService;
use App\Domain\Audit\AuditEventType;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Direction;
use App\Domain\Communication\Message;
use App\Domain\Communication\Policy\TtpExtractionPolicy;
use App\Domain\Communication\Ttp;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * TtpHandler unit tests with mocked collaborators.
 *
 * TtpExtractor and TtpObservationUpsertService are final, so they are used
 * for real: the extractor gets a stub LLM client returning controlled JSON
 * and the upsert service gets a mocked DBAL connection. The LLM output is
 * one item above and one below the 0.55 threshold so the confirmed/review
 * split is observable.
 */
final class TtpHandlerTest extends TestCase
{
    private const MSG_ID = '11111111-1111-1111-1111-111111111111';
    private const CONV_ID = '22222222-2222-2222-2222-222222222222';

    /**
     * Spy LLM client: returns a fixed response and records whether it was called.
     */
    private function makeLlmSpy(string $response): LLMClientInterface
    {
        return new class ($response) implements LLMClientInterface {
            public bool $called = false;

            public function __construct(private readonly string $response)
            {
            }

            public function chat(array $messages, array $options = []): string
            {
                $this->called = true;

                return $this->response;
            }
        };
    }

    private function makeExtractor(LLMClientInterface $llm): TtpExtractor
    {
        return new TtpExtractor($llm, new NullLogger(), new PromptProvider(sys_get_temp_dir(), new NullLogger()));
    }

    private function twoItemLlmResponse(): string
    {
        return (string) json_encode([
            ['ttp_id' => 'SB-T017', 'confidence' => 0.92, 'evidence' => 'act now'],
            ['ttp_id' => 'SB-T022', 'confidence' => 0.4, 'evidence' => 'no time for contracts'],
        ]);
    }

    private function makeMessage(string $directionCode): Message&MockObject
    {
        $direction = $this->createMock(Direction::class);
        $direction->method('getCode')->willReturn($directionCode);

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getConvId')->willReturn(self::CONV_ID);

        $message = $this->createMock(Message::class);
        $message->method('getMsgId')->willReturn(self::MSG_ID);
        $message->method('getDirection')->willReturn($direction);
        $message->method('getConversation')->willReturn($conversation);
        $message->method('getDeletedAt')->willReturn(null);
        $message->method('getSubject')->willReturn('Final notice');
        $message->method('getBodyText')->willReturn('you must act now, there is no time for contracts');

        return $message;
    }

    private function makeEmReturning(?Message $message): EntityManagerInterface&MockObject
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->with(self::MSG_ID)->willReturn($message);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Message::class)->willReturn($repository);

        return $em;
    }

    private function makeTtpManagerWithTaxonomy(): TtpManager&MockObject
    {
        $ttpManager = $this->createMock(TtpManager::class);
        $ttpManager->method('allActive')->willReturn([
            new Ttp('SB-T017', 'Urgency deadline pressure', 'Imposes a hard deadline to force immediate action.', 'escalation', [], []),
            new Ttp('SB-T022', 'Verification deflection', 'Refuses or evades verification requests.', 'escalation', [], []),
        ]);

        return $ttpManager;
    }

    public function test_disabled_module_throws_before_any_em_or_llm_touch(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getRepository');

        $ttpManager = $this->createMock(TtpManager::class);
        $ttpManager->expects($this->never())->method('allActive');

        $llm = $this->makeLlmSpy($this->twoItemLlmResponse());

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeStatement');

        $handler = new TtpHandler(
            $em,
            $ttpManager,
            $this->makeExtractor($llm),
            new TtpObservationUpsertService($connection, new NullLogger()),
            new TtpExtractionPolicy(),
            new NullLogger(),
            null,
            enabled: false,
        );

        $this->assertFalse($handler->isEnabled());

        try {
            $handler->extractForMessage(self::MSG_ID);
            $this->fail('Expected TtpExtractionDisabledException');
        } catch (TtpExtractionDisabledException) {
            // expected
        }

        /** @phpstan-ignore-next-line dynamic spy property */
        $this->assertFalse($llm->called, 'The LLM must never be called when the module is disabled');
    }

    public function test_threshold_splits_observations_into_confirmed_and_review(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditCalls = [];
        $auditLogger->expects($this->exactly(2))->method('log')
            ->willReturnCallback(function (AuditEventType $eventType, string $actorId, string $action, string $outcome, ?string $resourceType, ?string $resourceId, array $details) use (&$auditCalls): void {
                $auditCalls[] = [$eventType, $actorId, $action, $outcome, $resourceType, $resourceId, $details];
            });

        $handler = new TtpHandler(
            $this->makeEmReturning($this->makeMessage('in')),
            $this->makeTtpManagerWithTaxonomy(),
            $this->makeExtractor($this->makeLlmSpy($this->twoItemLlmResponse())),
            new TtpObservationUpsertService($connection, new NullLogger()),
            new TtpExtractionPolicy(),
            new NullLogger(),
            $auditLogger,
        );

        $result = $handler->extractForMessage(self::MSG_ID);

        $this->assertSame(self::MSG_ID, $result['msg_id']);
        $this->assertSame(2, $result['ttps_found']);
        $this->assertSame(2, $result['persisted']);
        $this->assertCount(2, $result['observations']);

        $byCode = array_column($result['observations'], null, 'ttp_code');
        $this->assertSame('confirmed', $byCode['SB-T017']['status']);
        $this->assertSame(0.92, $byCode['SB-T017']['confidence']);
        $this->assertSame('review', $byCode['SB-T022']['status']);
        $this->assertSame(0.4, $byCode['SB-T022']['confidence']);

        // Evidence verbatims never leave the persistence layer.
        foreach ($result['observations'] as $observation) {
            $this->assertArrayNotHasKey('evidence', $observation);
        }

        // Both evidences appear verbatim in the message text → offsets computed.
        $this->assertNotNull($byCode['SB-T017']['evidence_start']);
        $this->assertNotNull($byCode['SB-T022']['evidence_start']);

        // One audit event per persisted observation, keyed on the conversation.
        $this->assertCount(2, $auditCalls);

        foreach ($auditCalls as [$eventType, $actorId, $action, $outcome, $resourceType, $resourceId, $details]) {
            $this->assertSame(AuditEventType::TTP_EXTRACTED, $eventType);
            $this->assertSame(self::CONV_ID, $actorId);
            $this->assertSame('ttp_extracted', $action);
            $this->assertSame('success', $outcome);
            $this->assertSame('ttp_observation', $resourceType);
            $this->assertSame(self::MSG_ID, $resourceId);
            $this->assertArrayHasKey('ttp_code', $details);
            $this->assertArrayHasKey('confidence', $details);
            $this->assertArrayHasKey('status', $details);
        }
    }

    public function test_confidence_exactly_at_the_threshold_is_confirmed(): void
    {
        // The rule is "review BELOW the threshold": a confidence equal to the
        // threshold must land on confirmed. Locks >= against a >= -> > regression.
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);

        $response = (string) json_encode([
            ['ttp_id' => 'SB-T017', 'confidence' => 0.55, 'evidence' => 'act now'],
        ]);

        $handler = new TtpHandler(
            $this->makeEmReturning($this->makeMessage('in')),
            $this->makeTtpManagerWithTaxonomy(),
            $this->makeExtractor($this->makeLlmSpy($response)),
            new TtpObservationUpsertService($connection, new NullLogger()),
            new TtpExtractionPolicy(),
            new NullLogger(),
        );

        $result = $handler->extractForMessage(self::MSG_ID);

        $this->assertSame('confirmed', $result['observations'][0]['status']);
        $this->assertSame(0.55, $result['observations'][0]['confidence']);
    }

    public function test_outgoing_message_is_refused(): void
    {
        $ttpManager = $this->createMock(TtpManager::class);
        $ttpManager->expects($this->never())->method('allActive');

        $llm = $this->makeLlmSpy($this->twoItemLlmResponse());

        $handler = new TtpHandler(
            $this->makeEmReturning($this->makeMessage('out')),
            $ttpManager,
            $this->makeExtractor($llm),
            new TtpObservationUpsertService($this->createMock(Connection::class), new NullLogger()),
            new TtpExtractionPolicy(),
            new NullLogger(),
        );

        try {
            $handler->extractForMessage(self::MSG_ID);
            $this->fail('Expected OutgoingMessageException');
        } catch (OutgoingMessageException $e) {
            $this->assertSame(self::MSG_ID, $e->getMsgId());
            $this->assertSame('out', $e->getDirection());
        }

        /** @phpstan-ignore-next-line dynamic spy property */
        $this->assertFalse($llm->called, 'The LLM must never be called for an outgoing message');
    }

    public function test_missing_message_throws_runtime_exception(): void
    {
        $handler = new TtpHandler(
            $this->makeEmReturning(null),
            $this->makeTtpManagerWithTaxonomy(),
            $this->makeExtractor($this->makeLlmSpy($this->twoItemLlmResponse())),
            new TtpObservationUpsertService($this->createMock(Connection::class), new NullLogger()),
            new TtpExtractionPolicy(),
            new NullLogger(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Message not found: ' . self::MSG_ID);

        $handler->extractForMessage(self::MSG_ID);
    }

    public function test_persist_false_returns_observations_without_upserting(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeStatement');

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects($this->never())->method('log');

        $handler = new TtpHandler(
            $this->makeEmReturning($this->makeMessage('in')),
            $this->makeTtpManagerWithTaxonomy(),
            $this->makeExtractor($this->makeLlmSpy($this->twoItemLlmResponse())),
            new TtpObservationUpsertService($connection, new NullLogger()),
            new TtpExtractionPolicy(),
            new NullLogger(),
            $auditLogger,
        );

        $result = $handler->extractForMessage(self::MSG_ID, persist: false);

        $this->assertSame(2, $result['ttps_found']);
        $this->assertSame(0, $result['persisted']);
        $this->assertCount(2, $result['observations']);
    }

    public function test_one_failing_row_does_not_abort_the_batch(): void
    {
        $connection = $this->createMock(Connection::class);
        $callCount = 0;
        $connection->method('executeStatement')->willReturnCallback(function () use (&$callCount): int {
            ++$callCount;

            if ($callCount === 1) {
                throw new \RuntimeException('constraint violation');
            }

            return 1;
        });

        $handler = new TtpHandler(
            $this->makeEmReturning($this->makeMessage('in')),
            $this->makeTtpManagerWithTaxonomy(),
            $this->makeExtractor($this->makeLlmSpy($this->twoItemLlmResponse())),
            new TtpObservationUpsertService($connection, new NullLogger()),
            new TtpExtractionPolicy(),
            new NullLogger(),
        );

        $result = $handler->extractForMessage(self::MSG_ID);

        $this->assertSame(2, $result['ttps_found']);
        $this->assertSame(1, $result['persisted'], 'The failing row is skipped, the other one is persisted');
        $this->assertCount(2, $result['observations'], 'The observation list still reports both items');
    }
}

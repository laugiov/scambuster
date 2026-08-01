<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Scambaiting;

use App\Application\Audit\AuditLogger;
use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\Prompt\PromptProvider;
use App\Application\Scambaiting\ConversationClosureService;
use App\Application\Scambaiting\ConversationMetricsCollector;
use App\Application\Scambaiting\RewardJudge;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditLog;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Domain\Scambaiting\ConversationMetrics;
use App\Domain\Scambaiting\Event\ConversationEndedEvent;
use App\Infrastructure\Siem\Adapter\NullSiemExporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ConversationClosureServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private ConversationMetricsCollector $metricsCollector;
    private EventDispatcherInterface $eventDispatcher;
    private LoggerInterface $logger;
    private ConversationClosureService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->metricsCollector = $this->createMock(ConversationMetricsCollector::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ConversationClosureService(
            $this->em,
            $this->metricsCollector,
            $this->eventDispatcher,
            $this->logger
        );
    }

    public function testCloseConversationUpdatesRewardAndDispatchesEvent(): void
    {
        // Arrange
        $conversation = $this->createMockConversation(
            convId: 'conv-123',
            status: ConversationStatus::OPEN,
            scamTypeCode: 'PHISHING',
            personaCode: 'senior_trusting',
            turnsCount: 10
        );

        $metrics = new ConversationMetrics(
            durationSec: 450,
            iocsTotal: 5,
            iocsSensibles: 3,
            isCompleted: true
        );

        $expectedReward = $metrics->calculateReward(); // ~0.6958

        $conversationRepo = $this->createMock(EntityRepository::class);
        $conversationRepo->method('find')
            ->with('conv-123')
            ->willReturn($conversation);

        // Mock Connection for computeMessageMetrics
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(['turns' => 10, 'duration_sec' => 450]);
        $this->em->method('getConnection')->willReturn($conn);

        $this->em->method('getRepository')
            ->with(Conversation::class)
            ->willReturn($conversationRepo);

        $this->metricsCollector->method('collect')
            ->willReturn($metrics);

        $conversation->expects($this->once())
            ->method('setRewardValue')
            ->with($this->equalTo($expectedReward, 0.0001));

        $this->em->expects($this->once())
            ->method('flush');

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (ConversationEndedEvent $event) {
                return $event->getConversationId() === 'conv-123'
                    && $event->getScamTypeCode() === 'PHISHING'
                    && $event->getPersonaCode() === 'senior_trusting'
                    && $event->getDurationSec() === 450
                    && $event->getTurnsCount() === 10
                    && $event->getIocsTotal() === 5
                    && $event->getIocsSensibles() === 3
                    && $event->isCompleted() === true;
            }));

        // Act
        $this->service->closeConversation('conv-123');
    }

    public function testCloseConversationSkipsAlreadyTerminated(): void
    {
        // Arrange
        $conversation = $this->createMockConversation(
            convId: 'conv-456',
            status: ConversationStatus::CLOSED,
            scamTypeCode: 'PHISHING',
            personaCode: 'tech_newbie',
            turnsCount: 5
        );

        $conversationRepo = $this->createMock(EntityRepository::class);
        $conversationRepo->method('find')
            ->willReturn($conversation);

        $this->em->method('getRepository')
            ->with(Conversation::class)
            ->willReturn($conversationRepo);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('already closed'),
                $this->arrayHasKey('conv_id')
            );

        // Should NOT call metricsCollector, flush, or dispatch
        $this->metricsCollector->expects($this->never())->method('collect');
        $this->em->expects($this->never())->method('flush');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        // Act
        $this->service->closeConversation('conv-456');
    }

    public function testCloseConversationSkipsAlreadyClosed(): void
    {
        // Arrange
        $conversation = $this->createMockConversation(
            convId: 'conv-789',
            status: ConversationStatus::CLOSED,
            scamTypeCode: 'PHISHING',
            personaCode: 'entrepreneur_rushed',
            turnsCount: 8
        );

        $conversationRepo = $this->createMock(EntityRepository::class);
        $conversationRepo->method('find')
            ->willReturn($conversation);

        $this->em->method('getRepository')
            ->with(Conversation::class)
            ->willReturn($conversationRepo);

        $this->logger->expects($this->once())
            ->method('warning');

        $this->metricsCollector->expects($this->never())->method('collect');
        $this->em->expects($this->never())->method('flush');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        // Act
        $this->service->closeConversation('conv-789');
    }

    public function testCloseConversationHandlesNoPersona(): void
    {
        // Arrange
        $conversation = $this->createMockConversation(
            convId: 'conv-abc',
            status: ConversationStatus::OPEN,
            scamTypeCode: 'PHISHING',
            personaCode: null,
            turnsCount: 3
        );

        $metrics = new ConversationMetrics(
            durationSec: 60,
            iocsTotal: 0,
            iocsSensibles: 0,
            isCompleted: false
        );

        $conversationRepo = $this->createMock(EntityRepository::class);
        $conversationRepo->method('find')
            ->willReturn($conversation);

        $this->em->method('getRepository')
            ->with(Conversation::class)
            ->willReturn($conversationRepo);

        $this->metricsCollector->method('collect')
            ->willReturn($metrics);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (ConversationEndedEvent $event) {
                return $event->getPersonaCode() === null;
            }));

        // Act
        $this->service->closeConversation('conv-abc');
    }

    public function testCloseConversationsBatchProcessesMultipleConversations(): void
    {
        // Arrange
        $conv1 = $this->createMockConversation(
            convId: 'conv-1',
            status: ConversationStatus::OPEN,
            scamTypeCode: 'PHISHING',
            personaCode: 'persona_1',
            turnsCount: 5
        );

        $conv2 = $this->createMockConversation(
            convId: 'conv-2',
            status: ConversationStatus::OPEN,
            scamTypeCode: 'CEO_FRAUD',
            personaCode: 'persona_2',
            turnsCount: 7
        );

        $metrics = new ConversationMetrics(100, 2, 1, true);

        $conversationRepo = $this->createMock(EntityRepository::class);
        $conversationRepo->method('find')
            ->willReturnCallback(function ($convId) use ($conv1, $conv2) {
                if ($convId === 'conv-1') {
                    return $conv1;
                }

                if ($convId === 'conv-2') {
                    return $conv2;
                }

                return null;
            });

        $this->em->method('getRepository')
            ->willReturn($conversationRepo);

        $this->metricsCollector->method('collect')
            ->willReturn($metrics);

        $this->eventDispatcher->expects($this->exactly(2))
            ->method('dispatch');

        // Act — new signature takes items with per-conv reason
        $closedCount = $this->service->closeConversationsBatch([
            ['conv_id' => 'conv-1', 'reason' => 'inactivity (>48h)'],
            ['conv_id' => 'conv-2', 'reason' => 'max_turns (25/25)'],
        ]);

        // Assert
        $this->assertSame(2, $closedCount);
    }

    public function testCloseConversationsBatchHandlesPartialFailure(): void
    {
        // Arrange
        $conv1 = $this->createMockConversation(
            convId: 'conv-success',
            status: ConversationStatus::OPEN,
            scamTypeCode: 'PHISHING',
            personaCode: 'persona_1',
            turnsCount: 5
        );

        $metrics = new ConversationMetrics(100, 2, 1, true);

        $conversationRepo = $this->createMock(EntityRepository::class);
        $conversationRepo->method('find')
            ->willReturnCallback(function ($convId) use ($conv1) {
                if ($convId === 'conv-success') {
                    return $conv1;
                }

                throw new \RuntimeException('Conversation not found');
            });

        $this->em->method('getRepository')
            ->willReturn($conversationRepo);

        $this->metricsCollector->method('collect')
            ->willReturn($metrics);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to close conversation in batch'),
                $this->arrayHasKey('conv_id')
            );

        // Act — new signature
        $closedCount = $this->service->closeConversationsBatch([
            ['conv_id' => 'conv-success', 'reason' => 'inactivity'],
            ['conv_id' => 'conv-fail', 'reason' => 'inactivity'],
        ]);

        // Assert
        $this->assertSame(1, $closedCount, 'Only successful closures should be counted');
    }

    public function testCloseConversationsBatchReturnsZeroForEmptyArray(): void
    {
        // Act
        $closedCount = $this->service->closeConversationsBatch([]);

        // Assert
        $this->assertSame(0, $closedCount);
    }

    // ====================================================================
    // Actor-aware closure + audit_log accuracy
    // ====================================================================

    /** @var list<AuditLog> Captured audit_log entities emitted during the test */
    private array $emittedAuditLogs = [];

    private function createAuditLoggerSpy(): AuditLogger
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($entity): void {
            if ($entity instanceof AuditLog) {
                $this->emittedAuditLogs[] = $entity;
            }
        });

        return new AuditLogger($em, new NullLogger(), new \App\Tests\Support\Audit\NullRequestContext(), new NullSiemExporter());
    }

    private function createServiceWithAuditSpy(): ConversationClosureService
    {
        $audit = $this->createAuditLoggerSpy();

        return new ConversationClosureService(
            $this->em,
            $this->metricsCollector,
            $this->eventDispatcher,
            $this->logger,
            $audit,
        );
    }

    private function setupOpenConvAndMetrics(string $convId, string $scamCode = 'PHISHING'): void
    {
        $conversation = $this->createMockConversation(
            convId: $convId,
            status: ConversationStatus::OPEN,
            scamTypeCode: $scamCode,
            personaCode: 'persona_x',
            turnsCount: 5,
        );

        $conversationRepo = $this->createMock(EntityRepository::class);
        $conversationRepo->method('find')->willReturn($conversation);
        $this->em->method('getRepository')->willReturn($conversationRepo);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(['turns' => 5, 'duration_sec' => 600]);
        $this->em->method('getConnection')->willReturn($conn);

        $this->metricsCollector->method('collect')->willReturn(
            new ConversationMetrics(600, 3, 1, true),
        );
    }

    public function testCloseConversationEmitsAuditWithProvidedActor(): void
    {
        $this->emittedAuditLogs = [];
        $service = $this->createServiceWithAuditSpy();
        $this->setupOpenConvAndMetrics('conv-fix15-cron');

        $service->closeConversation('conv-fix15-cron', 'inactivity (>48h)', 'cron', 'system');

        $closed = array_values(array_filter(
            $this->emittedAuditLogs,
            static fn (AuditLog $log): bool => $log->getEventType() === AuditEventType::CONVERSATION_CLOSED->value,
        ));
        $this->assertCount(1, $closed);
        $this->assertSame('system', $closed[0]->getActorType(), 'actor_type must be "system" for cron');
        $this->assertSame('cron', $closed[0]->getActorId(), 'actor_id must be the passed-in value, NOT conv_id');
        $this->assertSame('conv-fix15-cron', $closed[0]->getResourceId(), 'resource_id must still carry conv_id');
        $this->assertSame('inactivity (>48h)', $closed[0]->getDetails()['reason']);
    }

    public function testCloseConversationDefaultsPreserveLegacyUserActor(): void
    {
        // Regression guard — existing callers that don't pass actor info
        // still get actor_type='user' and reason='manual'.
        $this->emittedAuditLogs = [];
        $service = $this->createServiceWithAuditSpy();
        $this->setupOpenConvAndMetrics('conv-fix15-legacy');

        $service->closeConversation('conv-fix15-legacy');

        $closed = array_values(array_filter(
            $this->emittedAuditLogs,
            static fn (AuditLog $log): bool => $log->getEventType() === AuditEventType::CONVERSATION_CLOSED->value,
        ));
        $this->assertCount(1, $closed);
        $this->assertSame('user', $closed[0]->getActorType());
        $this->assertSame('user', $closed[0]->getActorId());
        $this->assertSame('manual', $closed[0]->getDetails()['reason']);
    }

    public function testCloseConversationsBatchPropagatesPerConvReason(): void
    {
        // 2 convs in batch with different reasons. Both audit rows must
        // carry their own reason, not the default 'manual'.
        $this->emittedAuditLogs = [];

        $conv1 = $this->createMockConversation('batch-1', ConversationStatus::OPEN, 'PHISHING', 'p1', 5);
        $conv2 = $this->createMockConversation('batch-2', ConversationStatus::OPEN, 'PHISHING', 'p2', 15);
        $conversationRepo = $this->createMock(EntityRepository::class);
        $conversationRepo->method('find')->willReturnCallback(
            static fn ($id) => $id === 'batch-1' ? $conv1 : ($id === 'batch-2' ? $conv2 : null),
        );
        $this->em->method('getRepository')->willReturn($conversationRepo);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(['turns' => 5, 'duration_sec' => 600]);
        $this->em->method('getConnection')->willReturn($conn);

        $this->metricsCollector->method('collect')->willReturn(
            new ConversationMetrics(600, 3, 1, true),
        );

        $service = $this->createServiceWithAuditSpy();
        $service->closeConversationsBatch([
            ['conv_id' => 'batch-1', 'reason' => 'inactivity (>48h)'],
            ['conv_id' => 'batch-2', 'reason' => 'max_turns (15/15)'],
        ], 'cron', 'system');

        $closed = array_values(array_filter(
            $this->emittedAuditLogs,
            static fn (AuditLog $log): bool => $log->getEventType() === AuditEventType::CONVERSATION_CLOSED->value,
        ));
        $this->assertCount(2, $closed);
        // Each row carries its own reason
        $reasons = array_map(static fn (AuditLog $log): string => $log->getDetails()['reason'], $closed);
        $this->assertContains('inactivity (>48h)', $reasons);
        $this->assertContains('max_turns (15/15)', $reasons);

        // Both rows carry the cron actor
        foreach ($closed as $log) {
            $this->assertSame('system', $log->getActorType());
            $this->assertSame('cron', $log->getActorId());
        }
    }

    // ====================================================================
    // Manual reopen (analyst UI action)
    // ====================================================================

    public function testReopenConversationReactivatesResetsRewardAndAudits(): void
    {
        $this->emittedAuditLogs = [];
        $service = $this->createServiceWithAuditSpy();

        $conversation = $this->createMockConversation('conv-reopen', ConversationStatus::CLOSED, 'PHISHING', 'persona_x', 12);
        $conversation->expects($this->once())->method('reopen');
        $conversation->expects($this->once())->method('resetRewardValue');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->with('conv-reopen')->willReturn($conversation);
        $this->em->method('getRepository')->willReturn($repo);

        $this->em->expects($this->once())->method('flush');
        // Reopen must NOT feed the bandit — no ConversationEndedEvent.
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $service->reopenConversation('conv-reopen', 'alice', 'user');

        $reopened = array_values(array_filter(
            $this->emittedAuditLogs,
            static fn (AuditLog $log): bool => $log->getEventType() === AuditEventType::CONVERSATION_REOPENED->value,
        ));
        $this->assertCount(1, $reopened);
        $this->assertSame('alice', $reopened[0]->getActorId());
        $this->assertSame('conversation', $reopened[0]->getResourceType());
        $this->assertSame('conv-reopen', $reopened[0]->getResourceId());
        $this->assertSame('user', $reopened[0]->getActorType());
        $this->assertSame('closed', $reopened[0]->getDetails()['previous_status']);
    }

    public function testReopenConversationAllowsAbandonedStatus(): void
    {
        $conversation = $this->createMockConversation('conv-abandoned', ConversationStatus::ABANDONED, 'PHISHING', 'p', 4);
        $conversation->expects($this->once())->method('reopen');
        $conversation->expects($this->once())->method('resetRewardValue');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($conversation);
        $this->em->method('getRepository')->willReturn($repo);
        $this->em->expects($this->once())->method('flush');

        $this->service->reopenConversation('conv-abandoned');
    }

    public function testReopenConversationIsIdempotentWhenAlreadyOpen(): void
    {
        $conversation = $this->createMockConversation('conv-open', ConversationStatus::OPEN, 'PHISHING', 'p', 3);
        $conversation->expects($this->never())->method('reopen');
        $conversation->expects($this->never())->method('resetRewardValue');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($conversation);
        $this->em->method('getRepository')->willReturn($repo);

        $this->em->expects($this->never())->method('flush');
        $this->eventDispatcher->expects($this->never())->method('dispatch');
        $this->logger->expects($this->once())->method('warning');

        $this->service->reopenConversation('conv-open');
    }

    public function testReopenConversationRejectsMistakeStatus(): void
    {
        $conversation = $this->createMockConversation('conv-mistake', ConversationStatus::MISTAKE, 'PHISHING', 'p', 1);
        $conversation->expects($this->never())->method('reopen');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($conversation);
        $this->em->method('getRepository')->willReturn($repo);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\RuntimeException::class);
        $this->service->reopenConversation('conv-mistake');
    }

    public function testReopenConversationThrowsWhenNotFound(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn(null);
        $this->em->method('getRepository')->willReturn($repo);

        $this->expectException(\RuntimeException::class);
        $this->service->reopenConversation('missing');
    }

    public function testCloseConversationBlendsRewardWithLlmJudge(): void
    {
        // With a RewardJudge injected, the reward carried on the event is the
        // hybrid (LLM outcome blended with the mechanical reward), not the raw
        // mechanical value. A 0.0 outcome pulls the reward well below mechanical.
        $llm = $this->createMock(LLMClientInterface::class);
        $llm->method('chat')->willReturn('{"outcome_score": 0.0, "reason": "burned"}');
        $judge = new RewardJudge($llm, new NullLogger(), new PromptProvider('/nonexistent-prompt-dir', new NullLogger()), 0.7);

        $service = new ConversationClosureService(
            $this->em,
            $this->metricsCollector,
            $this->eventDispatcher,
            $this->logger,
            null,
            $judge,
        );

        $conversation = $this->createMockConversation('conv-hybrid', ConversationStatus::OPEN, 'PHISHING', 'p', 5);
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($conversation);
        $this->em->method('getRepository')->willReturn($repo);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(['turns' => 5, 'duration_sec' => 600]);
        $conn->method('fetchAllAssociative')->willReturn([
            ['direction' => 1, 'body_text' => 'pay me now'],
            ['direction' => 2, 'body_text' => 'sure, how?'],
        ]);
        $this->em->method('getConnection')->willReturn($conn);

        $metrics = new ConversationMetrics(600, 3, 1, true);
        $this->metricsCollector->method('collect')->willReturn($metrics);
        $mechanical = $metrics->calculateReward();

        $captured = null;
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (ConversationEndedEvent $e) use (&$captured): bool {
                $captured = $e->getRewardOverride();

                return true;
            }));

        $service->closeConversation('conv-hybrid');

        $this->assertNotNull($captured);
        $this->assertEqualsWithDelta(0.3 * $mechanical, $captured, 0.0001);
        $this->assertLessThan($mechanical, $captured);
    }

    private function createMockConversation(
        string $convId,
        ConversationStatus $status,
        string $scamTypeCode,
        ?string $personaCode,
        int $turnsCount
    ): Conversation {
        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getConvId')->willReturn($convId);
        $conversation->method('getStatus')->willReturn($status);
        $conversation->method('getTurnsCount')->willReturn($turnsCount);
        $conversation->method('getDeletedAt')->willReturn(null);
        $conversation->method('setRewardValue')->willReturnSelf();

        $scamType = $this->createMock(ScamType::class);
        $scamType->method('getCode')->willReturn($scamTypeCode);
        $conversation->method('getScamType')->willReturn($scamType);

        if ($personaCode !== null) {
            $persona = $this->createMock(Persona::class);
            $persona->method('getPersonaCode')->willReturn($personaCode);
            $conversation->method('getPersona')->willReturn($persona);
        } else {
            $conversation->method('getPersona')->willReturn(null);
        }

        return $conversation;
    }
}

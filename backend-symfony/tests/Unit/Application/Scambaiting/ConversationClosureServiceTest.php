<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Scambaiting;

use App\Application\Scambaiting\ConversationClosureService;
use App\Application\Scambaiting\ConversationMetricsCollector;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Domain\Scambaiting\ConversationMetrics;
use App\Domain\Scambaiting\Event\ConversationEndedEvent;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

        // Act
        $closedCount = $this->service->closeConversationsBatch(['conv-1', 'conv-2']);

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

        // Act
        $closedCount = $this->service->closeConversationsBatch(['conv-success', 'conv-fail']);

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

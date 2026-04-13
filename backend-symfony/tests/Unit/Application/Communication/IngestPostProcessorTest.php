<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IngestPostProcessor;
use App\Application\Communication\IocHandler;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Message;
use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for IngestPostProcessor — focuses on branches that don't need
 * final-class mocking. Rate limiter / flood / clustering tests require
 * integration tests since those dependencies are final.
 */
class IngestPostProcessorTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private IocHandler&MockObject $iocHandler;
    private NullLogger $logger;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->iocHandler = $this->createMock(IocHandler::class);
        $this->logger = new NullLogger();
    }

    private function createProcessor(): IngestPostProcessor
    {
        return new IngestPostProcessor(
            em: $this->em,
            logger: $this->logger,
            iocHandler: $this->iocHandler,
        );
    }

    // --- checkSenderRateLimits tests ---

    public function test_checkSenderRateLimits_returns_false_when_from_is_null(): void
    {
        $processor = $this->createProcessor();
        $this->assertFalse($processor->checkSenderRateLimits(null, 'conv-123'));
    }

    public function test_checkSenderRateLimits_returns_false_when_from_is_empty(): void
    {
        $processor = $this->createProcessor();
        $this->assertFalse($processor->checkSenderRateLimits('', 'conv-123'));
    }

    public function test_checkSenderRateLimits_returns_false_when_no_limiters(): void
    {
        $processor = $this->createProcessor();
        $this->assertFalse($processor->checkSenderRateLimits('scammer@example.com', 'conv-123'));
    }

    public function test_checkSenderRateLimits_returns_false_with_angle_bracket_email(): void
    {
        $processor = $this->createProcessor();
        $this->assertFalse($processor->checkSenderRateLimits('<scammer@example.com>', 'conv-123'));
    }

    // --- processAfterIngest tests ---

    public function test_processAfterIngest_header_ioc_extraction_error_is_swallowed(): void
    {
        $this->iocHandler->method('extractAndUpsertHeaderIocs')
            ->willThrowException(new \RuntimeException('DB error'));

        $message = $this->createMessageMock('msg-1', 'conv-1');
        $conversation = $this->createConversationMock('conv-1', 'PHISHING');

        $processor = $this->createProcessor();
        // Should not throw
        $processor->processAfterIngest($message, $conversation, 'en');
        $this->assertTrue(true);
    }

    public function test_processAfterIngest_ioc_context_null_service_skipped(): void
    {
        $this->iocHandler->method('extractAndUpsertHeaderIocs')->willReturn(0);
        $this->em->method('getRepository')->willReturn($this->createEmptyRepo());

        $message = $this->createMessageMock('msg-1', 'conv-1');
        $conversation = $this->createConversationMock('conv-1', 'UNKNOWN');

        // No iocContextService -> computeIocContext skipped
        $processor = $this->createProcessor();
        $processor->processAfterIngest($message, $conversation, 'en');
        $this->assertTrue(true);
    }

    public function test_processAfterIngest_runs_without_optional_services(): void
    {
        $this->iocHandler->method('extractAndUpsertHeaderIocs')->willReturn(3);
        $this->em->method('getRepository')->willReturn($this->createEmptyRepo());

        $message = $this->createMessageMock('msg-1', 'conv-1');
        $conversation = $this->createConversationMock('conv-1', 'PHISHING');

        // All optional services are null
        $processor = $this->createProcessor();
        $processor->processAfterIngest($message, $conversation, 'en');
        $this->assertTrue(true);
    }

    public function test_processAfterIngest_risk_score_not_updated_when_lower(): void
    {
        $this->iocHandler->method('extractAndUpsertHeaderIocs')->willReturn(0);
        $repo = $this->createEmptyRepo();
        $this->em->method('getRepository')->willReturn($repo);

        $message = $this->createMessageMock('msg-1', 'conv-1');
        // Conversation already has high risk score
        $conversation = $this->createConversationMock('conv-1', 'UNKNOWN', scoreRisk: 100);
        $conversation->expects($this->never())->method('updateRiskScore');

        $processor = $this->createProcessor();
        $processor->processAfterIngest($message, $conversation, 'en');
    }

    // --- Helper methods ---

    private function createMessageMock(string $msgId, string $convId): Message&MockObject
    {
        $message = $this->createMock(Message::class);
        $message->method('getMsgId')->willReturn($msgId);
        $conversation = $this->createConversationMock($convId, 'PHISHING');
        $message->method('getConversation')->willReturn($conversation);
        return $message;
    }

    private function createConversationMock(string $convId, string $scamTypeCode, int $scoreRisk = 30): Conversation&MockObject
    {
        $scamType = $this->createMock(ScamType::class);
        $scamType->method('getCode')->willReturn($scamTypeCode);

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getConvId')->willReturn($convId);
        $conversation->method('getScamType')->willReturn($scamType);
        $conversation->method('getScoreRisk')->willReturn($scoreRisk);
        $conversation->method('getStatus')->willReturn(ConversationStatus::OPEN);
        return $conversation;
    }

    private function createEmptyRepo(): EntityRepository&MockObject
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn([]);
        return $repo;
    }
}

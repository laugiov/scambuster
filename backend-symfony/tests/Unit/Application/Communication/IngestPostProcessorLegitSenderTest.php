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
 * Tests for F1: Legitimate sender safelist in IngestPostProcessor.
 *
 * Verifies that messages from known legitimate domains (Instagram, Facebook, etc.)
 * have their conversation risk set to 0 and skip classification.
 */
final class IngestPostProcessorLegitSenderTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private IocHandler&MockObject $iocHandler;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->iocHandler = $this->createMock(IocHandler::class);
    }

    private function createProcessor(): IngestPostProcessor
    {
        return new IngestPostProcessor(
            em: $this->em,
            logger: new NullLogger(),
            iocHandler: $this->iocHandler,
        );
    }

    /**
     * mail.instagram.com is a subdomain of instagram.com -> legitimate.
     */
    public function test_subdomain_of_legitimate_domain_is_legitimate(): void
    {
        $this->iocHandler->method('extractAndUpsertHeaderIocs')->willReturn(0);
        $this->em->method('getRepository')->willReturn($this->createEmptyRepo());

        $message = $this->createMessageWithHeaders('msg-1', 'conv-1', [
            'from' => 'noreply@mail.instagram.com',
        ]);

        $conversation = $this->createConversationMock('conv-1', 'UNKNOWN');
        // Legitimate sender: risk forced to 0
        $conversation->expects($this->once())->method('updateRiskScore')->with(0);
        $this->em->expects($this->atLeastOnce())->method('flush');

        $processor = $this->createProcessor();
        $processor->processAfterIngest($message, $conversation, 'en');
    }

    /**
     * evil-instagram.com is NOT a subdomain of instagram.com -> not legitimate.
     */
    public function test_lookalike_domain_is_not_legitimate(): void
    {
        $this->iocHandler->method('extractAndUpsertHeaderIocs')->willReturn(0);
        $this->em->method('getRepository')->willReturn($this->createEmptyRepo());

        $message = $this->createMessageWithHeaders('msg-2', 'conv-2', [
            'from' => 'scammer@evil-instagram.com',
        ]);

        $conversation = $this->createConversationMock('conv-2', 'UNKNOWN');
        // Not legitimate: updateRiskScore should NOT be called with 0
        // (it may be called with a computed value, but never 0 forced)
        $conversation->expects($this->never())->method('updateRiskScore')->with(0);

        $processor = $this->createProcessor();
        $processor->processAfterIngest($message, $conversation, 'en');
    }

    /**
     * facebookmail.com is an exact match -> legitimate.
     */
    public function test_exact_legitimate_domain_is_legitimate(): void
    {
        $this->iocHandler->method('extractAndUpsertHeaderIocs')->willReturn(0);
        $this->em->method('getRepository')->willReturn($this->createEmptyRepo());

        $message = $this->createMessageWithHeaders('msg-3', 'conv-3', [
            'from' => 'notification@facebookmail.com',
        ]);

        $conversation = $this->createConversationMock('conv-3', 'UNKNOWN');
        $conversation->expects($this->once())->method('updateRiskScore')->with(0);
        $this->em->expects($this->atLeastOnce())->method('flush');

        $processor = $this->createProcessor();
        $processor->processAfterIngest($message, $conversation, 'en');
    }

    /**
     * phishing.google.com.evil.test: the domain after @ is "evil.test", NOT google.com.
     */
    public function test_spoofed_domain_is_not_legitimate(): void
    {
        $this->iocHandler->method('extractAndUpsertHeaderIocs')->willReturn(0);
        $this->em->method('getRepository')->willReturn($this->createEmptyRepo());

        $message = $this->createMessageWithHeaders('msg-4', 'conv-4', [
            'from' => 'admin@phishing.google.com.evil.test',
        ]);

        $conversation = $this->createConversationMock('conv-4', 'UNKNOWN');
        $conversation->expects($this->never())->method('updateRiskScore')->with(0);

        $processor = $this->createProcessor();
        $processor->processAfterIngest($message, $conversation, 'en');
    }

    // --- Helpers ---

    private function createMessageWithHeaders(string $msgId, string $convId, array $headers): Message&MockObject
    {
        $message = $this->createMock(Message::class);
        $message->method('getMsgId')->willReturn($msgId);
        $message->method('getHeaders')->willReturn($headers);
        $message->method('getBodyText')->willReturn('Test body');
        $message->method('getSubject')->willReturn('Test subject');

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

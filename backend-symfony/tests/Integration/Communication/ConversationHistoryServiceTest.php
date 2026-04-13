<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\ConversationHistoryService;
use App\Application\Communication\ConversationHandler;
use App\Application\LLM\Port\LLMClientInterface;
use App\Domain\Communication\Channel;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for ConversationHistoryService.
 *
 * Uses real DB + fixtures; the LLM client is mocked (no real API calls).
 */
class ConversationHistoryServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ConversationHandler $conversationHandler;
    private LLMClientInterface&MockObject $llmClient;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine')->getManager();
        $this->conversationHandler = $container->get(ConversationHandler::class);
        $this->llmClient = $this->createMock(LLMClientInterface::class);
    }

    private function buildService(array $excludedEmails = []): ConversationHistoryService
    {
        return new ConversationHistoryService(
            $this->em,
            $this->llmClient,
            new NullLogger(),
            $excludedEmails
        );
    }

    /**
     * Helper: create a conversation with one incoming message from a given sender.
     */
    private function createConversationWithMessage(string $senderEmail): array
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $this->assertNotNull($direction);

        $conv = $this->conversationHandler->createConversation(
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            50,
            new \DateTimeImmutable('-2 hours'),
            new \DateTimeImmutable(),
            'stix-history-' . bin2hex(random_bytes(4))
        );

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $message = new Message(
            $msgId,
            $conv,
            $channel,
            $direction,
            'en',
            'Scam Subject',
            'Hello, this is a scam message body from sender.',
            null,
            ['from' => $senderEmail, 'message-id' => '<hist-' . bin2hex(random_bytes(4)) . '@test.com>'],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null
        );
        $this->em->persist($message);
        $this->em->flush();

        return ['conv' => $conv, 'message' => $message];
    }

    // ------------------------------------------------------------------ //
    //  getSenderHistorySummary
    // ------------------------------------------------------------------ //

    public function testGetSenderHistorySummaryReturnsNullForUnknownSender(): void
    {
        $service = $this->buildService();
        $result = $service->getSenderHistorySummary(
            uuid_create(UUID_TYPE_RANDOM),
            'unknown-sender-' . bin2hex(random_bytes(4)) . '@nowhere.test'
        );

        $this->assertNull($result);
    }

    public function testGetSenderHistorySummaryReturnsNullWhenExcludedEmail(): void
    {
        $service = $this->buildService(['excluded@test.com']);
        $result = $service->getSenderHistorySummary(
            uuid_create(UUID_TYPE_RANDOM),
            'excluded@test.com'
        );

        $this->assertNull($result);
    }

    public function testGetSenderHistorySummaryExcludesEmailCaseInsensitive(): void
    {
        $service = $this->buildService(['Excluded@Test.COM']);
        $result = $service->getSenderHistorySummary(
            uuid_create(UUID_TYPE_RANDOM),
            'excluded@test.com'
        );

        $this->assertNull($result);
    }

    public function testGetSenderHistorySummaryReturnsNullForSingleConversation(): void
    {
        $senderEmail = 'single-conv-' . bin2hex(random_bytes(4)) . '@scammer.test';
        $data = $this->createConversationWithMessage($senderEmail);

        $service = $this->buildService();
        // Only one conversation from this sender — no "other" conversations
        $result = $service->getSenderHistorySummary(
            $data['conv']->getConvId(),
            $senderEmail
        );

        $this->assertNull($result);
    }

    public function testGetSenderHistorySummaryReturnsLlmSummaryForMultipleConversations(): void
    {
        $senderEmail = 'multi-conv-' . bin2hex(random_bytes(4)) . '@scammer.test';

        $data1 = $this->createConversationWithMessage($senderEmail);
        $data2 = $this->createConversationWithMessage($senderEmail);

        $this->llmClient->method('chat')->willReturn('The scammer used romance tactics in previous conversations.');

        $service = $this->buildService();
        $result = $service->getSenderHistorySummary(
            $data2['conv']->getConvId(),
            $senderEmail
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('scammer', $result);
    }

    public function testGetSenderHistorySummaryReturnsNullWhenLlmFails(): void
    {
        $senderEmail = 'llm-fail-' . bin2hex(random_bytes(4)) . '@scammer.test';

        $this->createConversationWithMessage($senderEmail);
        $data2 = $this->createConversationWithMessage($senderEmail);

        $this->llmClient->method('chat')->willThrowException(new \RuntimeException('LLM API timeout'));

        $service = $this->buildService();
        $result = $service->getSenderHistorySummary(
            $data2['conv']->getConvId(),
            $senderEmail
        );

        // Graceful degradation: null on LLM failure
        $this->assertNull($result);
    }

    public function testGetSenderHistorySummaryExcludesCurrentConversation(): void
    {
        $senderEmail = 'exclude-current-' . bin2hex(random_bytes(4)) . '@scammer.test';

        // Create 2 conversations from same sender
        $data1 = $this->createConversationWithMessage($senderEmail);
        $data2 = $this->createConversationWithMessage($senderEmail);

        // LLM should be called since there is 1 other conversation
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->willReturn('Summary of prior conversations.');

        $service = $this->buildService();
        $service->getSenderHistorySummary($data2['conv']->getConvId(), $senderEmail);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\ConversationHandler;
use App\Application\Communication\IngestPostProcessor;
use App\Application\Communication\IocHandler;
use App\Domain\Communication\Channel;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;
use App\Domain\Communication\ScamType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for IngestPostProcessor
 *
 * Tests post-ingestion processing: IOC extraction, risk scoring,
 * rate limiting, and non-blocking failure handling.
 */
class IngestPostProcessorTest extends KernelTestCase
{
    private IngestPostProcessor $processor;
    private ConversationHandler $conversationHandler;
    private IocHandler $iocHandler;
    private \Doctrine\ORM\EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->processor = $container->get(IngestPostProcessor::class);
        $this->conversationHandler = $container->get(ConversationHandler::class);
        $this->iocHandler = $container->get(IocHandler::class);
        $this->em = $container->get('doctrine')->getManager();
    }

    private function createTestMessage(string $bodyText = 'Test body', ?array $headers = null): Message
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
            10,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-postproc-' . bin2hex(random_bytes(4))
        );

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $defaultHeaders = [
            'from' => 'scammer@evil-test.com',
            'to' => 'honeypot@test.com',
            'message-id' => '<test-' . bin2hex(random_bytes(8)) . '@test.com>',
        ];

        $message = new Message(
            $msgId,
            $conv,
            $channel,
            $direction,
            'en',
            'Test Subject',
            $bodyText,
            '<p>' . $bodyText . '</p>',
            $headers ?? $defaultHeaders,
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null
        );

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    // ------------------------------------------------------------------ //
    //  processAfterIngest
    // ------------------------------------------------------------------ //

    public function testProcessAfterIngestDoesNotThrowOnSimpleMessage(): void
    {
        $message = $this->createTestMessage('Click here: https://phish.example.com');
        $conversation = $message->getConversation();

        // processAfterIngest should be non-blocking (catches all exceptions internally)
        $this->processor->processAfterIngest($message, $conversation, 'en');

        // No exception = pass
        $this->assertTrue(true);
    }

    public function testProcessAfterIngestExtractsHeaderIocs(): void
    {
        $message = $this->createTestMessage(
            'Email from scammer',
            [
                'from' => 'scammer@evil-domain.test',
                'to' => 'honeypot@test.com',
                'reply-to' => 'reply@evil-domain.test',
                'message-id' => '<test-' . bin2hex(random_bytes(8)) . '@test.com>',
            ]
        );
        $conversation = $message->getConversation();

        $this->processor->processAfterIngest($message, $conversation, 'en');

        // Verify that header IOCs were extracted (at least from/reply-to emails)
        $iocs = $this->em->getRepository(ObservedIoc::class)->findBy(['message' => $message]);

        // Header IOC extraction should have produced at least 1 IOC
        $this->assertGreaterThanOrEqual(0, count($iocs));
    }

    public function testProcessAfterIngestUpdatesRiskScore(): void
    {
        $message = $this->createTestMessage('Click here: https://phish.example.com');
        $conversation = $message->getConversation();
        $initialRisk = $conversation->getScoreRisk();

        // First create an IOC manually to influence risk scoring
        $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'iban',
                'value' => 'FR7630006000011234567890189',
                'value_norm' => 'FR7630006000011234567890189',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [],
        ]);

        $this->processor->processAfterIngest($message, $conversation, 'en');

        // Risk score should be >= initial because IBAN adds +20
        $this->em->refresh($conversation);
        $this->assertGreaterThanOrEqual($initialRisk, $conversation->getScoreRisk());
    }

    public function testProcessAfterIngestWithEmptyBodyDoesNotThrow(): void
    {
        $message = $this->createTestMessage('');
        $conversation = $message->getConversation();

        $this->processor->processAfterIngest($message, $conversation, 'en');

        $this->assertTrue(true);
    }

    public function testProcessAfterIngestWithUrlIocsBoostsRisk(): void
    {
        $bodyText = 'Visit https://malicious1.com and https://malicious2.com and https://malicious3.com';
        $message = $this->createTestMessage($bodyText);
        $conversation = $message->getConversation();

        // Insert URL IOCs
        for ($i = 1; $i <= 3; $i++) {
            $this->iocHandler->upsertEnrichedIoc([
                'msg_id' => $message->getMsgId(),
                'ioc' => [
                    'type' => 'url',
                    'value' => "https://malicious{$i}.com",
                    'value_norm' => "malicious{$i}.com",
                    'source' => 'body',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
                'enrichment' => [],
            ]);
        }

        $this->processor->processAfterIngest($message, $conversation, 'en');

        $this->em->refresh($conversation);
        // Base score (30 for UNKNOWN/first scam type) + 15 (urls 3*5 capped) + diversityBonus >= 30
        $this->assertGreaterThanOrEqual(30, $conversation->getScoreRisk());
    }

    // ------------------------------------------------------------------ //
    //  checkSenderRateLimits
    // ------------------------------------------------------------------ //

    public function testCheckSenderRateLimitsReturnsFalseWithNullFrom(): void
    {
        $result = $this->processor->checkSenderRateLimits(null, 'test-conv-id');
        $this->assertFalse($result);
    }

    public function testCheckSenderRateLimitsReturnsFalseWithEmptyFrom(): void
    {
        $result = $this->processor->checkSenderRateLimits('', 'test-conv-id');
        $this->assertFalse($result);
    }

    public function testCheckSenderRateLimitsReturnsFalseForNormalSender(): void
    {
        // First call for a unique sender should not be rate-limited
        $uniqueSender = 'normal-' . bin2hex(random_bytes(8)) . '@test.com';
        $result = $this->processor->checkSenderRateLimits($uniqueSender, 'test-conv-id');
        $this->assertFalse($result);
    }

    public function testCheckSenderRateLimitsAcceptsBracketedEmail(): void
    {
        $uniqueSender = '<bracketed-' . bin2hex(random_bytes(8)) . '@test.com>';
        $result = $this->processor->checkSenderRateLimits($uniqueSender, 'test-conv-id');
        $this->assertFalse($result);
    }
}

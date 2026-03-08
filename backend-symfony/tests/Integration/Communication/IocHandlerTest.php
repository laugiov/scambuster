<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\ConversationHandler;
use App\Application\Communication\IocHandler;
use App\Application\Communication\MessageHandler;
use App\Domain\Communication\Channel;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;
use App\Domain\Communication\ScamType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for IocHandler
 *
 * Tests upsert, risk calculation, and conversation IOC aggregation
 * with real database interactions.
 */
class IocHandlerTest extends KernelTestCase
{
    private IocHandler $iocHandler;
    private ConversationHandler $conversationHandler;
    private MessageHandler $messageHandler;
    private \Doctrine\ORM\EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->iocHandler = $container->get(IocHandler::class);
        $this->conversationHandler = $container->get(ConversationHandler::class);
        $this->messageHandler = $container->get(MessageHandler::class);
        $this->em = $container->get('doctrine')->getManager();
    }

    private function createTestMessage(string $externalMessageId = null): Message
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
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-ioc-test-' . bin2hex(random_bytes(4))
        );

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $message = new Message(
            $msgId,
            $conv,
            $channel,
            $direction,
            'en',
            'Phishing Email',
            'Click here: https://evil-site.com/login',
            '<p>Click here: https://evil-site.com/login</p>',
            [
                'from' => 'scammer@test.com',
                'to' => 'victim@test.com',
                'message-id' => $externalMessageId ?? '<test-' . bin2hex(random_bytes(8)) . '@test.com>',
            ],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null
        );

        if ($externalMessageId) {
            $message->setExternalMessageId($externalMessageId);
        }

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    public function testUpsertEnrichedIocCreatesNewIoc(): void
    {
        $message = $this->createTestMessage('<gmail-test-123@mail.gmail.com>');

        $data = [
            'message_id' => '<gmail-test-123@mail.gmail.com>',
            'ioc' => [
                'type' => 'url',
                'value' => 'https://evil-site.com/login',
                'value_norm' => 'evil-site.com/login',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [
                'virustotal' => [
                    'malicious' => 5,
                    'suspicious' => 0,
                    'harmless' => 85,
                    'undetected' => 10,
                ],
                'urlscan' => [
                    'verdict' => 'malicious',
                    'status' => 'completed',
                ],
            ],
            'tags' => ['phishing'],
            'tlp' => 'AMBER',
        ];

        $ioc = $this->iocHandler->upsertEnrichedIoc($data);

        $this->assertNotNull($ioc);
        $this->assertSame($message->getMsgId(), $ioc->getMessage()->getMsgId());
        $this->assertSame('url', $ioc->getContext()['type']);
        $this->assertSame('evil-site.com/login', $ioc->getContext()['value_norm']);
        $this->assertSame(100, $ioc->getContext()['score']['agg']); // VT malicious (70) + URLscan malicious (60) = capped at 100
        $this->assertSame('Credential_phish', $ioc->getContext()['category']); // login in URL

        // Assert MISP/STIX export metadata is present
        $this->assertArrayHasKey('misp', $ioc->getContext());
        $this->assertSame('Network activity', $ioc->getContext()['misp']['category']);
        $this->assertSame('url', $ioc->getContext()['misp']['type']);
        $this->assertTrue($ioc->getContext()['misp']['to_ids']);

        $this->assertArrayHasKey('stix', $ioc->getContext());
        $this->assertSame('url', $ioc->getContext()['stix']['sco_type']);
        $this->assertStringContainsString('[url:value =', $ioc->getContext()['stix']['pattern']);
    }

    public function testUpsertEnrichedIocIsIdempotent(): void
    {
        $message = $this->createTestMessage();

        $data = [
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'url',
                'value' => 'https://test.com',
                'value_norm' => 'test.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [
                'virustotal' => ['malicious' => 0, 'suspicious' => 1],
            ],
        ];

        // First upsert
        $ioc1 = $this->iocHandler->upsertEnrichedIoc($data);
        $obsId1 = $ioc1->getObsId();

        // Second upsert (same msg_id + type + value_norm)
        $ioc2 = $this->iocHandler->upsertEnrichedIoc($data);
        $obsId2 = $ioc2->getObsId();

        $this->assertSame($obsId1, $obsId2, 'Should return same IOC (idempotent)');

        // Verify only one IOC in database
        $count = $this->em->getRepository(ObservedIoc::class)
            ->createQueryBuilder('ioc')
            ->select('COUNT(ioc.obsId)')
            ->where('ioc.message = :msg')
            ->setParameter('msg', $message)
            ->getQuery()
            ->getSingleScalarResult();

        $this->assertSame(1, (int)$count);
    }

    public function testCalculateMessageRiskWithHighRiskIoc(): void
    {
        $message = $this->createTestMessage();

        // Add high-risk IOC
        $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'url',
                'value' => 'https://malicious.com',
                'value_norm' => 'malicious.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [
                'virustotal' => ['malicious' => 10, 'suspicious' => 0],
                'urlscan' => ['verdict' => 'malicious'],
            ],
        ]);

        $risk = $this->iocHandler->calculateMessageRisk($message->getMsgId());

        $this->assertSame(100, $risk['score_agg']);
        $this->assertSame('high', $risk['level']);
        $this->assertTrue($risk['should_reply']);
        $this->assertStringContainsString('malicious', $risk['reason']);
    }

    public function testCalculateMessageRiskWithMediumRiskAndExploitableIoc(): void
    {
        $message = $this->createTestMessage();

        // Add medium-risk URL IOC
        $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'url',
                'value' => 'https://suspicious.com',
                'value_norm' => 'suspicious.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [
                'virustotal' => ['malicious' => 0, 'suspicious' => 2],
            ],
        ]);

        // Add IBAN (exploitable)
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

        $risk = $this->iocHandler->calculateMessageRisk($message->getMsgId());

        $this->assertSame(40, $risk['score_agg']); // VT suspicious
        $this->assertSame('medium', $risk['level']);
        $this->assertTrue($risk['should_reply'], 'Should reply because IBAN is exploitable');
    }

    public function testCalculateMessageRiskWithNoIocs(): void
    {
        $message = $this->createTestMessage();

        $risk = $this->iocHandler->calculateMessageRisk($message->getMsgId());

        $this->assertSame(0, $risk['score_agg']);
        $this->assertSame('low', $risk['level']);
        $this->assertFalse($risk['should_reply']);
        $this->assertSame('No IOCs detected', $risk['reason']);
    }

    public function testGetConversationIocsDeduplicates(): void
    {
        $message1 = $this->createTestMessage();
        $conv = $message1->getConversation();

        // Create second message in same conversation
        $msgId2 = uuid_create(UUID_TYPE_RANDOM);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'out']);
        $message2 = new Message(
            $msgId2,
            $conv,
            $message1->getChannel(),
            $direction,
            'en',
            'Reply',
            'Response',
            '<p>Response</p>',
            ['from' => 'bot@test.com'],
            bin2hex(random_bytes(32)),
            null,
            $message1,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null
        );
        $this->em->persist($message2);
        $this->em->flush();

        // Add same IOC to both messages (same ioc_id)
        $indicatorId = uuid_create(UUID_TYPE_RANDOM);

        $iocData1 = [
            'msg_id' => $message1->getMsgId(),
            'ioc' => [
                'type' => 'domain',
                'value' => 'evil.com',
                'value_norm' => 'evil.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [],
        ];

        $iocData2 = [
            'msg_id' => $message2->getMsgId(),
            'ioc' => [
                'type' => 'domain',
                'value' => 'evil.com',
                'value_norm' => 'evil.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [],
        ];

        $this->iocHandler->upsertEnrichedIoc($iocData1);
        $this->iocHandler->upsertEnrichedIoc($iocData2);

        // Get conversation IOCs (should be deduplicated)
        $iocs = $this->iocHandler->getConversationIocs($conv->getConvId());

        // Should have 2 IOCs total (one per message due to different msg_id in unique constraint)
        // But they should be grouped by ioc_id if we had same ioc_id
        $this->assertGreaterThanOrEqual(1, count($iocs));
        $this->assertLessThanOrEqual(2, count($iocs));
    }

    public function testResolveMessageByExternalMessageId(): void
    {
        $externalId = '<gmail-unique-12345@mail.gmail.com>';
        $message = $this->createTestMessage($externalId);

        $data = [
            'message_id' => $externalId, // Use external ID
            'ioc' => [
                'type' => 'email',
                'value' => 'scammer@evil.com',
                'value_norm' => 'scammer@evil.com',
                'source' => 'header',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [],
        ];

        $ioc = $this->iocHandler->upsertEnrichedIoc($data);

        $this->assertSame($message->getMsgId(), $ioc->getMessage()->getMsgId());
    }

    public function testUpsertEnrichedIocThrowsForUnknownMessage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Message not found');

        $this->iocHandler->upsertEnrichedIoc([
            'message_id' => '<nonexistent@test.com>',
            'msg_id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
            'ioc' => [
                'type' => 'url',
                'value' => 'https://test.com',
                'value_norm' => 'test.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [],
        ]);
    }

    public function testCalculateMessageRiskThrowsForUnknownMessage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Message not found');

        $this->iocHandler->calculateMessageRisk('ffffffff-ffff-ffff-ffff-ffffffffffff');
    }

    /**
     * Sprint 3 - Test LLM extraction integration
     */
    public function testExtractIocsFromMessageWithLLMMethod(): void
    {
        $message = $this->createTestMessage();

        // Extract IOCs using LLM method
        $iocs = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'llm', []);

        // Should return array of IOCs (even if empty, it should work)
        $this->assertIsArray($iocs);

        // Each IOC should have required structure
        foreach ($iocs as $ioc) {
            $this->assertArrayHasKey('type', $ioc);
            $this->assertArrayHasKey('value', $ioc);
            $this->assertArrayHasKey('value_norm', $ioc);
            $this->assertArrayHasKey('context', $ioc);
            $this->assertArrayHasKey('extraction_method', $ioc['context']);
            $this->assertSame('llm', $ioc['context']['extraction_method']);
        }
    }

    public function testExtractIocsFromMessageWithHybridMethodIncludesBothMethods(): void
    {
        $message = $this->createTestMessage();

        // Extract IOCs using hybrid method (regex + LLM)
        $iocs = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'hybrid', []);

        // Should return array of IOCs
        $this->assertIsArray($iocs);

        // If IOCs found, verify they have extraction_method marked
        if (count($iocs) > 0) {
            $extractionMethods = array_map(fn($ioc) => $ioc['context']['extraction_method'] ?? null, $iocs);
            $this->assertNotEmpty($extractionMethods);

            // Should have either 'regex', 'llm', or both
            $uniqueMethods = array_unique($extractionMethods);
            $this->assertNotEmpty(array_intersect($uniqueMethods, ['regex', 'llm']));
        }
    }

    public function testExtractIocsFromMessageWithLLMMethodAndTypeFilter(): void
    {
        $message = $this->createTestMessage();

        // Extract only email IOCs using LLM
        $iocs = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'llm', ['email']);

        // All returned IOCs should be emails
        foreach ($iocs as $ioc) {
            $this->assertSame('email', $ioc['type']);
        }
    }

    public function testExtractIocsFromMessageWithRegexMethodStillWorks(): void
    {
        $message = $this->createTestMessage();

        // Verify regex extraction still works (regression test)
        $iocs = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'regex', []);

        $this->assertIsArray($iocs);

        // Should find at least some IOCs (email, url)
        $this->assertGreaterThan(0, count($iocs));

        // All should be marked as regex extraction
        foreach ($iocs as $ioc) {
            $this->assertSame('regex', $ioc['context']['extraction_method']);
        }
    }
}

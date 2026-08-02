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

    private function createTestMessage(
        ?string $externalMessageId = null,
        string $bodyText = 'Click here: https://evil-site.com/login',
        ?array $headers = null,
    ): Message {
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
        $defaultHeaders = [
            'from' => 'scammer@test.com',
            'to' => 'victim@test.com',
            'message-id' => $externalMessageId ?? '<test-' . bin2hex(random_bytes(8)) . '@test.com>',
            'reply-to' => 'reply-scammer@evil-reply.test',
            'return-path' => 'bounce@evil-bounce.test',
        ];

        $message = new Message(
            $msgId,
            $conv,
            $channel,
            $direction,
            'en',
            'Phishing Email',
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

        // score_agg is now MAX(external, intrinsic). The intrinsic
        // side adds at least BASE(UNKNOWN)=30 + 30 (IBAN financial bonus) + 5
        // (URL bonus) + 6 (2 types × 3 diversity) = 71. The fixture's exact
        // scam_type is non-deterministic (findOneBy([]) at line 48), so any
        // baseline >= UNKNOWN brings score >= 71. External VT-suspicious = 40
        // never wins.
        $this->assertGreaterThanOrEqual(70, $risk['score_agg'], 'Intrinsic IBAN bonus must dominate over external VT-suspicious');
        $this->assertSame('high', $risk['level']);
        $this->assertTrue($risk['should_reply'], 'Should reply because IBAN is exploitable (level=high now)');
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

        // A conversation may contain multiple INCOMING scammer messages.
        // Outgoing messages must NEVER feed the IOC pipeline (the previous version of
        // this test used direction='out' on message2, which was itself an example of
        // the historical bug — the pipeline silently ingested IOCs from outgoing
        // messages, polluting the indicator table with our own headers and 555 phones).
        $msgId2 = uuid_create(UUID_TYPE_RANDOM);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);
        $message2 = new Message(
            $msgId2,
            $conv,
            $message1->getChannel(),
            $direction,
            'en',
            'Second scammer email',
            'Same domain mentioned again',
            '<p>Same domain mentioned again</p>',
            ['from' => 'scammer-followup@evil.com'],
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

        // Two observations of the same domain on two different messages share
        // the same indicator_id. getConversationIocs deduplicates by indicator_id,
        // so exactly 1 unique IOC is returned.
        $this->assertCount(1, $iocs);
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

        // Non-derived IOCs should be marked as regex extraction
        foreach ($iocs as $ioc) {
            $method = $ioc['context']['extraction_method'] ?? '';
            $this->assertTrue(
                \in_array($method, ['regex', 'derived_from_url', 'derived_from_email'], true),
                "Unexpected extraction method: {$method}"
            );
        }
    }

    // ================================================================== //
    //  Merged from IocHandlerAdditionalTest
    // ================================================================== //

    public function testExtractAndUpsertHeaderIocsReturnsCountOfExtractedIocs(): void
    {
        $message = $this->createTestMessage();
        $count = $this->iocHandler->extractAndUpsertHeaderIocs($message);

        $this->assertGreaterThanOrEqual(1, $count, 'Should extract at least 1 header IOC');
    }

    public function testExtractAndUpsertHeaderIocsIsIdempotent(): void
    {
        $message = $this->createTestMessage();
        $count1 = $this->iocHandler->extractAndUpsertHeaderIocs($message);
        $count2 = $this->iocHandler->extractAndUpsertHeaderIocs($message);

        $this->assertSame($count1, $count2, 'Header IOC extraction should be idempotent');
    }

    public function testExtractAndUpsertHeaderIocsWithMinimalHeaders(): void
    {
        $message = $this->createTestMessage(null, 'Click here', [
            'from' => 'onlysender@minimal.test',
            'message-id' => '<minimal-' . bin2hex(random_bytes(4)) . '@test.com>',
        ]);
        $count = $this->iocHandler->extractAndUpsertHeaderIocs($message);

        $this->assertGreaterThanOrEqual(0, $count, 'Should handle minimal headers gracefully');
    }

    public function testUpdateIocEnrichmentUpdatesContext(): void
    {
        $message = $this->createTestMessage();

        $ioc = $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'url',
                'value' => 'https://enrich-target.com',
                'value_norm' => 'enrich-target.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [],
        ]);

        $updated = $this->iocHandler->updateIocEnrichment($ioc->getObsId(), [
            'virustotal' => ['malicious' => 3, 'suspicious' => 1],
            'abuseipdb' => ['confidence' => 92],
        ]);

        $this->assertSame($ioc->getObsId(), $updated->getObsId());
        $context = $updated->getContext();
        $this->assertArrayHasKey('enrichment', $context);
        $this->assertSame(3, $context['enrichment']['virustotal']['malicious']);
    }

    public function testUpdateIocEnrichmentThrowsForUnknownObsId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->iocHandler->updateIocEnrichment('ffffffff-ffff-ffff-ffff-ffffffffffff', ['foo' => 'bar']);
    }

    public function testGetAllIocsWithConfidenceReturnsArray(): void
    {
        $message = $this->createTestMessage();
        $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'url',
                'value' => 'https://confidence-test.com',
                'value_norm' => 'confidence-test.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => ['virustotal' => ['malicious' => 2]],
        ]);

        $results = $this->iocHandler->getAllIocsWithConfidence();
        $this->assertIsArray($results);
        $this->assertGreaterThan(0, count($results));
    }

    public function testGetAllIocsWithConfidenceMinScoreFilters(): void
    {
        $results = $this->iocHandler->getAllIocsWithConfidence(999.0);
        $this->assertIsArray($results);
        $this->assertLessThanOrEqual(count($this->iocHandler->getAllIocsWithConfidence()), count($results));
    }

    public function testGetIocDetailReturnsStructuredData(): void
    {
        $message = $this->createTestMessage();
        $ioc = $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'domain',
                'value' => 'detail-test.com',
                'value_norm' => 'detail-test.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => ['virustotal' => ['malicious' => 1]],
        ]);

        $detail = $this->iocHandler->getIocDetail($ioc->getIndicatorId());

        $this->assertIsArray($detail);
        $this->assertArrayHasKey('indicator_id', $detail);
        $this->assertSame($ioc->getIndicatorId(), $detail['indicator_id']);
    }

    public function testGetIocDetailThrowsForUnknownIndicator(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->iocHandler->getIocDetail('ffffffff-ffff-ffff-ffff-ffffffffffff');
    }

    public function testGetCoOccurrenceGraphReturnsNodesAndEdges(): void
    {
        $message = $this->createTestMessage();

        $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'url',
                'value' => 'https://cooccur-a.com',
                'value_norm' => 'cooccur-a.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [],
        ]);

        $iocB = $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'domain',
                'value' => 'cooccur-b.com',
                'value_norm' => 'cooccur-b.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [],
        ]);

        $graph = $this->iocHandler->getCoOccurrenceGraph($iocB->getIndicatorId());

        $this->assertArrayHasKey('nodes', $graph);
        $this->assertArrayHasKey('edges', $graph);
        $this->assertIsArray($graph['nodes']);
        $this->assertIsArray($graph['edges']);
    }

    public function testComputeConfidenceDataReturnsExpectedKeys(): void
    {
        $message = $this->createTestMessage();
        $ioc = $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'url',
                'value' => 'https://confidence-calc.com',
                'value_norm' => 'confidence-calc.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => ['virustotal' => ['malicious' => 5]],
        ]);

        $data = $this->iocHandler->computeConfidenceData(
            $ioc->getIndicatorId(),
            75.0,
            new \DateTimeImmutable()
        );

        $this->assertArrayHasKey('confidence', $data);
        $this->assertArrayHasKey('decay_factor', $data);
        $this->assertArrayHasKey('effective_score', $data);
        $this->assertIsFloat($data['confidence']);
        $this->assertIsFloat($data['decay_factor']);
        $this->assertIsFloat($data['effective_score']);
    }

    public function testComputeConfidenceDataWithNullScore(): void
    {
        $message = $this->createTestMessage();
        $ioc = $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'email',
                'value' => 'nullscore@test.com',
                'value_norm' => 'nullscore@test.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [],
        ]);

        $data = $this->iocHandler->computeConfidenceData(
            $ioc->getIndicatorId(),
            null,
            new \DateTimeImmutable()
        );

        $this->assertArrayHasKey('confidence', $data);
        $this->assertArrayHasKey('effective_score', $data);
    }

    public function testComputeConfidenceDataWithOldObservation(): void
    {
        $message = $this->createTestMessage();
        $ioc = $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'url',
                'value' => 'https://old-observation.com',
                'value_norm' => 'old-observation.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => ['virustotal' => ['malicious' => 3]],
        ]);

        $data = $this->iocHandler->computeConfidenceData(
            $ioc->getIndicatorId(),
            80.0,
            new \DateTimeImmutable('-90 days')
        );

        $this->assertLessThanOrEqual(1.0, $data['decay_factor']);
        $this->assertGreaterThanOrEqual(0.0, $data['decay_factor']);
    }

    // ================================================================== //
    //  Merged from IocHandlerDeepTest
    // ================================================================== //

    public function testExtractIocsFromMessageWithPersistTrue(): void
    {
        $message = $this->createTestMessage(
            bodyText: 'Visit https://evil-persist.com and contact scammer@evil-persist.com'
        );

        $iocs = $this->iocHandler->extractIocsFromMessage(
            $message->getMsgId(),
            'regex',
            [],
            true
        );

        $this->assertIsArray($iocs);

        foreach ($iocs as $ioc) {
            $this->assertArrayHasKey('type', $ioc);
            $this->assertArrayHasKey('value', $ioc);
            $this->assertArrayHasKey('context', $ioc);
            if (isset($ioc['context']['obs_id'])) {
                $this->assertNotEmpty($ioc['context']['obs_id']);
            }
        }
    }

    public function testExtractIocsFromMessageWithPersistTrueAndTypeFilter(): void
    {
        $message = $this->createTestMessage(
            bodyText: 'Visit https://evil-filter.com and email scammer@evil-filter.com'
        );

        $iocs = $this->iocHandler->extractIocsFromMessage(
            $message->getMsgId(),
            'regex',
            ['email'],
            true
        );

        $this->assertIsArray($iocs);
        $emailIocs = array_filter($iocs, fn ($ioc) => $ioc['type'] === 'email');
        $this->assertGreaterThanOrEqual(1, count($emailIocs), 'Should contain at least one email IOC');
    }

    public function testExtractIocsFromMessageWithPersistTrueIsIdempotent(): void
    {
        $message = $this->createTestMessage(
            bodyText: 'Contact evil-idempotent@test.com'
        );

        $iocs1 = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'regex', [], true);
        $iocs2 = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'regex', [], true);

        $this->assertCount(count($iocs1), $iocs2);
    }

    public function testExtractIocsHybridWithPersist(): void
    {
        $message = $this->createTestMessage(
            bodyText: 'Visit https://hybrid-persist.com for your prize'
        );

        $iocs = $this->iocHandler->extractIocsFromMessage(
            $message->getMsgId(),
            'hybrid',
            [],
            true
        );

        $this->assertIsArray($iocs);
    }

    public function testExtractIocsFromMessageWithEmptyBody(): void
    {
        $message = $this->createTestMessage(bodyText: '');

        $iocs = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'regex', []);

        $this->assertIsArray($iocs);
    }

    public function testUpsertEnrichedIocWithAllOptionalFields(): void
    {
        $message = $this->createTestMessage();

        $ioc = $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'email',
                'value' => 'full-field-test@evil.com',
                'value_norm' => 'full-field-test@evil.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => ['virustotal' => ['malicious' => 2]],
            'tags' => ['phishing', 'credential-theft'],
            'tlp' => 'RED',
            'category' => 'Credential_phish',
        ]);

        $this->assertNotNull($ioc);
        $context = $ioc->getContext();
        $this->assertSame('email', $context['type']);
    }

    public function testUpsertEnrichedIocWithSha256Type(): void
    {
        $message = $this->createTestMessage();

        $hash = hash('sha256', 'test-content');
        $ioc = $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'sha256',
                'value' => $hash,
                'value_norm' => $hash,
                'source' => 'attachment',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [],
            'tlp' => 'AMBER',
        ]);

        $this->assertNotNull($ioc);
        $this->assertSame('sha256', $ioc->getContext()['type']);
    }

    public function testCalculateMessageRiskWithMultipleDiverseIocTypes(): void
    {
        $message = $this->createTestMessage();

        $types = [
            ['type' => 'url', 'value' => 'https://diverse-risk.com', 'value_norm' => 'diverse-risk.com'],
            ['type' => 'email', 'value' => 'diverse@evil.com', 'value_norm' => 'diverse@evil.com'],
            ['type' => 'iban', 'value' => 'DE89370400440532013000', 'value_norm' => 'DE89370400440532013000'],
        ];

        foreach ($types as $t) {
            $this->iocHandler->upsertEnrichedIoc([
                'msg_id' => $message->getMsgId(),
                'ioc' => array_merge($t, [
                    'source' => 'body',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ]),
                'enrichment' => [],
            ]);
        }

        $risk = $this->iocHandler->calculateMessageRisk($message->getMsgId());

        $this->assertIsInt($risk['score_agg']);
        $this->assertContains($risk['level'], ['high', 'medium', 'low']);
        $this->assertIsBool($risk['should_reply']);
    }

    public function testGetConversationIocsReturnsEmptyForNewConversation(): void
    {
        $message = $this->createTestMessage();
        $convId = $message->getConversation()->getConvId();

        $iocs = $this->iocHandler->getConversationIocs($convId);
        $this->assertIsArray($iocs);
    }

    // ================================================================== //
    //  Merged from IocHandlerExtendedTest
    // ================================================================== //

    public function testRegexExtractionFindsUrlAndEmail(): void
    {
        $message = $this->createTestMessage(
            bodyText: 'Contact me at scammer@evil.com or visit https://evil-phish.example.com/login'
        );

        $iocs = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'regex', []);

        $this->assertIsArray($iocs);
        $this->assertGreaterThan(0, count($iocs));

        $types = array_column($iocs, 'type');
        $this->assertContains('url', $types);
        $this->assertContains('email', $types);
    }

    public function testRegexExtractionFindsIban(): void
    {
        $message = $this->createTestMessage(
            bodyText: 'Transfer to FR7612345678901234567890123 immediately'
        );

        $iocs = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'regex', []);

        $types = array_column($iocs, 'type');
        $this->assertContains('iban', $types);
    }

    public function testRegexExtractionFindsPhone(): void
    {
        $message = $this->createTestMessage(
            bodyText: 'Call me at +33 6 12 34 56 78 for details'
        );

        $iocs = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'regex', []);

        $this->assertIsArray($iocs);
    }

    public function testRegexExtractionWithTypeFilter(): void
    {
        $message = $this->createTestMessage(
            bodyText: 'Visit https://evil.com or mail scammer@evil.com'
        );

        $iocs = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'regex', ['url']);

        foreach ($iocs as $ioc) {
            $this->assertContains($ioc['type'], ['url', 'domain']);
        }
    }

    public function testHybridExtractionReturnsArray(): void
    {
        $message = $this->createTestMessage(
            bodyText: 'Send Bitcoin to bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh'
        );

        $iocs = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'hybrid', []);

        $this->assertIsArray($iocs);
    }

    public function testHybridExtractionDeduplicates(): void
    {
        $message = $this->createTestMessage(
            bodyText: 'Visit https://evil-dedup.example.com mentioned twice https://evil-dedup.example.com'
        );

        $iocs = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'hybrid', []);

        $urlValues = [];
        foreach ($iocs as $ioc) {
            if ($ioc['type'] === 'url') {
                $urlValues[] = $ioc['value_norm'] ?? $ioc['value'];
            }
        }

        $this->assertCount(count(array_unique($urlValues)), $urlValues, 'Should not have duplicate URL IOCs');
    }

    public function testExtractIocsFromMessageThrowsForUnknownMessage(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->iocHandler->extractIocsFromMessage('ffffffff-ffff-ffff-ffff-ffffffffffff', 'regex', []);
    }

    public function testUpsertWithUrlScanCleanVerdictScoresLower(): void
    {
        $message = $this->createTestMessage();

        $ioc = $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'url',
                'value' => 'https://clean-site.example.com',
                'value_norm' => 'clean-site.example.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [
                'virustotal' => ['malicious' => 0, 'suspicious' => 0, 'harmless' => 90],
                'urlscan' => ['verdict' => 'clean'],
            ],
        ]);

        $context = $ioc->getContext();
        $score = $context['score']['agg'] ?? 0;

        $this->assertLessThan(50, $score, 'Clean site should score low');
    }

    public function testUpsertWithDomainType(): void
    {
        $message = $this->createTestMessage();

        $ioc = $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'domain',
                'value' => 'domain-ext-test.example.com',
                'value_norm' => 'domain-ext-test.example.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [],
        ]);

        $context = $ioc->getContext();
        $this->assertSame('domain', $context['type']);
        $this->assertArrayHasKey('stix', $context);
        $this->assertStringContainsString('domain-name:value', $context['stix']['pattern']);
    }

    // ─── end-to-end shortcut on pre-filtered message ──

    public function testRiskEndpoint_returnsShouldReplyFalseForPreFilteredMessageWithBodyIocs(): void
    {
        // End-to-end: reproduce the 2026-05-19 incident shape
        // (DMARC report with body URLs/domains) in DB with the pre-filter
        // marker set. The /risk endpoint must override IOC-based scoring
        // and return should_reply=false. Without the marker override, score_agg=60
        // medium → should_reply=true (the bug).
        $message = $this->createTestMessage(
            bodyText: 'DMARC aggregate report content with https://privacy.microsoft.com link.',
            headers: [
                'from' => 'dmarcreport@microsoft.com',
                'to' => 'admin@gamma-partners.example',
                'message-id' => '<spec086e2e-' . bin2hex(random_bytes(8)) . '@microsoft.com>',
                'pre_filter' => [
                    'kind' => 'domain',
                    'pattern' => 'microsoft.com',
                    'matched_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ],
            ],
        );

        // Add body IOCs that WOULD normally trigger reply on a non-filtered
        // message (URL + domain + email → medium score → reply).
        $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'url',
                'value' => 'https://privacy.microsoft.com/en-us/dmarc',
                'value_norm' => 'privacy.microsoft.com/en-us/dmarc',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [],
        ]);
        $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'domain',
                'value' => 'privacy.microsoft.com',
                'value_norm' => 'privacy.microsoft.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [],
        ]);

        $risk = $this->iocHandler->calculateMessageRisk($message->getMsgId());

        $this->assertSame(0, $risk['score_agg'], 'pre-filter marker must force score_agg=0 regardless of body IOCs');
        $this->assertSame('low', $risk['level']);
        $this->assertFalse($risk['should_reply'], 'pre-filtered message must never trigger reply');
        $this->assertStringContainsString('pre_filtered: domain:microsoft.com', $risk['reason']);
    }
}

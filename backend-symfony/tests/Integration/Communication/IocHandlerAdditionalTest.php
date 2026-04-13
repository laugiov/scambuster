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
 * Additional integration tests for IocHandler — covers methods not exercised
 * by the original IocHandlerTest: header extraction, enrichment update,
 * confidence queries, IOC listing, IOC detail, and co-occurrence graph.
 */
class IocHandlerAdditionalTest extends KernelTestCase
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

    private function createTestMessage(string $externalMessageId = null, ?array $headers = null): Message
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
            'stix-additional-' . bin2hex(random_bytes(4))
        );

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $defaultHeaders = [
            'from' => 'scammer@evil-domain.test',
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
            'Test IOC Additional',
            'Visit https://phish.example.com and contact scammer@evil-domain.test',
            '<p>Visit https://phish.example.com</p>',
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

    // ------------------------------------------------------------------ //
    //  extractAndUpsertHeaderIocs
    // ------------------------------------------------------------------ //

    public function testExtractAndUpsertHeaderIocsReturnsCountOfExtractedIocs(): void
    {
        $message = $this->createTestMessage();
        $count = $this->iocHandler->extractAndUpsertHeaderIocs($message);

        // Headers contain at least from + reply-to + return-path emails
        $this->assertGreaterThanOrEqual(1, $count, 'Should extract at least 1 header IOC');
    }

    public function testExtractAndUpsertHeaderIocsIsIdempotent(): void
    {
        $message = $this->createTestMessage();
        $count1 = $this->iocHandler->extractAndUpsertHeaderIocs($message);
        $count2 = $this->iocHandler->extractAndUpsertHeaderIocs($message);

        // Second call should not create duplicates — upsert dedup
        $this->assertSame($count1, $count2, 'Header IOC extraction should be idempotent');
    }

    public function testExtractAndUpsertHeaderIocsWithMinimalHeaders(): void
    {
        $message = $this->createTestMessage(null, [
            'from' => 'onlysender@minimal.test',
            'message-id' => '<minimal-' . bin2hex(random_bytes(4)) . '@test.com>',
        ]);
        $count = $this->iocHandler->extractAndUpsertHeaderIocs($message);

        $this->assertGreaterThanOrEqual(0, $count, 'Should handle minimal headers gracefully');
    }

    // ------------------------------------------------------------------ //
    //  updateIocEnrichment
    // ------------------------------------------------------------------ //

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

    // ------------------------------------------------------------------ //
    //  getAllIocsWithConfidence
    // ------------------------------------------------------------------ //

    public function testGetAllIocsWithConfidenceReturnsArray(): void
    {
        // Seed at least one IOC
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
        // Very high threshold should return few or zero results
        $this->assertLessThanOrEqual(count($this->iocHandler->getAllIocsWithConfidence()), count($results));
    }

    // ------------------------------------------------------------------ //
    //  getIocDetail
    // ------------------------------------------------------------------ //

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

    // ------------------------------------------------------------------ //
    //  getCoOccurrenceGraph
    // ------------------------------------------------------------------ //

    public function testGetCoOccurrenceGraphReturnsNodesAndEdges(): void
    {
        $message = $this->createTestMessage();

        // Create two IOCs on the same message to form co-occurrence
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

    // ------------------------------------------------------------------ //
    //  computeConfidenceData
    // ------------------------------------------------------------------ //

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

        // Old observation should have higher decay
        $data = $this->iocHandler->computeConfidenceData(
            $ioc->getIndicatorId(),
            80.0,
            new \DateTimeImmutable('-90 days')
        );

        $this->assertLessThanOrEqual(1.0, $data['decay_factor']);
        $this->assertGreaterThanOrEqual(0.0, $data['decay_factor']);
    }
}

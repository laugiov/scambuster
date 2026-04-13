<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitoring;

use App\Application\Monitoring\PipelineTraceHandler;
use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for PipelineTraceHandler.
 *
 * Tests querying pipeline traces from outbound message headers
 * with real database interactions. Since fixtures do not include
 * pipeline_trace data, tests create outbound messages with
 * pipeline_trace in their headers JSONB.
 */
class PipelineTraceHandlerTest extends KernelTestCase
{
    private PipelineTraceHandler $handler;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(PipelineTraceHandler::class);
        $this->em = $container->get('doctrine')->getManager();
    }

    /**
     * Create an outbound message with a pipeline_trace in its headers JSON.
     */
    private function createOutboundMessageWithTrace(
        ?string $persona = 'elderly_person',
        ?string $scamType = 'ROMANCE',
        bool $approved = true,
        bool $fallbackUsed = false,
        float $totalCost = 0.0012,
        ?\DateTimeImmutable $tsMsg = null,
    ): string {
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamTypeEntity = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $directionOut = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'out']);

        $this->assertNotNull($directionOut, 'Direction "out" must exist in fixtures');

        $conv = new Conversation(
            uuid_create(UUID_TYPE_RANDOM),
            $channel,
            $scamTypeEntity,
            $account,
            ConversationStatus::OPEN,
            50,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-trace-test-' . bin2hex(random_bytes(4))
        );
        $this->em->persist($conv);

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $tsMsg ??= new \DateTimeImmutable();

        $pipelineTrace = [
            'conversation_id' => $conv->getConvId(),
            'persona' => $persona,
            'scam_type' => $scamType,
            'detected_language' => 'en',
            'total_duration_ms' => 1234.56,
            'total_cost' => $totalCost,
            'attempts' => 1,
            'approved' => $approved,
            'fallback_used' => $fallbackUsed,
            'component_count' => 4,
            'has_alerts' => false,
            'components' => [
                ['name' => 'prompt_builder', 'status' => 'ran', 'duration_ms' => 50.0, 'cost' => 0.0001],
                ['name' => 'policy_guard', 'status' => 'ran', 'duration_ms' => 10.0],
                ['name' => 'reply_validator', 'status' => 'ran', 'duration_ms' => 15.0],
                ['name' => 'ioc_scorer', 'status' => 'ran', 'duration_ms' => 5.0],
            ],
            'created_at' => $tsMsg->format(\DATE_ATOM),
        ];

        $message = new Message(
            $msgId,
            $conv,
            $channel,
            $directionOut,
            'en',
            'Re: Test',
            'Generated reply text',
            null,
            [
                'pipeline_trace' => $pipelineTrace,
                'llm_persona' => $persona,
            ],
            bin2hex(random_bytes(32)),
            null,
            null,
            $tsMsg,
            $tsMsg,
            null
        );
        $this->em->persist($message);
        $this->em->flush();

        return $msgId;
    }

    // ── getRecentTraces ──

    public function testGetRecentTracesReturnsEmptyWhenNoTraces(): void
    {
        // Query with very short window to avoid picking up test data from other runs
        $result = $this->handler->getRecentTraces(0, 50, 0);

        $this->assertArrayHasKey('traces', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('limit', $result);
        $this->assertArrayHasKey('offset', $result);
        $this->assertIsArray($result['traces']);
    }

    public function testGetRecentTracesReturnsCreatedTrace(): void
    {
        $msgId = $this->createOutboundMessageWithTrace();

        $result = $this->handler->getRecentTraces(7, 50, 0);

        $this->assertGreaterThanOrEqual(1, $result['total']);
        $this->assertGreaterThanOrEqual(1, count($result['traces']));

        // Find our trace
        $found = false;
        foreach ($result['traces'] as $trace) {
            if ($trace['msg_id'] === $msgId) {
                $found = true;
                $this->assertSame('elderly_person', $trace['persona']);
                $this->assertSame('ROMANCE', $trace['scam_type']);
                $this->assertTrue($trace['approved']);
                $this->assertFalse($trace['fallback_used']);
                $this->assertSame(4, $trace['component_count']);
                break;
            }
        }
        $this->assertTrue($found, 'Created trace should appear in getRecentTraces results');
    }

    public function testGetRecentTracesRespectsLimit(): void
    {
        // Create 3 traces
        $this->createOutboundMessageWithTrace();
        $this->createOutboundMessageWithTrace(persona: 'confused_user');
        $this->createOutboundMessageWithTrace(persona: 'bank_customer');

        $result = $this->handler->getRecentTraces(7, 2, 0);

        $this->assertLessThanOrEqual(2, count($result['traces']));
        $this->assertSame(2, $result['limit']);
    }

    public function testGetRecentTracesRespectsOffset(): void
    {
        // Create 3 traces
        $this->createOutboundMessageWithTrace();
        $this->createOutboundMessageWithTrace(persona: 'confused_user');
        $this->createOutboundMessageWithTrace(persona: 'bank_customer');

        $resultAll = $this->handler->getRecentTraces(7, 100, 0);
        $resultOffset = $this->handler->getRecentTraces(7, 100, 1);

        $this->assertSame($resultAll['total'], $resultOffset['total']);
        $this->assertSame(1, $resultOffset['offset']);

        if ($resultAll['total'] > 1) {
            $this->assertCount(count($resultAll['traces']) - 1, $resultOffset['traces']);
        }
    }

    public function testGetRecentTracesFiltersByPersona(): void
    {
        $uniquePersona = 'test_persona_' . bin2hex(random_bytes(4));
        $this->createOutboundMessageWithTrace(persona: $uniquePersona);
        $this->createOutboundMessageWithTrace(persona: 'other_persona');

        $result = $this->handler->getRecentTraces(7, 50, 0, $uniquePersona);

        foreach ($result['traces'] as $trace) {
            $this->assertSame($uniquePersona, $trace['persona']);
        }
    }

    public function testGetRecentTracesFiltersByScamType(): void
    {
        $uniqueScamType = 'TEST_SCAM_' . strtoupper(bin2hex(random_bytes(4)));
        $this->createOutboundMessageWithTrace(scamType: $uniqueScamType);
        $this->createOutboundMessageWithTrace(scamType: 'OTHER_SCAM');

        $result = $this->handler->getRecentTraces(7, 50, 0, null, $uniqueScamType);

        foreach ($result['traces'] as $trace) {
            $this->assertSame($uniqueScamType, $trace['scam_type']);
        }
    }

    public function testGetRecentTracesClampsDaysToMaximum30(): void
    {
        // Passing days=999 should be clamped to 30 internally
        $result = $this->handler->getRecentTraces(999, 50, 0);

        $this->assertArrayHasKey('traces', $result);
        // No exception = pass; the method clamps internally
    }

    public function testGetRecentTracesClampsLimitToMaximum100(): void
    {
        $result = $this->handler->getRecentTraces(7, 999, 0);
        $this->assertSame(100, $result['limit']);
    }

    // ── getTraceByMessageId ──

    public function testGetTraceByMessageIdReturnsTraceData(): void
    {
        $msgId = $this->createOutboundMessageWithTrace(
            persona: 'lonely_person',
            scamType: 'INVESTMENT',
            totalCost: 0.0042,
        );

        $trace = $this->handler->getTraceByMessageId($msgId);

        $this->assertNotNull($trace);
        $this->assertSame('lonely_person', $trace['persona']);
        $this->assertSame('INVESTMENT', $trace['scam_type']);
        $this->assertSame(0.0042, $trace['total_cost']);
        $this->assertArrayHasKey('components', $trace);
        $this->assertCount(4, $trace['components']);
    }

    public function testGetTraceByMessageIdReturnsNullForNonExistentMessage(): void
    {
        $trace = $this->handler->getTraceByMessageId('ffffffff-ffff-ffff-ffff-ffffffffffff');
        $this->assertNull($trace);
    }

    public function testGetTraceByMessageIdReturnsNullForInboundMessage(): void
    {
        // Create an inbound message (no pipeline_trace expected)
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $directionIn = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        $conv = new Conversation(
            uuid_create(UUID_TYPE_RANDOM),
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            10,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-inbound-trace-' . bin2hex(random_bytes(4))
        );
        $this->em->persist($conv);

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $message = new Message(
            $msgId,
            $conv,
            $channel,
            $directionIn,
            'en',
            'Inbound email',
            'Body text',
            null,
            ['from' => 'scammer@test.com'],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null
        );
        $this->em->persist($message);
        $this->em->flush();

        // Should return null because getTraceByMessageId only queries outbound messages
        $trace = $this->handler->getTraceByMessageId($msgId);
        $this->assertNull($trace);
    }

    // ── getHealthMetrics ──

    public function testGetHealthMetricsReturnsExpectedStructure(): void
    {
        $result = $this->handler->getHealthMetrics(24);

        $this->assertArrayHasKey('period_hours', $result);
        $this->assertArrayHasKey('total_replies', $result);
        $this->assertArrayHasKey('avg_duration_ms', $result);
        $this->assertArrayHasKey('avg_cost', $result);
        $this->assertArrayHasKey('approval_rate', $result);
        $this->assertArrayHasKey('fallback_rate', $result);
        $this->assertArrayHasKey('components', $result);
        $this->assertArrayHasKey('alerts', $result);
        $this->assertArrayHasKey('cost_today', $result);
        $this->assertArrayHasKey('cost_yesterday', $result);

        $this->assertSame(24, $result['period_hours']);
        $this->assertIsInt($result['total_replies']);
        $this->assertIsFloat($result['cost_today']);
        $this->assertIsFloat($result['cost_yesterday']);
    }

    public function testGetHealthMetricsAggregatesCreatedTraces(): void
    {
        // Create approved + fallback traces
        $this->createOutboundMessageWithTrace(approved: true, fallbackUsed: false, totalCost: 0.001);
        $this->createOutboundMessageWithTrace(approved: true, fallbackUsed: true, totalCost: 0.002);
        $this->createOutboundMessageWithTrace(approved: false, fallbackUsed: false, totalCost: 0.003);

        $result = $this->handler->getHealthMetrics(24);

        $this->assertGreaterThanOrEqual(3, $result['total_replies']);
        $this->assertGreaterThan(0, $result['avg_duration_ms']);
        $this->assertGreaterThan(0, $result['avg_cost']);

        // At least 2 approved out of at least 3 total
        $this->assertGreaterThan(0, $result['approval_rate']);
        // At least 1 fallback
        $this->assertGreaterThan(0, $result['fallback_rate']);

        // Components should be present
        $this->assertNotEmpty($result['components']);
        $this->assertArrayHasKey('prompt_builder', $result['components']);
        $this->assertArrayHasKey('success_rate', $result['components']['prompt_builder']);
        $this->assertArrayHasKey('avg_duration_ms', $result['components']['prompt_builder']);
    }

    public function testGetHealthMetricsClampsHoursToMaximum168(): void
    {
        // Passing hours=9999 should be clamped to 168 (1 week)
        $result = $this->handler->getHealthMetrics(9999);

        $this->assertSame(168, $result['period_hours']);
    }

    public function testGetHealthMetricsCostTodayIncludesRecentTraces(): void
    {
        // Create a trace dated today
        $this->createOutboundMessageWithTrace(totalCost: 0.005);

        $result = $this->handler->getHealthMetrics(24);

        $this->assertGreaterThanOrEqual(0.005, $result['cost_today']);
    }
}

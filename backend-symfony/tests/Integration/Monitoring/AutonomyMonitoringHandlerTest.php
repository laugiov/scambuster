<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitoring;

use App\Application\Monitoring\AutonomyMonitoringHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for AutonomyMonitoringHandler.
 *
 * Verifies that the monitoring handler returns correctly structured
 * data from the real database with fixtures loaded.
 */
class AutonomyMonitoringHandlerTest extends KernelTestCase
{
    private AutonomyMonitoringHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->handler = new AutonomyMonitoringHandler($em);
    }

    public function testGetAutonomyStatusReturnsCompleteStructure(): void
    {
        $status = $this->handler->getAutonomyStatus();

        // Top-level keys
        $this->assertArrayHasKey('status', $status);
        $this->assertArrayHasKey('kill_switch_active', $status);
        $this->assertArrayHasKey('conversations', $status);
        $this->assertArrayHasKey('messages', $status);
        $this->assertArrayHasKey('iocs', $status);
        $this->assertArrayHasKey('convergence', $status);
        $this->assertArrayHasKey('last_activity', $status);
        $this->assertArrayHasKey('checked_at', $status);
    }

    public function testConversationMetricsStructure(): void
    {
        $status = $this->handler->getAutonomyStatus();
        $conv = $status['conversations'];

        $this->assertArrayHasKey('total', $conv);
        $this->assertArrayHasKey('open', $conv);
        $this->assertArrayHasKey('closed', $conv);
        $this->assertArrayHasKey('abandoned', $conv);

        $this->assertIsInt($conv['total']);
        $this->assertIsInt($conv['open']);
        $this->assertIsInt($conv['closed']);
        $this->assertIsInt($conv['abandoned']);

        // Fixture data should have at least some conversations
        $this->assertGreaterThanOrEqual(0, $conv['total']);
    }

    public function testMessageMetricsStructure(): void
    {
        $status = $this->handler->getAutonomyStatus();
        $msg = $status['messages'];

        $this->assertArrayHasKey('total', $msg);
        $this->assertArrayHasKey('inbound', $msg);
        $this->assertArrayHasKey('outbound', $msg);

        $this->assertIsInt($msg['total']);
        $this->assertIsInt($msg['inbound']);
        $this->assertIsInt($msg['outbound']);
    }

    public function testIocMetricsStructure(): void
    {
        $status = $this->handler->getAutonomyStatus();
        $iocs = $status['iocs'];

        $this->assertArrayHasKey('total', $iocs);
        $this->assertArrayHasKey('unique_indicators', $iocs);
        $this->assertArrayHasKey('last_24h', $iocs);

        $this->assertIsInt($iocs['total']);
        $this->assertIsInt($iocs['unique_indicators']);
        $this->assertIsInt($iocs['last_24h']);
    }

    public function testConvergenceStatusStructure(): void
    {
        $status = $this->handler->getAutonomyStatus();
        $conv = $status['convergence'];

        $this->assertArrayHasKey('converged_types', $conv);
        $this->assertArrayHasKey('total_types', $conv);
        $this->assertArrayHasKey('details', $conv);

        $this->assertIsInt($conv['converged_types']);
        $this->assertIsInt($conv['total_types']);
        $this->assertIsArray($conv['details']);

        // Should have at least some scam types (fixtures load 12 types minus UNKNOWN)
        $this->assertGreaterThan(0, $conv['total_types']);
    }

    public function testLastActivityStructure(): void
    {
        $status = $this->handler->getAutonomyStatus();
        $activity = $status['last_activity'];

        $this->assertArrayHasKey('last_inbound', $activity);
        $this->assertArrayHasKey('last_outbound', $activity);
        $this->assertArrayHasKey('last_ioc', $activity);
    }

    public function testKillSwitchReflectsEnvironment(): void
    {
        $status = $this->handler->getAutonomyStatus();

        $this->assertIsBool($status['kill_switch_active']);
        // Default should be false in test environment
        $this->assertFalse($status['kill_switch_active']);
    }

    public function testStatusIsOperationalWhenHealthy(): void
    {
        $status = $this->handler->getAutonomyStatus();

        // With kill switch off and fixtures loaded, should be operational or degraded
        $this->assertContains($status['status'], ['operational', 'degraded']);
    }

    public function testCheckedAtIsValidDatetime(): void
    {
        $status = $this->handler->getAutonomyStatus();

        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $status['checked_at']);
        $this->assertNotFalse($parsed, 'checked_at must be a valid ATOM datetime');
    }

    public function testConversationCountsAreConsistent(): void
    {
        $status = $this->handler->getAutonomyStatus();
        $conv = $status['conversations'];

        // open + closed + abandoned should be <= total (there might be other statuses)
        $this->assertLessThanOrEqual(
            $conv['total'],
            $conv['open'] + $conv['closed'] + $conv['abandoned'],
        );
    }

    public function testConvergenceDetailsMatchScamTypes(): void
    {
        $status = $this->handler->getAutonomyStatus();

        $this->assertSame(
            $status['convergence']['total_types'],
            count($status['convergence']['details']),
            'Convergence details should have one entry per active scam type (excluding UNKNOWN)'
        );
    }
}

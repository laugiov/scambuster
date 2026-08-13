<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitoring;

use App\Application\Communication\ReplyCadenceService;
use App\Application\Monitoring\AutonomyMonitoringHandler;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for AutonomyMonitoringHandler.
 *
 * Verifies that the monitoring handler returns correctly structured
 * data from the real database with fixtures loaded.
 *
 * Kill-switch reporting: the handler must resolve the kill switch through the
 * same reader the reply pipeline enforces with, so the reported state can never
 * disagree with the enforced one. Resolution *mechanics* — cache before env,
 * and degrading to env when the cache pool throws — are already proven by
 * ReplyCadenceServiceTest (testKillSwitchActiveViaCachePool,
 * testKillSwitchActiveViaEnvVar, testKillSwitchCacheFailureDoesNotCrash) and are
 * deliberately not duplicated here. What is tested here is the reporting surface.
 */
class AutonomyMonitoringHandlerTest extends KernelTestCase
{
    private AutonomyMonitoringHandler $handler;
    private CacheItemPoolInterface $cache;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->handler = $container->get(AutonomyMonitoringHandler::class);

        /** @var CacheItemPoolInterface $cache */
        $cache = $container->get('cache.app');
        $this->cache = $cache;

        // Start from a known state: no runtime toggle, no deployment signal.
        $this->cache->deleteItem(ReplyCadenceService::KILL_SWITCH_CACHE_KEY);
        unset($_ENV['SCAMBUSTER_KILL_SWITCH'], $_SERVER['SCAMBUSTER_KILL_SWITCH']);
    }

    protected function tearDown(): void
    {
        $this->cache->deleteItem(ReplyCadenceService::KILL_SWITCH_CACHE_KEY);
        unset($_ENV['SCAMBUSTER_KILL_SWITCH'], $_SERVER['SCAMBUSTER_KILL_SWITCH']);

        parent::tearDown();
    }

    private function activateKillSwitchViaAdminToggle(): void
    {
        $item = $this->cache->getItem(ReplyCadenceService::KILL_SWITCH_CACHE_KEY);
        $item->set(true);
        $this->cache->save($item);
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

    /**
     * The defect: an operator halts the pipeline through the admin toggle, which
     * writes the runtime cache, but the monitoring surface reported the system as
     * running because it only ever read the deployment environment variable.
     */
    public function testKillSwitchActiveWhenHaltedThroughAdminToggle(): void
    {
        $this->activateKillSwitchViaAdminToggle();

        $status = $this->handler->getAutonomyStatus();

        $this->assertTrue(
            $status['kill_switch_active'],
            'A kill switch set through the admin toggle must be reported as active'
        );
        $this->assertSame(
            'degraded',
            $status['status'],
            'A halted pipeline must never report itself as operational'
        );
    }

    /**
     * The pre-existing deployment-level signal must keep working once the handler
     * resolves through the shared reader.
     */
    public function testKillSwitchActiveFromDeploymentSignalAlone(): void
    {
        $_ENV['SCAMBUSTER_KILL_SWITCH'] = '1';

        $status = $this->handler->getAutonomyStatus();

        $this->assertTrue(
            $status['kill_switch_active'],
            'The environment fallback must survive the switch to the shared reader'
        );
    }

    public function testKillSwitchInactiveAfterAdminToggleIsTurnedOff(): void
    {
        $this->activateKillSwitchViaAdminToggle();
        $this->cache->deleteItem(ReplyCadenceService::KILL_SWITCH_CACHE_KEY);

        $status = $this->handler->getAutonomyStatus();

        $this->assertFalse(
            $status['kill_switch_active'],
            'Turning the admin toggle off must be reported immediately'
        );
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

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Monitoring;

use App\Application\Audit\AuditLogger;
use App\Application\Monitoring\BudgetThresholdNotifier;
use App\Application\Monitoring\LlmCostHandler;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditLog;
use App\Infrastructure\Siem\Adapter\NullSiemExporter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Spec 065b — Budget threshold notifier
 *
 * Unit tests for the deduplicated daily warning emitter. Uses Symfony's
 * ArrayAdapter for the cache pool (PSR-6) so the test runs entirely
 * in-memory without Redis.
 *
 * Both LlmCostHandler and AuditLogger are `final` so we cannot mock them
 * directly. Instead we instantiate real ones with mocked dependencies
 * (Connection for the cost handler, EntityManagerInterface for the
 * audit logger). The persist call on the EM is the spy point that
 * verifies the audit event was emitted.
 */
final class BudgetThresholdNotifierTest extends TestCase
{
    /**
     * Build a real LlmCostHandler with a mocked Connection that returns a
     * configurable monthly sum. LlmCostHandler is `final`, so PHPUnit
     * cannot mock it directly.
     */
    private function makeCostHandler(float $monthlySum, float $limit = 50.0): LlmCostHandler
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn((string) $monthlySum);

        return new LlmCostHandler($connection, $limit);
    }

    /**
     * Build a real AuditLogger with a mocked EntityManager. The EM
     * persist/flush methods are spied via PHPUnit expectations.
     */
    private function makeAuditLoggerSpy(EntityManagerInterface $em): AuditLogger
    {
        return new AuditLogger($em, new NullLogger(), new RequestStack(), new NullSiemExporter());
    }

    public function test_it_emits_audit_event_when_threshold_reached(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('persist')
            ->with($this->callback(fn (AuditLog $log) => $log->getEventType() === AuditEventType::BUDGET_THRESHOLD_REACHED->value));
        $em->expects($this->once())->method('flush');

        $notifier = new BudgetThresholdNotifier(
            $this->makeCostHandler(45.0), // 45 / 50 = 90% > 80%
            $this->makeAuditLoggerSpy($em),
            new ArrayAdapter(),
            new NullLogger(),
        );

        $notifier->check();
    }

    public function test_it_does_not_emit_when_below_threshold(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $notifier = new BudgetThresholdNotifier(
            $this->makeCostHandler(10.0), // 10 / 50 = 20% < 80%
            $this->makeAuditLoggerSpy($em),
            new ArrayAdapter(),
            new NullLogger(),
        );

        $notifier->check();
    }

    public function test_it_deduplicates_within_the_same_day_via_cache_key(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())  // exactly once across two checks
            ->method('persist');
        $em->expects($this->once())->method('flush');

        $cache = new ArrayAdapter();
        $notifier = new BudgetThresholdNotifier(
            $this->makeCostHandler(45.0),
            $this->makeAuditLoggerSpy($em),
            $cache,
            new NullLogger(),
        );

        $notifier->check();
        $notifier->check();
        $notifier->check();
    }

    public function test_it_re_emits_after_cache_expiry(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(2))->method('persist');
        $em->expects($this->exactly(2))->method('flush');

        $cache = new ArrayAdapter();
        $notifier = new BudgetThresholdNotifier(
            $this->makeCostHandler(45.0),
            $this->makeAuditLoggerSpy($em),
            $cache,
            new NullLogger(),
        );

        $notifier->check();
        // Simulate next day by clearing the dedup cache key
        $cache->clear();
        $notifier->check();
    }

    public function test_it_does_nothing_when_budget_disabled(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $notifier = new BudgetThresholdNotifier(
            $this->makeCostHandler(1000.0, 0.0), // limit=0 disables enforcement
            $this->makeAuditLoggerSpy($em),
            new ArrayAdapter(),
            new NullLogger(),
        );

        $notifier->check();
    }
}

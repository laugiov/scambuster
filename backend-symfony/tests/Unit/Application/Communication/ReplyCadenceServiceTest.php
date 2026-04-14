<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\ReplyCadenceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;

/**
 * Unit tests for ReplyCadenceService.
 *
 * All dependencies (EM, rate limiters, cache) are mocked.
 */
class ReplyCadenceServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private NullLogger $logger;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->logger = new NullLogger();
    }

    private function buildService(
        ?CacheItemPoolInterface $killSwitchCache = null,
    ): ReplyCadenceService {
        return new ReplyCadenceService(
            $this->em,
            $this->logger,
            null, // convLimiter (final, cannot mock)
            null, // llmLimiter
            null, // activeLimiter
            null, // auditLogger (final, cannot mock)
            $killSwitchCache,
        );
    }

    // ------------------------------------------------------------------ //
    //  isKillSwitchActive — env var layer
    // ------------------------------------------------------------------ //

    public function testKillSwitchInactiveByDefault(): void
    {
        // Ensure env var is not set
        unset($_ENV['SCAMBUSTER_KILL_SWITCH'], $_SERVER['SCAMBUSTER_KILL_SWITCH']);

        $service = $this->buildService();
        $this->assertFalse($service->isKillSwitchActive());
    }

    public function testKillSwitchActiveViaEnvVar(): void
    {
        $_ENV['SCAMBUSTER_KILL_SWITCH'] = '1';

        try {
            $service = $this->buildService();
            $this->assertTrue($service->isKillSwitchActive());
        } finally {
            unset($_ENV['SCAMBUSTER_KILL_SWITCH']);
        }
    }

    public function testKillSwitchActiveViaServerVar(): void
    {
        unset($_ENV['SCAMBUSTER_KILL_SWITCH']);
        $_SERVER['SCAMBUSTER_KILL_SWITCH'] = 'true';

        try {
            $service = $this->buildService();
            $this->assertTrue($service->isKillSwitchActive());
        } finally {
            unset($_SERVER['SCAMBUSTER_KILL_SWITCH']);
        }
    }

    // ------------------------------------------------------------------ //
    //  isKillSwitchActive — cache layer
    // ------------------------------------------------------------------ //

    public function testKillSwitchActiveViaCachePool(): void
    {
        unset($_ENV['SCAMBUSTER_KILL_SWITCH'], $_SERVER['SCAMBUSTER_KILL_SWITCH']);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn(true);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')
            ->with(ReplyCadenceService::KILL_SWITCH_CACHE_KEY)
            ->willReturn($cacheItem);

        $service = $this->buildService(killSwitchCache: $cache);
        $this->assertTrue($service->isKillSwitchActive());
    }

    public function testKillSwitchCacheFailureDoesNotCrash(): void
    {
        unset($_ENV['SCAMBUSTER_KILL_SWITCH'], $_SERVER['SCAMBUSTER_KILL_SWITCH']);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new \RuntimeException('Redis down'));

        $service = $this->buildService(killSwitchCache: $cache);
        // Should not throw, should fall through to env var (false)
        $this->assertFalse($service->isKillSwitchActive());
    }

    // Note: checkCadence uses EM QueryBuilder which returns Doctrine\ORM\Query
    // (non-mockable in unit tests). Covered by integration tests instead.

    // ------------------------------------------------------------------ //
    //  checkSafelist
    // ------------------------------------------------------------------ //

    public function testCheckSafelistDefaultDomains(): void
    {
        unset($_ENV['SCAMBUSTER_SAFE_DOMAINS'], $_SERVER['SCAMBUSTER_SAFE_DOMAINS']);

        $service = $this->buildService();

        $this->assertTrue($service->checkSafelist('user@example.test'));
        $this->assertTrue($service->checkSafelist('user@mailinator.com'));
        $this->assertTrue($service->checkSafelist('user@guerrillamail.com'));
        $this->assertFalse($service->checkSafelist('user@unknown-domain.com'));
    }

    public function testCheckSafelistWildcardAllowsAll(): void
    {
        $_ENV['SCAMBUSTER_SAFE_DOMAINS'] = '*';

        try {
            $service = $this->buildService();
            $this->assertTrue($service->checkSafelist('anyone@anydomain.com'));
        } finally {
            unset($_ENV['SCAMBUSTER_SAFE_DOMAINS']);
        }
    }

    public function testCheckSafelistCustomDomains(): void
    {
        $_ENV['SCAMBUSTER_SAFE_DOMAINS'] = 'custom.org,another.net';

        try {
            $service = $this->buildService();
            $this->assertTrue($service->checkSafelist('user@custom.org'));
            $this->assertTrue($service->checkSafelist('user@another.net'));
            // Default domains should still be present
            $this->assertTrue($service->checkSafelist('user@example.test'));
        } finally {
            unset($_ENV['SCAMBUSTER_SAFE_DOMAINS']);
        }
    }

    public function testCheckSafelistRejectsMalformedEmail(): void
    {
        unset($_ENV['SCAMBUSTER_SAFE_DOMAINS'], $_SERVER['SCAMBUSTER_SAFE_DOMAINS']);

        $service = $this->buildService();
        $this->assertFalse($service->checkSafelist('no-at-sign'));
    }

    // ------------------------------------------------------------------ //
    //  checkRateLimits
    // ------------------------------------------------------------------ //

    public function testCheckRateLimitsReturnsNullWhenNoLimitersConfigured(): void
    {
        $service = $this->buildService();
        $this->assertNull($service->checkRateLimits('some-conv-id'));
    }

    // ------------------------------------------------------------------ //
    //  dispatchRateLimitAudit — without audit logger (null)
    // ------------------------------------------------------------------ //

    public function testDispatchRateLimitAuditDoesNothingWithoutAuditLogger(): void
    {
        $service = $this->buildService();
        // Should not throw when auditLogger is null
        $service->dispatchRateLimitAudit('test_limit', 'some-conv-id');
        $this->assertTrue(true); // no exception
    }

}

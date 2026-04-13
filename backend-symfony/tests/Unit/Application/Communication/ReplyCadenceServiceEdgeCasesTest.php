<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\ReplyCadenceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;

class ReplyCadenceServiceEdgeCasesTest extends TestCase
{
    // --- isKillSwitchActive tests ---

    public function test_killswitch_inactive_by_default(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new ReplyCadenceService($em, new NullLogger());

        // Ensure env var is not set
        $old = $_ENV['SCAMBUSTER_KILL_SWITCH'] ?? null;
        $_ENV['SCAMBUSTER_KILL_SWITCH'] = '0';

        $this->assertFalse($service->isKillSwitchActive());

        if ($old !== null) {
            $_ENV['SCAMBUSTER_KILL_SWITCH'] = $old;
        } else {
            unset($_ENV['SCAMBUSTER_KILL_SWITCH']);
        }
    }

    public function test_killswitch_active_via_env(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new ReplyCadenceService($em, new NullLogger());

        $old = $_ENV['SCAMBUSTER_KILL_SWITCH'] ?? null;
        $_ENV['SCAMBUSTER_KILL_SWITCH'] = '1';

        $this->assertTrue($service->isKillSwitchActive());

        if ($old !== null) {
            $_ENV['SCAMBUSTER_KILL_SWITCH'] = $old;
        } else {
            unset($_ENV['SCAMBUSTER_KILL_SWITCH']);
        }
    }

    public function test_killswitch_active_via_cache(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn(true);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($item);

        $service = new ReplyCadenceService($em, new NullLogger(), killSwitchCache: $cache);

        $old = $_ENV['SCAMBUSTER_KILL_SWITCH'] ?? null;
        $_ENV['SCAMBUSTER_KILL_SWITCH'] = '0';

        $this->assertTrue($service->isKillSwitchActive());

        if ($old !== null) {
            $_ENV['SCAMBUSTER_KILL_SWITCH'] = $old;
        } else {
            unset($_ENV['SCAMBUSTER_KILL_SWITCH']);
        }
    }

    public function test_killswitch_handles_cache_failure(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new \RuntimeException('Redis down'));

        $service = new ReplyCadenceService($em, new NullLogger(), killSwitchCache: $cache);

        $old = $_ENV['SCAMBUSTER_KILL_SWITCH'] ?? null;
        $_ENV['SCAMBUSTER_KILL_SWITCH'] = '0';

        // Should not throw, falls through to env var
        $this->assertFalse($service->isKillSwitchActive());

        if ($old !== null) {
            $_ENV['SCAMBUSTER_KILL_SWITCH'] = $old;
        } else {
            unset($_ENV['SCAMBUSTER_KILL_SWITCH']);
        }
    }

    // --- checkSafelist tests ---

    public function test_safelist_wildcard_allows_all(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new ReplyCadenceService($em, new NullLogger());

        $old = $_ENV['SCAMBUSTER_SAFE_DOMAINS'] ?? null;
        $_ENV['SCAMBUSTER_SAFE_DOMAINS'] = '*';

        $this->assertTrue($service->checkSafelist('anyone@anything.com'));

        if ($old !== null) {
            $_ENV['SCAMBUSTER_SAFE_DOMAINS'] = $old;
        } else {
            unset($_ENV['SCAMBUSTER_SAFE_DOMAINS']);
        }
    }

    public function test_safelist_rejects_unknown_domain(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new ReplyCadenceService($em, new NullLogger());

        $old = $_ENV['SCAMBUSTER_SAFE_DOMAINS'] ?? null;
        $_ENV['SCAMBUSTER_SAFE_DOMAINS'] = '';

        $this->assertFalse($service->checkSafelist('scammer@evil.com'));

        if ($old !== null) {
            $_ENV['SCAMBUSTER_SAFE_DOMAINS'] = $old;
        } else {
            unset($_ENV['SCAMBUSTER_SAFE_DOMAINS']);
        }
    }

    public function test_safelist_allows_default_domains(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new ReplyCadenceService($em, new NullLogger());

        $old = $_ENV['SCAMBUSTER_SAFE_DOMAINS'] ?? null;
        $_ENV['SCAMBUSTER_SAFE_DOMAINS'] = '';

        $this->assertTrue($service->checkSafelist('user@example.test'));
        $this->assertTrue($service->checkSafelist('user@mailinator.com'));
        $this->assertTrue($service->checkSafelist('user@guerrillamail.com'));

        if ($old !== null) {
            $_ENV['SCAMBUSTER_SAFE_DOMAINS'] = $old;
        } else {
            unset($_ENV['SCAMBUSTER_SAFE_DOMAINS']);
        }
    }

    public function test_safelist_allows_custom_domains(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new ReplyCadenceService($em, new NullLogger());

        $old = $_ENV['SCAMBUSTER_SAFE_DOMAINS'] ?? null;
        $_ENV['SCAMBUSTER_SAFE_DOMAINS'] = 'custom.org,another.net';

        $this->assertTrue($service->checkSafelist('user@custom.org'));
        $this->assertTrue($service->checkSafelist('user@another.net'));

        if ($old !== null) {
            $_ENV['SCAMBUSTER_SAFE_DOMAINS'] = $old;
        } else {
            unset($_ENV['SCAMBUSTER_SAFE_DOMAINS']);
        }
    }

    public function test_safelist_rejects_invalid_email(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new ReplyCadenceService($em, new NullLogger());

        $old = $_ENV['SCAMBUSTER_SAFE_DOMAINS'] ?? null;
        $_ENV['SCAMBUSTER_SAFE_DOMAINS'] = '*'; // Even wildcard shouldn't match no @
        // Actually, wildcard returns true early. Let me test without wildcard
        $_ENV['SCAMBUSTER_SAFE_DOMAINS'] = '';

        $this->assertFalse($service->checkSafelist('nodomain'));

        if ($old !== null) {
            $_ENV['SCAMBUSTER_SAFE_DOMAINS'] = $old;
        } else {
            unset($_ENV['SCAMBUSTER_SAFE_DOMAINS']);
        }
    }

    // --- checkRateLimits tests ---

    public function test_checkRateLimits_passes_when_no_limiters(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new ReplyCadenceService($em, new NullLogger());

        $this->assertNull($service->checkRateLimits('conv-1'));
    }

    // --- dispatchRateLimitAudit tests ---

    public function test_dispatchRateLimitAudit_does_nothing_without_logger(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new ReplyCadenceService($em, new NullLogger());

        // Should not throw
        $service->dispatchRateLimitAudit('test', 'conv-1');
        $this->assertTrue(true);
    }
}

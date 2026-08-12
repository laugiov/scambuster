<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\LLM\Resilience;

use App\Application\LLM\Resilience\CircuitRecord;
use App\Infrastructure\LLM\Resilience\CacheCircuitBreakerStore;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The cache-backed breaker store round-trips a record through a PSR-6 pool
 * (Redis in production) and, crucially, fails open: any pool error degrades to a
 * closed circuit / no-op rather than blocking LLM traffic.
 */
final class CacheCircuitBreakerStoreTest extends TestCase
{
    public function testRoundTripsARecordThroughThePool(): void
    {
        $store = new CacheCircuitBreakerStore(new ArrayAdapter(), new NullLogger());

        $store->save('llm.reply_generation', new CircuitRecord(3, 1_700_000_000.5), 120);
        $loaded = $store->load('llm.reply_generation');

        self::assertSame(3, $loaded->consecutiveFailures);
        self::assertSame(1_700_000_000.5, $loaded->openedAt);
    }

    public function testUnknownKeyLoadsAClosedRecord(): void
    {
        $store = new CacheCircuitBreakerStore(new ArrayAdapter(), new NullLogger());

        $record = $store->load('never_seen');

        self::assertSame(0, $record->consecutiveFailures);
        self::assertNull($record->openedAt);
    }

    public function testFailsOpenWhenThePoolThrowsOnLoad(): void
    {
        $store = new CacheCircuitBreakerStore(new ThrowingPool(), new NullLogger());

        $record = $store->load('llm.reply_generation');

        self::assertSame(0, $record->consecutiveFailures, 'a store outage must read as a closed circuit');
        self::assertNull($record->openedAt);
    }

    public function testFailsOpenWhenThePoolThrowsOnSave(): void
    {
        $store = new CacheCircuitBreakerStore(new ThrowingPool(), new NullLogger());

        // Must not throw: a store outage cannot be allowed to break the LLM call.
        $store->save('llm.reply_generation', new CircuitRecord(5, 1_700_000_000.0), 120);

        $this->expectNotToPerformAssertions();
    }
}

final class ThrowingPool implements CacheItemPoolInterface
{
    public function getItem(string $key): CacheItemInterface
    {
        throw new \RuntimeException('redis down');
    }

    public function getItems(array $keys = []): iterable
    {
        throw new \RuntimeException('redis down');
    }

    public function hasItem(string $key): bool
    {
        throw new \RuntimeException('redis down');
    }

    public function clear(): bool
    {
        throw new \RuntimeException('redis down');
    }

    public function deleteItem(string $key): bool
    {
        throw new \RuntimeException('redis down');
    }

    public function deleteItems(array $keys): bool
    {
        throw new \RuntimeException('redis down');
    }

    public function save(CacheItemInterface $item): bool
    {
        throw new \RuntimeException('redis down');
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        throw new \RuntimeException('redis down');
    }

    public function commit(): bool
    {
        throw new \RuntimeException('redis down');
    }
}

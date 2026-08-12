<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM\Resilience;

use App\Application\LLM\Resilience\CircuitBreakerStore;
use App\Application\LLM\Resilience\CircuitRecord;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * PSR-6 (Redis in production, via cache.app) backed breaker store, shared across
 * every worker so a provider outage trips the circuit fleet-wide.
 *
 * Records auto-expire after {@see $ttlSeconds}: a breaker whose processes all
 * went quiet forgets its failure streak rather than staying open forever. Every
 * pool operation is guarded — any cache error degrades to a closed circuit
 * (load) or a dropped write (save), never to a blocked LLM call.
 */
final readonly class CacheCircuitBreakerStore implements CircuitBreakerStore
{
    private const KEY_PREFIX = 'llm_circuit_breaker.';

    public function __construct(
        private CacheItemPoolInterface $pool,
        private LoggerInterface $logger,
    ) {
    }

    public function load(string $key): CircuitRecord
    {
        try {
            $item = $this->pool->getItem(self::KEY_PREFIX . $key);

            if (!$item->isHit()) {
                return CircuitRecord::closed();
            }

            $data = $item->get();

            if (!\is_array($data) || !isset($data['f']) || !is_numeric($data['f'])) {
                return CircuitRecord::closed();
            }

            $rawOpenedAt = $data['o'] ?? null;
            $openedAt = is_numeric($rawOpenedAt) ? (float) $rawOpenedAt : null;

            return new CircuitRecord((int) $data['f'], $openedAt);
        } catch (\Throwable $e) {
            $this->logger->warning('[CircuitBreaker] store load failed, treating circuit as closed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return CircuitRecord::closed();
        }
    }

    public function save(string $key, CircuitRecord $record, int $ttlSeconds): void
    {
        try {
            $item = $this->pool->getItem(self::KEY_PREFIX . $key);
            $item->set(['f' => $record->consecutiveFailures, 'o' => $record->openedAt]);
            $item->expiresAfter(max(1, $ttlSeconds));
            $this->pool->save($item);
        } catch (\Throwable $e) {
            $this->logger->warning('[CircuitBreaker] store save failed, breaker state not persisted', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

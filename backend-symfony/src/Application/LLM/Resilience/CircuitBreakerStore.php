<?php

declare(strict_types=1);

namespace App\Application\LLM\Resilience;

/**
 * Persists circuit-breaker state, keyed per logical breaker (e.g. one key for
 * the active LLM chat provider). Shared across processes so a provider outage
 * seen by one worker fails fast for all of them.
 *
 * Implementations MUST fail open: if the backing store is unavailable, load()
 * returns a closed record and save() is a no-op. A breaker must never turn its
 * own storage outage into a refusal to call the provider.
 */
interface CircuitBreakerStore
{
    public function load(string $key): CircuitRecord;

    /**
     * Persist $record under $key with a time-to-live of $ttlSeconds, after which
     * a quiet breaker forgets its state. The caller owns the TTL so it can keep it
     * at least as long as the cooldown window.
     */
    public function save(string $key, CircuitRecord $record, int $ttlSeconds): void;
}

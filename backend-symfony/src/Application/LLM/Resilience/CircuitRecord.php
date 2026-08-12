<?php

declare(strict_types=1);

namespace App\Application\LLM\Resilience;

/**
 * Immutable persisted state of one circuit breaker: the number of consecutive
 * failures observed and, once the breaker has tripped, the epoch second at which
 * it opened (null while closed).
 *
 * The breaker's decision logic (threshold, cooldown) lives in
 * {@see \App\Infrastructure\LLM\Resilience\CircuitBreakerLLMClient}; this record
 * only carries the raw counters a store must persist.
 */
final readonly class CircuitRecord
{
    public function __construct(
        public int $consecutiveFailures = 0,
        public ?float $openedAt = null,
    ) {
    }

    public static function closed(): self
    {
        return new self(0, null);
    }

    /**
     * A failure observed at $now. The caller decides whether this failure trips
     * the breaker (passing $openedAt) or merely increments the counter.
     */
    public function withFailure(?float $openedAt): self
    {
        return new self($this->consecutiveFailures + 1, $openedAt);
    }
}

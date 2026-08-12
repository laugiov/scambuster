<?php

declare(strict_types=1);

namespace App\Application\LLM\Resilience;

/**
 * The three states of a circuit breaker.
 *
 * - CLOSED: calls flow through to the provider (normal operation).
 * - OPEN: the provider is presumed down; calls fail fast without hitting it.
 * - HALF_OPEN: the cooldown elapsed; a single probe call is allowed to test
 *   whether the provider recovered. Success closes the circuit, failure re-opens it.
 */
enum CircuitState: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM\Resilience;

/**
 * Thrown when the circuit breaker is open and a call is short-circuited without
 * reaching the provider.
 *
 * Extends \RuntimeException so every existing caller handles an open circuit
 * exactly as it already handles the provider being down. Those paths differ and
 * both are fail-safe: TtpExtractor catches it and returns [] (no observations),
 * while reply generation lets it propagate (RetryCoordinator does not wrap the
 * generation call) so NO scammer-influenced text is ever sent — the honeypot
 * stays silent for that turn rather than emitting an unvalidated reply.
 */
final class CircuitOpenException extends \RuntimeException
{
}

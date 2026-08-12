<?php

declare(strict_types=1);

namespace App\Application\LLM\Port\Exception;

/**
 * The request we sent was rejected by an otherwise-healthy provider: a 4xx
 * (bad/oversized payload, auth, forbidden) or a 429 rate-limit. The provider is
 * up — retrying the same request will not help, and this must NOT trip an
 * outage circuit breaker (otherwise a burst of client-side errors, e.g. a remote
 * party flooding the honeypot into provider-side 429s, could manufacture a
 * fleet-wide outage). Rate-limiting is handled separately by the LLM rate limiter.
 *
 * Extends \RuntimeException so every existing `catch (\Throwable|\RuntimeException)`
 * keeps working unchanged.
 */
final class LlmRequestException extends \RuntimeException
{
}

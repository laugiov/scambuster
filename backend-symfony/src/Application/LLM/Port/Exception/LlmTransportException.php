<?php

declare(strict_types=1);

namespace App\Application\LLM\Port\Exception;

/**
 * The provider itself is unhealthy or unreachable: a connection/timeout error, a
 * 5xx, or a malformed response. These are the failures a circuit breaker should
 * react to — they mean "stop hammering this provider for a bit".
 *
 * Extends \RuntimeException so every existing `catch (\Throwable|\RuntimeException)`
 * keeps working unchanged.
 */
final class LlmTransportException extends \RuntimeException
{
}

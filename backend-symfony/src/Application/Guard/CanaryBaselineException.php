<?php

declare(strict_types=1);

namespace App\Application\Guard;

/**
 * The frozen canary baseline could not be loaded, trusted, or parsed — it is missing,
 * unreadable, not valid JSON, fails its .sha256 integrity check (tampered), or is not a
 * canary aggregate. The gate fails CLOSED on any of these.
 */
final class CanaryBaselineException extends \RuntimeException
{
}

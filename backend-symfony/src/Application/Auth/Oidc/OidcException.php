<?php

declare(strict_types=1);

namespace App\Application\Auth\Oidc;

/**
 * Raised on any OIDC protocol, configuration or trust failure. Callers map it to
 * an HTTP 4xx — it never carries provider secrets in its message.
 */
final class OidcException extends \RuntimeException
{
}

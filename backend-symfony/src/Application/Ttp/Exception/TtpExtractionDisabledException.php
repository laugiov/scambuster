<?php

declare(strict_types=1);

namespace App\Application\Ttp\Exception;

/**
 * Raised when the TTP extraction module is switched off for this deployment
 * (TTP_EXTRACTION_ENABLED=false). The HTTP layer maps it to 503 so callers
 * can distinguish "disabled by configuration" from a request error.
 */
final class TtpExtractionDisabledException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('TTP extraction is disabled on this deployment');
    }
}

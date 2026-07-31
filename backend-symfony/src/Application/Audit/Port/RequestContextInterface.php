<?php

declare(strict_types=1);

namespace App\Application\Audit\Port;

/**
 * Port exposing the current request's client IP and trace id, so the audit
 * layer captures them without depending on Symfony HttpFoundation or an
 * Infrastructure event listener directly.
 */
interface RequestContextInterface
{
    public function getClientIp(): ?string;

    public function getTraceId(): ?string;
}

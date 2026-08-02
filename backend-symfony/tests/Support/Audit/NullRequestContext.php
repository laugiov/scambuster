<?php

declare(strict_types=1);

namespace App\Tests\Support\Audit;

use App\Application\Audit\Port\RequestContextInterface;

/**
 * No-op RequestContextInterface for tests that construct AuditLogger without a
 * real HTTP request (ip/trace are passed explicitly to log() when needed).
 */
final class NullRequestContext implements RequestContextInterface
{
    public function getClientIp(): ?string
    {
        return null;
    }

    public function getTraceId(): ?string
    {
        return null;
    }
}

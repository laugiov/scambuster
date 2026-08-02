<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use App\Application\Audit\Port\RequestContextInterface;
use App\Infrastructure\EventListener\Security\TraceIdListener;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * RequestContextInterface backed by the current HTTP request. Returns null
 * outside a request (CLI, tests), preserving the previous AuditLogger behaviour.
 */
final readonly class HttpRequestContext implements RequestContextInterface
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    public function getClientIp(): ?string
    {
        return $this->requestStack->getCurrentRequest()?->getClientIp();
    }

    public function getTraceId(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            return null;
        }

        return TraceIdListener::getTraceId($request);
    }
}

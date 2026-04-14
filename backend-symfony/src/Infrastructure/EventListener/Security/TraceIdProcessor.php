<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener\Security;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Monolog processor that adds trace_id to every log record.
 *
 * Reads the trace_id from the current request attributes
 * (set by TraceIdListener on kernel.request).
 */
class TraceIdProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RequestStack $requestStack
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request instanceof \Symfony\Component\HttpFoundation\Request) {
            $traceId = $request->attributes->get(TraceIdListener::ATTRIBUTE_KEY);

            if (is_string($traceId) && $traceId !== '') {
                return $record->with(extra: array_merge($record->extra, ['trace_id' => $traceId]));
            }
        }

        return $record;
    }
}

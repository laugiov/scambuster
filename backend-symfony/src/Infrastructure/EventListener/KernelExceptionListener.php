<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

#[AsEventListener(event: 'kernel.exception')]
class KernelExceptionListener
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        // Log the exception with stack trace
        $this->logger->error($exception->getMessage(), ['exception' => $exception]);

        // If it's an HttpException, use its status code, otherwise 500
        $statusCode = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $message = 'Internal server error';

        // In dev, show the real message for easier debugging
        if (($_SERVER['APP_ENV'] ?? '') === 'dev') {
            $message = $exception->getMessage();
        }
        $response = new JsonResponse(['error' => $message], $statusCode);
        $event->setResponse($response);
    }
}

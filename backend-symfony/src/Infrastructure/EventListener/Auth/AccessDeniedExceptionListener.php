<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener\Auth;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AccessDeniedExceptionListener
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();

        if (! $e instanceof AccessDeniedException) {
            return;
        }

        $response = new JsonResponse(
            ['message' => 'Access Denied'],
            JsonResponse::HTTP_FORBIDDEN
        );
        $event->setResponse($response);
    }
}

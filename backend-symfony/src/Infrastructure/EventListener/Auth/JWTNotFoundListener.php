<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener\Auth;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use Symfony\Component\HttpFoundation\JsonResponse;

final class JWTNotFoundListener
{
    public function onJWTNotFound(JWTNotFoundEvent $event): void
    {
        $event->setResponse(new JsonResponse(
            ['message' => 'Full authentication is required'],
            JsonResponse::HTTP_UNAUTHORIZED
        ));
    }
}

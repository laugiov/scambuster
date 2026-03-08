<?php

declare(strict_types=1);

namespace App\UI\Http\User;

use App\UI\Http\Dto\ProtectedEndpointResponseDto;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Endpoint for CSRF + JWT tests
 */
#[Route('/api/v1/some-protected-endpoint', name: 'api_v1_protected_dummy', methods: ['POST'])]
final class DummyProtectedController
{
    /**
     * Checks CSRF token and returns a message.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // dd($this->getUser()); // Commenté pour éviter l'arrêt du code en prod
        $token = $request->headers->get('X-CSRF-TOKEN');

        if (null === $token || $token === '') {
            return new JsonResponse(new ProtectedEndpointResponseDto('CSRF token missing'), Response::HTTP_FORBIDDEN);
        }

        if ($token !== 'valid_csrf_token') {
            return new JsonResponse(new ProtectedEndpointResponseDto('CSRF token invalid'), Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(new ProtectedEndpointResponseDto('OK'));
    }
}

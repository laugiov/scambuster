<?php

declare(strict_types=1);

namespace App\UI\Http\User;

use App\UI\Http\Dto\ProtectedEndpointResponseDto;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1')]
final class TestProtectedController
{
    /**
     * Endpoint accessible aux utilisateurs avec ROLE_USER.
     */
    #[Route('/some-protected-endpoint', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function protectedEndpoint(): JsonResponse
    {
        return new JsonResponse(new ProtectedEndpointResponseDto('OK'));
    }

    /**
     * Endpoint accessible aux administrateurs.
     */
    #[Route('/admin/endpoint', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminEndpoint(): JsonResponse
    {
        return new JsonResponse(new ProtectedEndpointResponseDto('OK'));
    }
}

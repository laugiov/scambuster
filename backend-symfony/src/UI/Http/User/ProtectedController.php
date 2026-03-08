<?php

declare(strict_types=1);

namespace App\UI\Http\User;

use App\UI\Http\Dto\ProtectedEndpointResponseDto;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1')]
final class ProtectedController
{
    /**
     * Endpoint accessible aux utilisateurs avec ROLE_USER.
     */
    #[Route('/some-protected-endpoint', name: 'some_protected_endpoint', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function someProtectedEndpoint(): JsonResponse
    {
        return new JsonResponse(new ProtectedEndpointResponseDto('OK'));
    }

    /**
     * Endpoint accessible aux administrateurs.
     */
    #[Route('/admin-protected-endpoint', name: 'admin_protected_endpoint', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminProtectedEndpoint(): JsonResponse
    {
        return new JsonResponse(new ProtectedEndpointResponseDto('ADMIN OK'));
    }
}

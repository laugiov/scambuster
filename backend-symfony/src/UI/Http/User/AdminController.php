<?php

declare(strict_types=1);

namespace App\UI\Http\User;

use App\UI\Http\Dto\AdminWelcomeResponseDto;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Endpoint to welcome admin users.
 */
#[Route('/api/v1/admin', name: 'api_v1_admin', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
final class AdminController
{
    /**
     * Returns a welcome message for admin users.
     */
    public function __invoke(): JsonResponse
    {
        $dto = new AdminWelcomeResponseDto('Welcome, admin!');

        return new JsonResponse($dto);
    }
}

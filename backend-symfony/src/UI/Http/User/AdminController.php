<?php

declare(strict_types=1);

namespace App\UI\Http\User;

use App\UI\Http\Dto\AdminWelcomeResponseDto;
use OpenApi\Attributes as OA;
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
    #[OA\Get(
        path: '/api/v1/admin',
        summary: 'Admin welcome endpoint',
        description: 'Returns a welcome message for authenticated admin users.',
        tags: ['User'],
        responses: [
            new OA\Response(response: 200, description: 'Admin welcome message', content: new OA\JsonContent(
                type: 'object',
                properties: [new OA\Property(property: 'message', type: 'string', example: 'Welcome, admin!')]
            )),
            new OA\Response(response: 403, description: 'Forbidden — requires ROLE_ADMIN'),
        ],
        security: [['Bearer' => []]]
    )]
    public function __invoke(): JsonResponse
    {
        $dto = new AdminWelcomeResponseDto('Welcome, admin!');

        return new JsonResponse($dto);
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Http\User;

use App\Domain\User\User;
use App\UI\Http\Dto\MeResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Endpoint to retrieve the authenticated user's profile.
 */
#[Route('/api/v1/me', name: 'api_v1_me', methods: ['GET'])]
#[OA\Get(
    path: '/api/v1/me',
    summary: 'Get authenticated user profile',
    tags: ['Auth'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Profil utilisateur',
            content: new OA\JsonContent(ref: new Model(type: MeResponseDto::class))
        ),
        new OA\Response(
            response: 401,
            description: 'Not authenticated',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final class MeController
{
    /**
     * Returns the authenticated user's profile (id, email, roles).
     */
    public function __invoke(UserInterface $user): JsonResponse
    {
        $id = null;

        if ($user instanceof User) {
            $id = (string) $user->getId();
        }
        $dto = new MeResponseDto(
            $id,
            $user->getUserIdentifier(),
            $user->getRoles()
        );

        return new JsonResponse($dto);
    }
}

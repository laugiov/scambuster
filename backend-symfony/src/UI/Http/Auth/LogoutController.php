<?php

declare(strict_types=1);

namespace App\UI\Http\Auth;

use App\Application\Auth\AuthServiceInterface;
use App\Application\Auth\Dto\RefreshRequestDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
#[OA\Post(
    path: '/api/v1/auth/logout',
    summary: 'Déconnexion utilisateur (logout)',
    tags: ['Auth'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(ref: new Model(type: RefreshRequestDto::class))
    ),
    responses: [
        new OA\Response(
            response: 204,
            description: 'Déconnexion réussie (no content)'
        ),
        new OA\Response(
            response: 400,
            description: 'JSON invalide',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])
        ),
        new OA\Response(
            response: 401,
            description: 'Refresh token invalide ou déjà utilisé',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])
        ),
        new OA\Response(
            response: 422,
            description: 'Erreur de validation',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])
        )
    ]
)]
final readonly class LogoutController
{
    public function __construct(
        private AuthServiceInterface $handler,
        private ValidatorInterface $validator,
        private SerializerInterface $serializer
    ) {
    }
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $dto = $this->serializer->deserialize($request->getContent(), RefreshRequestDto::class, 'json');
        } catch (\Throwable $e) {
            return new JsonResponse(['message' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }
        $errors = $this->validator->validate($dto);

        if (count($errors) > 0) {
            return new JsonResponse(['message' => (string) $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->handler->logout($dto->refreshToken);
        } catch (\Throwable $e) {
            $message = strtolower($e->getMessage());
            $status = str_contains($message, 'invalid') || str_contains($message, 'already') ? Response::HTTP_UNAUTHORIZED : Response::HTTP_BAD_REQUEST;

            return new JsonResponse(['message' => $message], $status);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}

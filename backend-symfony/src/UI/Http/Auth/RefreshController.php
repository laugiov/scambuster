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
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/auth/refresh', name: 'api_auth_refresh', methods: ['POST'])]
#[OA\Post(
    path: '/api/v1/auth/refresh',
    summary: 'Rafraîchir le token JWT',
    tags: ['Auth'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(ref: new Model(type: RefreshRequestDto::class))
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Nouveaux tokens JWT',
            content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'access_token', type: 'string'),
                new OA\Property(property: 'refresh_token', type: 'string'),
                new OA\Property(property: 'expires_in', type: 'integer')
            ])
        ),
        new OA\Response(
            response: 400,
            description: 'JSON invalide',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])
        ),
        new OA\Response(
            response: 401,
            description: 'Refresh token invalide',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])
        ),
        new OA\Response(
            response: 422,
            description: 'Erreur de validation',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])
        )
    ]
)]
final readonly class RefreshController
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
            $response = $this->handler->refresh($dto);
        } catch (AuthenticationException $e) {
            return new JsonResponse(['message' => strtolower($e->getMessage())], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'access_token'  => $response->accessToken,
            'refresh_token' => $response->refreshToken,
            'expires_in'    => $response->expiresIn,
        ], Response::HTTP_OK);
    }
}

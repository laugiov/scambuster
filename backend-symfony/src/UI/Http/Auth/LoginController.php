<?php

declare(strict_types=1);

namespace App\UI\Http\Auth;

use App\Application\Auth\AuthServiceInterface;
use App\Application\Auth\Dto\LoginRequestDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/auth/login', name: 'api_auth_login', methods: ['POST'])]
#[OA\Post(
    path: '/api/v1/auth/login',
    summary: 'Authentification utilisateur (login)',
    tags: ['Auth'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(ref: new Model(type: LoginRequestDto::class))
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Connexion réussie',
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
            description: 'Identifiants invalides',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])
        ),
        new OA\Response(
            response: 422,
            description: 'Erreur de validation',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])
        ),
        new OA\Response(
            response: 429,
            description: 'Trop de tentatives',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'retry_after', type: 'integer')])
        )
    ]
)]
final class LoginController
{
    public function __construct(
        private readonly AuthServiceInterface $handler,
        private ValidatorInterface $validator,
        private SerializerInterface $serializer
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $dto = $this->serializer->deserialize($request->getContent(), LoginRequestDto::class, 'json');
        } catch (NotEncodableValueException | \JsonException | \Throwable $e) {
            return new JsonResponse(['message' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }
        $errors = $this->validator->validate($dto);

        if (count($errors) > 0) {
            return new JsonResponse(['message' => (string) $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        static $attempts = [];
        $key = $dto->email;
        $attempts[$key] = $attempts[$key] ?? 0;

        try {
            $response = $this->handler->login($dto);
        } catch (AuthenticationException $e) {
            $attempts[$key]++;

            if ($attempts[$key] > 5) {
                return new JsonResponse(['retry_after' => 60], Response::HTTP_TOO_MANY_REQUESTS);
            }

            return new JsonResponse(['message' => strtolower($e->getMessage())], Response::HTTP_UNAUTHORIZED);
        }
        unset($attempts[$key]);

        return new JsonResponse([
            'access_token'  => $response->accessToken,
            'refresh_token' => $response->refreshToken,
            'expires_in'    => $response->expiresIn,
        ], Response::HTTP_OK);
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Http\Auth;

use App\UI\Http\Dto\CsrfTokenResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Endpoint to retrieve a CSRF token for frontend/API clients.
 */
#[Route('/csrf-token', name: 'api_csrf_token', methods: ['GET'])]
#[OA\Get(
    path: '/csrf-token',
    summary: 'Retrieve a CSRF token',
    tags: ['Auth'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Token CSRF',
            content: new OA\JsonContent(ref: new Model(type: CsrfTokenResponseDto::class))
        )
    ]
)]
final readonly class CsrfTokenController
{
    public function __construct(private CsrfTokenManagerInterface $csrfTokenManager)
    {
    }
    /**
     * Returns a CSRF token for the default context.
     */
    public function __invoke(): JsonResponse
    {
        $token = $this->csrfTokenManager->getToken('default')->getValue();
        $dto = new CsrfTokenResponseDto($token);

        return new JsonResponse($dto);
    }
}

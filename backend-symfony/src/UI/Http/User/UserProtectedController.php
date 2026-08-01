<?php

declare(strict_types=1);

namespace App\UI\Http\User;

use App\UI\Http\Dto\ProtectedEndpointResponseDto;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/some-protected-endpoint', name: 'some_protected_endpoint', methods: ['POST'])]
#[IsGranted('ROLE_USER')]
final class UserProtectedController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(new ProtectedEndpointResponseDto('OK'));
    }
}

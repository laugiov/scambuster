<?php

declare(strict_types=1);

namespace App\UI\Http\Taxii;

use App\Application\Taxii\TaxiiService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ioc:read')]
final readonly class TaxiiApiRootController
{
    public function __construct(
        private TaxiiService $taxiiService,
    ) {
    }
    #[OA\Get(
        path: '/api/v1/taxii2/api/',
        summary: 'TAXII 2.1 API Root',
        description: 'Returns API root information including supported versions and content limits.',
        tags: ['TAXII'],
        responses: [
            new OA\Response(response: 200, description: 'API root information'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('/api/v1/taxii2/api/', name: 'taxii_api_root', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $response = new JsonResponse($this->taxiiService->getApiRoot());
        $response->headers->set('Content-Type', 'application/taxii+json;version=2.1');

        return $response;
    }
}

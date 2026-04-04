<?php

declare(strict_types=1);

namespace App\UI\Http\Taxii;

use App\Application\Taxii\TaxiiService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ioc:read')]
final class TaxiiDiscoveryController
{
    public function __construct(
        private readonly TaxiiService $taxiiService,
    ) {
    }

    #[OA\Get(
        path: '/api/v1/taxii2/',
        summary: 'TAXII 2.1 Discovery',
        description: 'Returns server discovery information including available API roots.',
        tags: ['TAXII'],
        responses: [
            new OA\Response(response: 200, description: 'TAXII discovery document'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('/api/v1/taxii2/', name: 'taxii_discovery', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $response = new JsonResponse($this->taxiiService->getDiscovery());
        $response->headers->set('Content-Type', 'application/taxii+json;version=2.1');

        return $response;
    }
}

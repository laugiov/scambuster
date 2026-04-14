<?php

declare(strict_types=1);

namespace App\UI\Http\Taxii;

use App\Application\Taxii\TaxiiService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ioc:read')]
final readonly class TaxiiCollectionsController
{
    public function __construct(
        private TaxiiService $taxiiService,
    ) {
    }
    #[OA\Get(
        path: '/api/v1/taxii2/api/collections/',
        summary: 'TAXII 2.1 Collections',
        description: 'Returns the list of available STIX collections.',
        tags: ['TAXII'],
        responses: [
            new OA\Response(response: 200, description: 'Collections list'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('/api/v1/taxii2/api/collections/', name: 'taxii_collections', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $response = new JsonResponse($this->taxiiService->getCollections());
        $response->headers->set('Content-Type', 'application/taxii+json;version=2.1');

        return $response;
    }
}

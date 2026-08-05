<?php

declare(strict_types=1);

namespace App\UI\Http\Taxii;

use App\Application\Taxii\TaxiiService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ioc:read')]
final readonly class TaxiiObjectsController
{
    public function __construct(
        private TaxiiService $taxiiService,
    ) {
    }
    #[OA\Get(
        path: '/api/v1/taxii2/api/collections/{collectionId}/objects/',
        summary: 'TAXII 2.1 Collection Objects',
        description: 'Returns STIX objects from a specific collection with optional filtering.',
        tags: ['TAXII'],
        parameters: [
            new OA\Parameter(name: 'collectionId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'added_after', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 100, maximum: 1000)),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'next', in: 'query', required: false, description: 'Opaque pagination cursor from a prior response envelope', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'STIX envelope with objects'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Unknown collection'),
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('/api/v1/taxii2/api/collections/{collectionId}/objects/', name: 'taxii_objects', methods: ['GET'])]
    public function __invoke(string $collectionId, Request $request): JsonResponse
    {
        if (!$this->taxiiService->isValidCollection($collectionId)) {
            return new JsonResponse(
                ['title' => 'Unknown collection', 'http_status' => 404],
                Response::HTTP_NOT_FOUND,
                ['Content-Type' => 'application/taxii+json;version=2.1']
            );
        }

        $addedAfter = null;
        $addedAfterParam = $request->query->get('added_after');

        if (\is_string($addedAfterParam) && $addedAfterParam !== '') {
            try {
                $addedAfter = new \DateTimeImmutable($addedAfterParam);
            } catch (\Exception) {
                // Ignore invalid date, proceed without filter
            }
        }

        $limit = (int) $request->query->get('limit', '100');
        $limit = min(max(1, $limit), 1000);

        $typeRaw = $request->query->get('type');
        $type = \is_string($typeRaw) && $typeRaw !== '' ? $typeRaw : null;

        $nextRaw = $request->query->get('next');
        $cursor = \is_string($nextRaw) && $nextRaw !== '' ? $nextRaw : null;

        $result = $this->taxiiService->getCollectionObjects($collectionId, $addedAfter, $limit, $type, $cursor);

        $response = new JsonResponse($result['envelope']);
        $response->headers->set('Content-Type', 'application/taxii+json;version=2.1');

        if ($result['firstAdded'] !== null) {
            $response->headers->set('X-TAXII-Date-Added-First', $result['firstAdded']);
        }

        if ($result['lastAdded'] !== null) {
            $response->headers->set('X-TAXII-Date-Added-Last', $result['lastAdded']);
        }

        return $response;
    }
}

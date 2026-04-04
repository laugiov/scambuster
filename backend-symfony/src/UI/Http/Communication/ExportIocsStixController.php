<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Stix\IocStixExportHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Export a selection of IOCs as a STIX 2.1 bundle (OpenCTI-compatible).
 *
 * Accepts indicator_ids from the frontend (filtered IOC Explorer list)
 * and builds a bundle with indicators + co-occurrence relationships.
 */
#[IsGranted('ioc:export')]
final class ExportIocsStixController
{
    public function __construct(
        private readonly IocStixExportHandler $handler,
    ) {
    }

    #[OA\Post(
        path: '/api/v1/iocs/export/stix',
        summary: 'Export selected IOCs as STIX 2.1 bundle',
        tags: ['Export'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(
                        property: 'indicator_ids',
                        type: 'array',
                        items: new OA\Items(type: 'string', format: 'uuid'),
                        description: 'List of indicator UUIDs to export (from IOC Explorer)'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'STIX 2.1 bundle JSON'),
            new OA\Response(response: 400, description: 'Missing or empty indicator_ids'),
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('/api/v1/iocs/export/stix', name: 'export_iocs_stix', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<int, string> $indicatorIds */
        $indicatorIds = [];

        if (isset($data['indicator_ids']) && is_array($data['indicator_ids'])) {
            $indicatorIds = array_filter($data['indicator_ids'], 'is_string');
        }

        if (empty($indicatorIds)) {
            return new JsonResponse(['error' => 'Missing or empty indicator_ids'], Response::HTTP_BAD_REQUEST);
        }

        $bundle = $this->handler->export($indicatorIds);

        return new JsonResponse($bundle, Response::HTTP_OK);
    }
}

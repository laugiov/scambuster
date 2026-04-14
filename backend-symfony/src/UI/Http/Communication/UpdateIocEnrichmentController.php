<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\IocHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Update IOC enrichment data
 *
 * This endpoint is used by n8n workflows to update enrichment data
 * (URLScan, VirusTotal) for IOCs that were previously extracted and persisted.
 *
 * Unlike the POST /enriched endpoint, this endpoint:
 * - Uses PATCH for idempotent updates (not inserts)
 * - Accepts obs_id (not msg_id) for direct IOC targeting
 * - Only updates enrichment field (no new IOC creation)
 * - Recalculates score based on new enrichment
 *
 * Workflow: POST /extract-iocs -> [URLScan + VT] -> PATCH /iocs/{obs_id}/enrich
 */
#[OA\Patch(
    path: '/api/v1/iocs/{obs_id}/enrich',
    summary: 'Update IOC enrichment data from external sources',
    tags: ['IOCs'],
    parameters: [
        new OA\Parameter(
            name: 'obs_id',
            in: 'path',
            required: true,
            description: 'Observation ID (UUID)',
            schema: new OA\Schema(type: 'string', format: 'uuid')
        )
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: 'object',
            required: ['enrichment'],
            properties: [
                new OA\Property(
                    property: 'enrichment',
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'urlscan',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'status', type: 'string', enum: ['completed', 'error', 'timeout', 'n/a']),
                                new OA\Property(property: 'verdict', type: 'string', enum: ['malicious', 'suspicious', 'clean', 'unknown']),
                                new OA\Property(property: 'positives', type: 'integer'),
                                new OA\Property(property: 'permalink', type: 'string', nullable: true)
                            ]
                        ),
                        new OA\Property(
                            property: 'virustotal',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'harmless', type: 'integer'),
                                new OA\Property(property: 'malicious', type: 'integer'),
                                new OA\Property(property: 'suspicious', type: 'integer'),
                                new OA\Property(property: 'undetected', type: 'integer'),
                                new OA\Property(property: 'permalink', type: 'string', nullable: true)
                            ]
                        )
                    ]
                )
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'IOC enrichment updated successfully',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'obs_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'updated', type: 'boolean')
                ]
            )
        ),
        new OA\Response(
            response: 400,
            description: 'Invalid request format or missing enrichment field',
            content: new OA\JsonContent(
                type: 'object',
                properties: [new OA\Property(property: 'error', type: 'string')]
            )
        ),
        new OA\Response(
            response: 404,
            description: 'IOC not found',
            content: new OA\JsonContent(
                type: 'object',
                properties: [new OA\Property(property: 'error', type: 'string')]
            )
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
#[IsGranted('ioc:read')]
final readonly class UpdateIocEnrichmentController
{
    public function __construct(
        private IocHandler $handler
    ) {
    }
    #[Route('/api/v1/iocs/{obs_id}/enrich', name: 'update_ioc_enrichment', methods: ['PATCH'])]
    public function __invoke(string $obs_id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        // Validate required fields
        if (!isset($data['enrichment']) || !is_array($data['enrichment'])) {
            return new JsonResponse(
                ['error' => 'Missing or invalid field: enrichment'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Delegate to handler
        try {
            $observedIoc = $this->handler->updateIocEnrichment($obs_id, $data['enrichment']);

            return new JsonResponse([
                'obs_id' => $observedIoc->getObsId(),
                'updated' => true
            ], Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            // IOC not found
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }
}

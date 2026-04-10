<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\IocExtractor;
use App\Application\Communication\IocHandler;
use App\UI\Http\Dto\IocEnrichedResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for IOC (Indicator of Compromise) enrichment endpoints
 *
 * Handles ingestion of enriched IOCs from n8n workflows and provides
 * risk scoring for the decision gate.
 *
 * IMPORTANT: This controller follows DDD architecture:
 * - final class
 * - Single responsibility
 * - ZERO EntityManager access
 * - Full delegation to IocHandler
 *
 * Spec: specs/05-normaliser-decider.md §3
 */
#[Route('/api/v1/iocs')]
#[IsGranted('ioc:read')]
final class IocController
{
    public function __construct(
        private readonly IocHandler $handler
    ) {
    }

    /**
     * List all IOCs with confidence scoring.
     *
     * Returns all IOCs enriched with confidence, decay_factor, and effective_score.
     * Use ?min_score=0.5 to filter out low-confidence IOCs.
     */
    #[OA\Get(
        path: '/api/v1/iocs',
        summary: 'List all IOCs with confidence scoring',
        tags: ['IOCs'],
        parameters: [
            new OA\Parameter(
                name: 'min_score',
                in: 'query',
                required: false,
                description: 'Minimum effective_score to include (0.0-1.0)',
                schema: new OA\Schema(type: 'number', format: 'float')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of IOCs with confidence data',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(type: 'object', properties: [
                        new OA\Property(property: 'obs_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'ioc_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'type', type: 'string'),
                        new OA\Property(property: 'value', type: 'string'),
                        new OA\Property(property: 'value_norm', type: 'string'),
                        new OA\Property(property: 'score', type: 'object'),
                        new OA\Property(property: 'category', type: 'string'),
                        new OA\Property(property: 'ts_observed', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'confidence', type: 'number', format: 'float'),
                        new OA\Property(property: 'decay_factor', type: 'number', format: 'float'),
                        new OA\Property(property: 'effective_score', type: 'number', format: 'float'),
                    ])
                )
            )
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('', name: 'list_iocs', methods: ['GET'])]
    public function listIocs(Request $request): JsonResponse
    {
        $minScoreParam = $request->query->get('min_score');
        $minScore = $minScoreParam !== null ? (float) $minScoreParam : null;

        $iocs = $this->handler->getAllIocsWithConfidence($minScore);

        return new JsonResponse($iocs, Response::HTTP_OK);
    }

    /**
     * Ingest enriched IOC from n8n workflow
     *
     * This endpoint receives IOCs that have been extracted and enriched
     * (VirusTotal + URLscan) by n8n workflows. It performs:
     * - Idempotent upsert (deduplication by msg_id + type + value_norm)
     * - Risk score calculation
     * - Category assignment (B2B/Credential/Gov)
     *
     * The endpoint supports both external_message_id (Gmail/Outlook ID)
     * and internal msg_id (UUID) for message resolution.
     */
    #[OA\Post(
        path: '/api/v1/iocs/enriched',
        summary: 'Ingest enriched IOC from n8n workflow',
        tags: ['IOCs'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['ioc'],
                properties: [
                    new OA\Property(
                        property: 'message_id',
                        type: 'string',
                        description: 'External message ID (Gmail Message-ID, Outlook ID, etc.)',
                        example: '<CABcD1234@mail.gmail.com>'
                    ),
                    new OA\Property(
                        property: 'msg_id',
                        type: 'string',
                        format: 'uuid',
                        description: 'Internal message UUID (fallback if message_id not found)'
                    ),
                    new OA\Property(
                        property: 'ioc',
                        type: 'object',
                        required: ['type', 'value', 'value_norm', 'source', 'first_seen'],
                        properties: [
                            new OA\Property(property: 'type', type: 'string', enum: ['url', 'domain', 'ip', 'hash', 'email', 'iban', 'phone']),
                            new OA\Property(property: 'value', type: 'string', example: 'https://evil-site.com/login'),
                            new OA\Property(property: 'value_norm', type: 'string', example: 'evil-site.com/login'),
                            new OA\Property(property: 'source', type: 'string', enum: ['body', 'header', 'attachment']),
                            new OA\Property(property: 'first_seen', type: 'string', format: 'date-time')
                        ]
                    ),
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
                    ),
                    new OA\Property(
                        property: 'score',
                        type: 'object',
                        description: 'Optional pre-calculated score (will be calculated if missing)',
                        properties: [
                            new OA\Property(property: 'vt', type: 'integer'),
                            new OA\Property(property: 'urlscan', type: 'integer'),
                            new OA\Property(property: 'agg', type: 'integer'),
                            new OA\Property(property: 'explain', type: 'string')
                        ]
                    ),
                    new OA\Property(
                        property: 'category',
                        type: 'string',
                        enum: ['B2B_invoice_change', 'Credential_phish', 'Gov_impersonation'],
                        description: 'Optional category (will be guessed if missing)'
                    ),
                    new OA\Property(
                        property: 'tags',
                        type: 'array',
                        items: new OA\Items(type: 'string'),
                        example: ['phishing']
                    ),
                    new OA\Property(property: 'tlp', type: 'string', enum: ['WHITE', 'GREEN', 'AMBER', 'RED'], example: 'AMBER')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'IOC ingested successfully',
                content: new OA\JsonContent(ref: new Model(type: IocEnrichedResponseDto::class))
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid request format or missing required fields',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Message not found',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/enriched', name: 'ingest_enriched_ioc', methods: ['POST'])]
    public function ingestEnrichedIoc(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        // Validate required fields
        if (empty($data['ioc']) || !is_array($data['ioc'])) {
            return new JsonResponse(['error' => 'Missing or invalid field: ioc'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, string> $iocData */
        $iocData = $data['ioc'];
        $requiredIocFields = ['type', 'value', 'value_norm', 'source', 'first_seen'];

        foreach ($requiredIocFields as $field) {
            if (empty($iocData[$field])) {
                return new JsonResponse(
                    ['error' => "Missing required IOC field: $field"],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        // Validate IOC type against the canonical list + known aliases
        $validTypes = array_merge(
            IocExtractor::getSupportedTypes(),
            ['ip', 'hash', 'file_hash']
        );

        if (!in_array($iocData['type'], $validTypes, true)) {
            return new JsonResponse(
                ['error' => 'Invalid IOC type: ' . $iocData['type']],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Delegate to handler (all business logic there)
        try {
            /** @var array{message_id?: string, msg_id?: string, ioc: array{type: string, value: string, value_norm: string, source: string, first_seen: string}, enrichment?: array<string, mixed>, score?: array<string, mixed>, category?: string, tags?: array<string>, tlp?: string} $data */
            $observedIoc = $this->handler->upsertEnrichedIoc($data);
            $risk = $this->handler->calculateMessageRisk($observedIoc->getMessage()->getMsgId());

            $dto = new IocEnrichedResponseDto(
                $observedIoc->getObsId(),
                $risk
            );

            return new JsonResponse($dto->toArray(), Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            // Spec 061: outgoing-message guard or honeypot-address filter rejection
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            // Message not found or other business error
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }

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
     * Workflow: POST /extract-iocs → [URLScan + VT] → PATCH /iocs/{obs_id}/enrich
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
    #[Route('/{obs_id}/enrich', name: 'update_ioc_enrichment', methods: ['PATCH'])]
    public function updateIocEnrichment(string $obs_id, Request $request): JsonResponse
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

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
#[IsGranted('ioc:read')]
final readonly class IngestEnrichedIocController
{
    public function __construct(
        private IocHandler $handler
    ) {
    }
    #[Route('/api/v1/iocs/enriched', name: 'ingest_enriched_ioc', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
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
}

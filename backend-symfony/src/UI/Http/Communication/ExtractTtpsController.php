<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Ttp\Exception\OutgoingMessageException;
use App\Application\Ttp\Exception\TtpExtractionDisabledException;
use App\Application\Ttp\TtpHandler;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Post(
    path: '/api/v1/communication/message/{msgId}/extract-ttps',
    summary: 'Extract scammer TTPs from an inbound message against the closed taxonomy',
    tags: ['Messages', 'TTPs'],
    parameters: [
        new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(
        required: false,
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'persist',
                    type: 'boolean',
                    description: 'Persist the observations (idempotent per message and TTP). Set to false for a dry run.',
                    default: true
                ),
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'TTPs extracted successfully',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'msg_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'ttps_found', type: 'integer', description: 'Number of TTP observations found'),
                    new OA\Property(property: 'persisted', type: 'integer', description: 'Number of observations actually inserted (0 on re-runs)'),
                    new OA\Property(
                        property: 'observations',
                        type: 'array',
                        description: 'Observations without evidence verbatims (evidence is stored in database only)',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'ttp_code', type: 'string', example: 'SB-T017'),
                                new OA\Property(property: 'confidence', type: 'number', format: 'float', example: 0.92),
                                new OA\Property(property: 'status', type: 'string', enum: ['confirmed', 'review']),
                                new OA\Property(property: 'evidence_start', type: 'integer', nullable: true),
                                new OA\Property(property: 'evidence_end', type: 'integer', nullable: true),
                            ]
                        )
                    ),
                    new OA\Property(property: 'extraction_time_ms', type: 'integer'),
                ]
            )
        ),
        new OA\Response(
            response: 404,
            description: 'Message not found',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
        new OA\Response(
            response: 400,
            description: 'Invalid request or outgoing message',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
        new OA\Response(
            response: 503,
            description: 'TTP extraction is disabled on this deployment',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: false),
                    new OA\Property(property: 'error', type: 'string'),
                ]
            )
        ),
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final readonly class ExtractTtpsController
{
    public function __construct(
        private TtpHandler $ttpHandler,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/api/v1/communication/message/{msgId}/extract-ttps', name: 'extract_message_ttps', methods: ['POST'])]
    #[IsGranted('conversation:write')]
    public function __invoke(string $msgId, Request $request): JsonResponse
    {
        // Parse request body
        $data = json_decode($request->getContent(), true);

        if ($data === null && $request->getContent() !== '' && $request->getContent() !== '{}') {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $data */
        $persist = (bool) ($data['persist'] ?? true);

        $startTime = microtime(true);

        try {
            $result = $this->ttpHandler->extractForMessage($msgId, $persist);
        } catch (TtpExtractionDisabledException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'TTP extraction is disabled on this deployment',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (OutgoingMessageException $e) {
            // Refuse extraction on outgoing messages: TTPs describe the scammer's
            // behaviour, our own generated replies must never be tagged.
            $this->logger->warning('[TTP-EXTRACT] Refused outgoing message extraction', [
                'msg_id' => $msgId,
                'direction' => $e->getDirection(),
            ]);

            return new JsonResponse([
                'error' => 'TTP extraction is not allowed on outgoing messages',
                'msg_id' => $msgId,
                'direction' => $e->getDirection(),
            ], Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            $this->logger->warning('[TTP-EXTRACT] Message not found', [
                'msg_id' => $msgId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        $extractionTime = (int) ((microtime(true) - $startTime) * 1000);

        $this->logger->info('[TTP-EXTRACT] TTPs extracted successfully', [
            'msg_id' => $msgId,
            'ttps_found' => $result['ttps_found'],
            'persisted' => $result['persisted'],
            'extraction_time_ms' => $extractionTime,
        ]);

        return new JsonResponse($result + ['extraction_time_ms' => $extractionTime], Response::HTTP_OK);
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\IocHandler;
use App\Application\Communication\MessageHandler;
use App\Domain\Communication\Policy\IocExtractionPolicy;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Post(
    path: '/api/v1/communication/message/{msgId}/extract-iocs',
    summary: 'Extract IOCs from a message using regex, LLM, or hybrid approach',
    tags: ['Messages', 'IOCs'],
    parameters: [
        new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(
        required: false,
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'method',
                    type: 'string',
                    enum: ['regex', 'llm', 'hybrid'],
                    description: 'Extraction method: regex (fast, known patterns), llm (contextual, AI-powered), hybrid (combines both)',
                    default: 'hybrid'
                ),
                new OA\Property(
                    property: 'types',
                    type: 'array',
                    items: new OA\Items(type: 'string'),
                    description: 'IOC types to extract (e.g., ["ipv4", "url", "email"]). If empty, extracts all types.',
                    example: ['ipv4', 'url', 'email']
                ),
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'IOCs extracted successfully',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'msg_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'method', type: 'string', enum: ['regex', 'llm', 'hybrid']),
                    new OA\Property(property: 'iocs_found', type: 'integer', description: 'Number of IOCs found'),
                    new OA\Property(
                        property: 'iocs',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'type', type: 'string', example: 'ipv4'),
                                new OA\Property(property: 'value', type: 'string', example: '192.168.1.1'),
                                new OA\Property(property: 'value_norm', type: 'string', example: '192.168.1.1'),
                                new OA\Property(property: 'context', type: 'object'),
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
            description: 'Invalid method or parameters',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final readonly class ExtractIocsController
{
    public function __construct(
        private MessageHandler $handler,
        private IocHandler $iocHandler,
        private LoggerInterface $logger,
        // Spec 065h — extracted from Message::canExtractIocs()
        private IocExtractionPolicy $iocExtractionPolicy = new IocExtractionPolicy()
    ) {
    }
    #[Route('/api/v1/communication/message/{msgId}/extract-iocs', name: 'extract_message_iocs', methods: ['POST'])]
    #[IsGranted('conversation:write')]
    public function __invoke(string $msgId, Request $request): JsonResponse
    {
        $this->logger->info('[IOC-EXTRACT-DEBUG] Starting IOC extraction', [
            'msg_id' => $msgId,
            'request_content' => $request->getContent(),
        ]);

        // Get message
        $message = $this->handler->getMessage($msgId);

        if (!$message || $message->getDeletedAt() instanceof \DateTimeImmutable) {
            $this->logger->warning('[IOC-EXTRACT-DEBUG] Message not found', [
                'msg_id' => $msgId,
                'message_exists' => $message instanceof \App\Domain\Communication\Message,
                'deleted_at' => $message instanceof \App\Domain\Communication\Message ? $message->getDeletedAt() : 'n/a',
            ]);

            return new JsonResponse(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        // Spec 061: refuse extraction on outgoing messages.
        // Outgoing messages are LLM replies and pollute the indicator table with our
        // own headers + fictional 555 phone numbers invented by the persona.
        if (!$this->iocExtractionPolicy->allows($message)) {
            $this->logger->warning('[IOC-EXTRACT] Refused outgoing message extraction (spec 061)', [
                'msg_id' => $msgId,
                'direction' => $message->getDirection()->getCode(),
            ]);

            return new JsonResponse([
                'error' => 'IOC extraction is not allowed on outgoing messages',
                'msg_id' => $msgId,
                'direction' => $message->getDirection()->getCode(),
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->logger->info('[IOC-EXTRACT-DEBUG] Message found', [
            'msg_id' => $msgId,
            'body_length' => strlen($message->getBodyText()),
            'body_preview' => substr($message->getBodyText(), 0, 200),
        ]);

        // Parse request body
        $data = json_decode($request->getContent(), true);

        if ($data === null && $request->getContent() !== '' && $request->getContent() !== '{}') {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $data */
        $method = $data['method'] ?? 'hybrid';
        /** @var array<int, string> $types */
        $types = $data['types'] ?? [];
        $persist = (bool) ($data['persist'] ?? false); // New parameter to persist IOCs

        $this->logger->info('[IOC-EXTRACT-DEBUG] Extraction parameters', [
            'msg_id' => $msgId,
            'method' => $method,
            'types' => $types,
            'persist' => $persist,
        ]);

        // Validate method
        $validMethods = ['regex', 'llm', 'hybrid'];

        if (!in_array($method, $validMethods, true)) {
            return new JsonResponse(
                ['error' => 'Invalid method. Must be one of: ' . implode(', ', $validMethods)],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Extract IOCs using IocHandler
        $startTime = microtime(true);

        try {
            $this->logger->info('[IOC-EXTRACT-DEBUG] Calling IocHandler->extractIocsFromMessage', [
                'msg_id' => $msgId,
                'method' => $method,
            ]);

            $iocs = $this->iocHandler->extractIocsFromMessage($msgId, $method, $types, $persist);
            $extractionTime = (int) ((microtime(true) - $startTime) * 1000);

            $this->logger->info('[IOC-EXTRACT-DEBUG] IOCs extracted successfully', [
                'msg_id' => $msgId,
                'iocs_found' => count($iocs),
                'iocs_preview' => array_slice($iocs, 0, 3),
                'extraction_time_ms' => $extractionTime,
            ]);

            $response = [
                'msg_id' => $msgId,
                'method' => $method,
                'iocs_found' => count($iocs),
                'iocs' => $iocs,
                'extraction_time_ms' => $extractionTime,
                'persisted' => $persist,
            ];

            // If persisted, add count of successfully added IOCs
            if ($persist) {
                $response['added'] = count($iocs);
            }

            return new JsonResponse($response, Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            $this->logger->error('[IOC-EXTRACT-DEBUG] Extraction failed with exception', [
                'msg_id' => $msgId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse(['error' => 'IOC extraction failed'], Response::HTTP_BAD_REQUEST);
        }
    }
}

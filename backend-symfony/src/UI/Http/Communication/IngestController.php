<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\IngestHandler;
use App\Application\Communication\IngestRawRequestDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/communication/ingest')]
final class IngestController
{
    public function __construct(
        private IngestHandler $handler,
        private ValidatorInterface $validator,
        private SerializerInterface $serializer,
        private LoggerInterface $logger
    ) {
    }

    #[OA\Post(
        path: '/api/v1/communication/ingest/raw',
        summary: 'Ingestion brute d\'un email (format RFC822)',
        tags: ['Ingest'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: new Model(type: IngestRawRequestDto::class))
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Message ingéré',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'msg_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'conv_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'status', type: 'string', example: 'ingested')
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Erreur de validation ou de référence',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            ),
            new OA\Response(
                response: 409,
                description: 'Conflit (doublon)',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            ),
            new OA\Response(
                response: 422,
                description: 'Erreur de validation (Unprocessable Entity)',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/raw', name: 'ingest_raw', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $this->logger->info('[IngestController] Received ingest request');

        try {
            $dto = $this->serializer->deserialize($request->getContent(), IngestRawRequestDto::class, 'json');
        } catch (\Throwable $e) {
            // Désérialisation JSON malformé (NotEncodableValueException, JsonException, etc.)
            $this->logger->error('[IngestController] JSON deserialization error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $errors = $this->validator->validate($dto);

        if (count($errors) > 0) {
            $this->logger->warning('[IngestController] Validation errors', ['errors' => (string) $errors]);

            return new JsonResponse(['error' => (string) $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->handler->ingest($dto);
            $this->logger->info('[IngestController] Ingestion successful', $result);

            return new JsonResponse($result, Response::HTTP_CREATED);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'Message already ingested (composite_hash conflict)' ? 409 : 400;
            $this->logger->error('[IngestController] Ingestion error', [
                'error' => $e->getMessage(),
                'code' => $code,
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse(['error' => $e->getMessage()], $code);
        } catch (\Throwable $e) {
            $this->logger->error('[IngestController] Unexpected error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse(['error' => 'Internal server error: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

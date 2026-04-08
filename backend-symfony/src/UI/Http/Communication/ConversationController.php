<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ConversationHandler;
use App\Application\Communication\IocHandler;
use App\Application\Communication\ScamClassificationHandler;
use App\Domain\Communication\ConversationStatus;
use App\UI\Http\Dto\ConversationCreateResponseDto;
use App\UI\Http\Dto\ConversationDeleteResponseDto;
use App\UI\Http\Dto\ConversationDetailResponseDto;
use App\UI\Http\Dto\ConversationListItemDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/communication/conversation')]
final class ConversationController
{
    public function __construct(
        private readonly ConversationHandler $handler,
        private readonly IocHandler $iocHandler,
        private readonly ScamClassificationHandler $classificationHandler
    ) {
    }

    #[OA\Post(
        path: '/api/v1/communication/conversation',
        summary: 'Créer une conversation',
        tags: ['Conversations'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['primary_channel_id', 'scam_type_id', 'account_id', 'status', 'score_risk', 'ts_first', 'ts_last', 'stix_id'],
                properties: [
                    new OA\Property(property: 'primary_channel_id', type: 'integer'),
                    new OA\Property(property: 'scam_type_id', type: 'integer'),
                    new OA\Property(property: 'account_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'status', type: 'string', example: 'open'),
                    new OA\Property(property: 'score_risk', type: 'integer'),
                    new OA\Property(property: 'ts_first', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'ts_last', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'stix_id', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Conversation créée',
                content: new OA\JsonContent(ref: new Model(type: ConversationCreateResponseDto::class))
            ),
            new OA\Response(
                response: 400,
                description: 'Erreur de validation ou de référence',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string')
                    ]
                )
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('', name: 'create_conversation', methods: ['POST'])]
    #[IsGranted('conversation:write')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        foreach (['primary_channel_id', 'scam_type_id', 'account_id', 'status', 'score_risk', 'ts_first', 'ts_last', 'stix_id'] as $field) {
            if (empty($data[$field])) {
                return new JsonResponse(['error' => "Missing field: $field"], Response::HTTP_BAD_REQUEST);
            }
        }
        /** @var array<string, string|int|null> $data */
        $channel = $this->handler->getChannel((string)$data['primary_channel_id']);
        $scamType = $this->handler->getScamType((string)$data['scam_type_id']);
        $account = $this->handler->getMailAccount((string)$data['account_id']);
        $status = ConversationStatus::tryFrom((string)$data['status']);

        if (!$channel || !$scamType || !$account || !$status) {
            return new JsonResponse(['error' => 'Invalid reference'], Response::HTTP_BAD_REQUEST);
        }
        $scoreRisk = (int)$data['score_risk'];
        $tsFirst = new \DateTimeImmutable((string)$data['ts_first']);
        $tsLast = new \DateTimeImmutable((string)$data['ts_last']);
        $stixId = (string)$data['stix_id'];
        $conv = $this->handler->createConversation(
            $channel,
            $scamType,
            $account,
            $status,
            $scoreRisk,
            $tsFirst,
            $tsLast,
            $stixId
        );
        $dto = new ConversationCreateResponseDto($conv->getConvId(), $conv->getStatus()->value);

        return new JsonResponse($dto->toArray(), Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/communication/conversation/{convId}',
        summary: 'Détail d\'une conversation',
        tags: ['Conversations'],
        parameters: [
            new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Détail de la conversation',
                content: new OA\JsonContent(ref: new Model(type: ConversationDetailResponseDto::class))
            ),
            new OA\Response(
                response: 404,
                description: 'Conversation non trouvée',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/{convId}', name: 'get_conversation', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function getConversation(string $convId): JsonResponse
    {
        $conv = $this->handler->getConversation($convId);

        if (!$conv || $conv->getDeletedAt() !== null) {
            return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
        }
        $links = $this->handler->getConversationChannels($conv);
        $channels = array_map(function ($link) {
            return [
                'channel_id' => $link->getChannel()->getChannelId(),
                'code' => $link->getChannel()->getCode(),
                'label' => $link->getChannel()->getLabelFr(),
            ];
        }, $links);
        $dto = new ConversationDetailResponseDto(
            $conv->getConvId(),
            $conv->getStatus()->value,
            $conv->getScoreRisk(),
            $conv->getTsFirst()->format(DATE_ATOM),
            $conv->getTsLast()->format(DATE_ATOM),
            $conv->getStixId(),
            $channels
        );

        return new JsonResponse($dto->toArray(), Response::HTTP_OK);
    }

    #[OA\Get(
        path: '/api/v1/communication/conversation',
        summary: 'Liste paginée des conversations',
        tags: ['Conversations'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste paginée des conversations',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: new Model(type: ConversationListItemDto::class))
                )
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('', name: 'list_conversations', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function listConversations(Request $request): JsonResponse
    {
        $page = max(1, (int)$request->query->get('page', '1'));
        $limit = max(1, (int)$request->query->get('limit', '20'));
        $offset = ($page - 1) * $limit;
        /** @var string|null $status */
        $status = $request->query->get('status');
        /** @var string|null $from */
        $from = $request->query->get('from');
        /** @var string|null $to */
        $to = $request->query->get('to');
        $convs = $this->handler->getFilteredConversations($page, $limit, $status, $from, $to);
        $convIds = array_values(array_map(static fn ($c) => $c->getConvId(), $convs));
        $messageCounts = $this->handler->getMessageCountsForConversations($convIds);
        $result = array_map(function ($conv) use ($messageCounts) {
            $persona = $conv->getPersona();
            $scamType = $conv->getScamType();
            $dto = new ConversationListItemDto(
                $conv->getConvId(),
                $conv->getStatus()->value,
                $conv->getScoreRisk(),
                $conv->getTsFirst()->format(DATE_ATOM),
                $conv->getTsLast()->format(DATE_ATOM),
                $conv->getStixId(),
                $persona?->getPersonaCode(),
                $scamType->getCode(),
                $conv->getTurnsCount(),
                $messageCounts[$conv->getConvId()] ?? 0,
                $conv->getRewardValue(),
            );

            return $dto->toArray();
        }, $convs);

        return new JsonResponse($result, Response::HTTP_OK);
    }

    #[OA\Delete(
        path: '/api/v1/communication/conversation/{convId}',
        summary: 'Supprimer une conversation',
        tags: ['Conversations'],
        parameters: [
            new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conversation supprimée',
                content: new OA\JsonContent(ref: new Model(type: ConversationDeleteResponseDto::class))
            ),
            new OA\Response(
                response: 404,
                description: 'Conversation non trouvée',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/{convId}', name: 'delete_conversation', methods: ['DELETE'])]
    #[IsGranted('conversation:write')]
    public function deleteConversation(string $convId): JsonResponse
    {
        $ok = $this->handler->deleteConversation($convId);

        if (!$ok) {
            return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
        }
        $dto = new ConversationDeleteResponseDto('Conversation deleted');

        return new JsonResponse($dto->toArray(), Response::HTTP_OK);
    }

    #[OA\Patch(
        path: '/api/v1/communication/conversation/{convId}',
        summary: 'Modifier une conversation',
        tags: ['Conversations'],
        parameters: [
            new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'open', description: 'Statut de la conversation'),
                    new OA\Property(property: 'score_risk', type: 'integer', example: 75, description: 'Score de risque (0-100)'),
                    new OA\Property(property: 'ts_last', type: 'string', format: 'date-time', description: 'Date du dernier message'),
                    new OA\Property(property: 'stix_id', type: 'string', description: 'Identifiant STIX'),
                    new OA\Property(property: 'scam_type_id', type: 'integer', example: 4, description: 'ID du type de scam (permet de changer le persona utilisé)')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conversation modifiée',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])
            ),
            new OA\Response(
                response: 400,
                description: 'Erreur de validation',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            ),
            new OA\Response(
                response: 404,
                description: 'Conversation non trouvée',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/{convId}', name: 'patch_conversation', methods: ['PATCH'])]
    #[IsGranted('conversation:write')]
    public function patchConversation(string $convId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $conv = $this->handler->patchConversation($convId, $data);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        if ($conv === false) {
            return new JsonResponse(['error' => 'No change'], 400);
        }

        if (!$conv) {
            return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['message' => 'Conversation updated'], 200);
    }

    #[Route('/{convId}/add-channel', name: 'add_channel_to_conversation', methods: ['POST'])]
    #[IsGranted('conversation:write')]
    public function addChannel(string $convId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || empty($data['channel_id'])) {
            return new JsonResponse(['error' => 'Missing channel_id'], Response::HTTP_BAD_REQUEST);
        }
        /** @var string|int $channelId */
        $channelId = $data['channel_id'];
        $channel = $this->handler->getChannel((string) $channelId);

        if (!$channel) {
            return new JsonResponse(['error' => 'Invalid reference'], Response::HTTP_BAD_REQUEST);
        }
        $ok = $this->handler->addChannelToConversation($convId, $channel);

        if ($ok) {
            return new JsonResponse(['message' => 'Channel added to conversation'], Response::HTTP_OK);
        }

        return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
    }

    #[OA\Get(
        path: '/api/v1/communication/conversation/{convId}/iocs',
        summary: 'Liste des IOCs d\'une conversation (dédupliqués)',
        tags: ['Conversations'],
        parameters: [
            new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des IOCs dédupliqués',
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
                        new OA\Property(property: 'ts_observed', type: 'string', format: 'date-time')
                    ])
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Conversation non trouvée',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/{convId}/iocs', name: 'list_conversation_iocs', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function listIocs(string $convId): JsonResponse
    {
        $conv = $this->handler->getConversation($convId);

        if (!$conv || $conv->getDeletedAt() !== null) {
            return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
        }

        // Delegate to IocHandler for deduplicated IOC list
        $iocs = $this->iocHandler->getConversationIocs($convId);

        // Batch-load confidence data for all IOCs in a single query
        $indicatorIds = [];
        $confidenceScores = [];
        $tsObservedMap = [];

        foreach ($iocs as $ioc) {
            $id = $ioc->getIndicatorId();
            $indicatorIds[] = $id;
            $confidenceScores[$id] = $ioc->getConfidenceScore();
            $tsObservedMap[$id] = $ioc->getTsObserved();
        }

        $confidenceBatch = $this->iocHandler->batchComputeConfidenceData($indicatorIds, $confidenceScores, $tsObservedMap);

        $result = array_map(function ($ioc) use ($confidenceBatch) {
            $confidenceData = $confidenceBatch[$ioc->getIndicatorId()] ?? [
                'confidence' => round($ioc->getConfidenceScore() ?? 0.80, 4),
                'decay_factor' => 1.0,
                'effective_score' => round($ioc->getConfidenceScore() ?? 0.80, 4),
            ];

            return [
                'obs_id' => $ioc->getObsId(),
                'ioc_id' => $ioc->getIndicatorId(),
                'type' => $ioc->getContext()['type'] ?? '',
                'value' => $ioc->getContext()['value'] ?? '',
                'value_norm' => $ioc->getContext()['value_norm'] ?? '',
                'score' => $ioc->getContext()['score'] ?? [],
                'category' => $ioc->getContext()['category'] ?? '',
                'ts_observed' => $ioc->getTsObserved()->format(DATE_ATOM),
                'confidence' => $confidenceData['confidence'],
                'decay_factor' => $confidenceData['decay_factor'],
                'effective_score' => $confidenceData['effective_score'],
            ];
        }, $iocs);

        return new JsonResponse($result, Response::HTTP_OK);
    }

    #[OA\Get(
        path: '/api/v1/communication/conversation/{convId}/messages',
        summary: 'Liste paginée des messages d\'une conversation',
        tags: ['Conversations'],
        parameters: [
            new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste paginée des messages',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(type: 'object', properties: [
                        new OA\Property(property: 'message_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out']),
                        new OA\Property(property: 'subject', type: 'string', nullable: true),
                        new OA\Property(property: 'body_text', type: 'string'),
                        new OA\Property(property: 'body_html', type: 'string', nullable: true),
                        new OA\Property(property: 'ts_msg', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'lang_detect', type: 'string'),
                        new OA\Property(property: 'external_message_id', type: 'string', nullable: true),
                    ])
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Conversation non trouvée',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/{convId}/messages', name: 'list_conversation_messages', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function listMessages(string $convId, Request $request): JsonResponse
    {
        $page = max(1, (int)$request->query->get('page', 1));
        $limit = max(1, (int)$request->query->get('limit', 20));

        $result = $this->handler->getConversationMessages($convId, $page, $limit);

        if ($result['total'] === 0 && $page === 1) {
            // Check if conversation exists (only on first page with no results)
            $conv = $this->handler->getConversation($convId);

            if (!$conv || $conv->getDeletedAt() !== null) {
                return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
            }
        }

        /** @var list<\App\Domain\Communication\Message> $resultMessages */
        $resultMessages = $result['messages'];
        $items = array_map(function (\App\Domain\Communication\Message $msg) {
            return [
                'message_id' => $msg->getMsgId(),
                'direction' => $msg->getDirection()->getCode(),
                'subject' => $msg->getSubject(),
                'body_text' => $msg->getBodyText(),
                'body_html' => $msg->getBodyHtml(),
                'ts_msg' => $msg->getTsMsg()->format(\DateTimeInterface::ATOM),
                'lang_detect' => $msg->getLangDetect(),
                'external_message_id' => $msg->getExternalMessageId(),
            ];
        }, $resultMessages);

        return new JsonResponse($items, Response::HTTP_OK);
    }

    #[OA\Post(
        path: '/api/v1/communication/conversation/{convId}/classify',
        summary: 'Classify a conversation manually with a specific scam type and persona',
        tags: ['Conversations', 'Classification'],
        parameters: [
            new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['scam_type_code'],
                properties: [
                    new OA\Property(property: 'scam_type_code', type: 'string', description: 'Scam type code (e.g., PHISHING, INVOICE_FRAUD)', example: 'PHISHING'),
                    new OA\Property(property: 'persona_code', type: 'string', description: 'Persona code to assign (optional)', example: 'tech_savvy_user'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conversation classified successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'conv_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'scam_type_code', type: 'string'),
                        new OA\Property(property: 'scam_type_label', type: 'string'),
                        new OA\Property(property: 'persona_code', type: 'string', nullable: true),
                        new OA\Property(property: 'persona_label', type: 'string', nullable: true),
                        new OA\Property(property: 'classified_at', type: 'string', format: 'date-time'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Conversation or scam type not found',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            ),
            new OA\Response(
                response: 400,
                description: 'Validation error',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            ),
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/{convId}/classify', name: 'classify_conversation', methods: ['POST'])]
    #[IsGranted('conversation:write')]
    public function classify(string $convId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['scam_type_code'])) {
            return new JsonResponse(['error' => 'scam_type_code is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->classificationHandler->manualClassifyConversation(
                $convId,
                $data['scam_type_code'],
                $data['persona_code'] ?? null
            );

            return new JsonResponse([
                'conv_id' => $convId,
                'scam_type_code' => $result['scam_type_code'],
                'scam_type_label' => $result['scam_type_label'],
                'persona_code' => $result['persona_code'] ?? null,
                'persona_label' => $result['persona_label'] ?? null,
                'classified_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ], Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
            }

            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[OA\Post(
        path: '/api/v1/communication/conversation/{convId}/auto-classify',
        summary: 'Auto-classify a conversation using LLM',
        tags: ['Conversations', 'Classification'],
        parameters: [
            new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'force', type: 'boolean', description: 'Force reclassification even if already classified', default: false),
                    new OA\Property(property: 'confidence_threshold', type: 'number', description: 'Minimum confidence threshold (0.0-1.0)', default: 0.75, minimum: 0, maximum: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conversation classified successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'conv_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'scam_type_code', type: 'string'),
                        new OA\Property(property: 'scam_type_label', type: 'string'),
                        new OA\Property(property: 'persona_code', type: 'string', nullable: true),
                        new OA\Property(property: 'persona_label', type: 'string', nullable: true),
                        new OA\Property(property: 'confidence', type: 'number', description: 'Classification confidence (0.0-1.0)'),
                        new OA\Property(property: 'classified_at', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'is_new_scam_type', type: 'boolean', description: 'True if a new scam type was created'),
                        new OA\Property(property: 'is_new_persona', type: 'boolean', description: 'True if a new persona was created'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Conversation not found',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            ),
            new OA\Response(
                response: 400,
                description: 'Classification failed',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            ),
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/{convId}/auto-classify', name: 'auto_classify_conversation', methods: ['POST'])]
    #[IsGranted('conversation:write')]
    public function autoClassify(string $convId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if ($data !== null && !is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $force = ($data['force'] ?? false) === true;
        $confidenceThreshold = $data['confidence_threshold'] ?? 0.75;

        try {
            $result = $this->classificationHandler->autoClassifyConversation(
                $convId,
                $force,
                $confidenceThreshold
            );

            return new JsonResponse([
                'conv_id' => $convId,
                'scam_type_code' => $result['scam_type_code'],
                'scam_type_label' => $result['scam_type_label'],
                'persona_code' => $result['persona_code'] ?? null,
                'persona_label' => $result['persona_label'] ?? null,
                'confidence' => $result['confidence'],
                'classified_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'is_new_scam_type' => $result['is_new_scam_type'],
                'is_new_persona' => $result['is_new_persona'],
            ], Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
            }

            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}

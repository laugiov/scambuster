<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Application\Campaign\StoreRuleHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/campaign/rule', name: 'api_campaign_rule_store', methods: ['POST'])]
#[IsGranted('campaign:read')]
final readonly class StoreRuleController
{
    public function __construct(
        private StoreRuleHandler $handler,
    ) {
    }
    #[OA\Post(
        path: '/api/v1/campaign/rule',
        summary: 'Store a compiled DSL rule for a campaign',
        description: 'Creates a new campaign rule with DSL and compiled SQL. The rule is enabled by default in shadow mode.',
        tags: ['Campaign Radar'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['campaign_id', 'dsl', 'compiled_sql'],
                properties: [
                    new OA\Property(property: 'campaign_id', type: 'string', format: 'uuid', description: 'Campaign UUID'),
                    new OA\Property(property: 'dsl', type: 'string', description: 'MailGuard DSL rule source'),
                    new OA\Property(
                        property: 'compiled_sql',
                        type: 'object',
                        description: 'Compiled SQL with parameters (from /transpile)',
                        properties: [
                            new OA\Property(property: 'sql', type: 'string'),
                            new OA\Property(property: 'params', type: 'object', additionalProperties: true),
                        ]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Rule created successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'rule_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'campaign_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'status', type: 'string', enum: ['shadow']),
                        new OA\Property(property: 'enabled', type: 'boolean'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid parameters',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Campaign not found',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
        ]
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || !isset($data['campaign_id'], $data['dsl'], $data['compiled_sql'])) {
            return new JsonResponse([
                'error' => 'campaign_id, dsl, and compiled_sql required',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $campaignId = Uuid::fromString($data['campaign_id']);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid campaign_id format'], Response::HTTP_BAD_REQUEST);
        }

        // Delegate to Handler
        try {
            /** @var string $dsl */
            $dsl = $data['dsl'];
            $result = $this->handler->handle($campaignId, $dsl, $data['compiled_sql']);

            return new JsonResponse($result, Response::HTTP_CREATED);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }
}

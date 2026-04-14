<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Application\Campaign\CompileRulesHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/campaign/{campaign_id}/rules/compile', name: 'api_campaign_compile_rules', methods: ['POST'])]
#[IsGranted('campaign:hunt')]
final readonly class CompileCampaignRulesController
{
    public function __construct(
        private CompileRulesHandler $handler
    ) {
    }
    #[OA\Post(
        path: '/api/v1/campaign/{campaign_id}/rules/compile',
        summary: 'Compile MailGuard DSL rules for a campaign',
        description: 'Generates detection rules in MailGuard DSL format from the campaign YAML profile. Rules use simhash, URL patterns, DKIM, and other features to detect campaign variants.',
        tags: ['Campaign Radar'],
        parameters: [
            new OA\Parameter(
                name: 'campaign_id',
                in: 'path',
                required: true,
                description: 'UUID of the campaign to compile rules for',
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(
                        property: 'examples',
                        type: 'object',
                        description: 'Example messages to refine the rules',
                        properties: [
                            new OA\Property(
                                property: 'pos',
                                type: 'array',
                                description: 'Positive messages (campaign members)',
                                items: new OA\Items(type: 'string', format: 'uuid')
                            ),
                            new OA\Property(
                                property: 'neg',
                                type: 'array',
                                description: 'Negative messages (outside campaign)',
                                items: new OA\Items(type: 'string', format: 'uuid')
                            ),
                        ]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Rules compiled successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'rules_dsl', type: 'string', description: 'Rules in MailGuard DSL format'),
                        new OA\Property(property: 'rules_count', type: 'integer', description: 'Number of rules generated'),
                        new OA\Property(property: 'attempts', type: 'integer', description: 'Number of LLM attempts required'),
                        new OA\Property(property: 'rule_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), description: 'UUIDs of rules created in database'),
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
                description: 'Campaign not found or YAML profile missing',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
            new OA\Response(
                response: 500,
                description: 'Error compiling rules',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
        ]
    )]
    public function __invoke(string $campaign_id, Request $request): JsonResponse
    {
        // 1. Validation campaign_id
        try {
            $campaignId = Uuid::fromString($campaign_id);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid campaign_id format'], Response::HTTP_BAD_REQUEST);
        }

        // 2. Optional parameters (examples to refine rules)
        /** @var array<string, mixed> $data */
        $data = json_decode($request->getContent(), true) ?? [];
        /** @var array<string, mixed> $examplesData */
        $examplesData = $data['examples'] ?? [];
        $examples = [
            'pos' => $examplesData['pos'] ?? [],
            'neg' => $examplesData['neg'] ?? [],
        ];

        // Valider structure exemples
        if (!is_array($examples['pos']) || !is_array($examples['neg'])) {
            return new JsonResponse(
                ['error' => 'examples must have "pos" and "neg" arrays'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // 3. Appel handler
        /** @var array{pos: array<int, mixed>, neg: array<int, mixed>} $examples */
        try {
            $result = $this->handler->handle($campaignId, $examples);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Rule compilation failed: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // 4. Response
        return new JsonResponse([
            'rules_dsl' => $result['rules_dsl'],
            'rules_count' => $result['rules_count'],
            'attempts' => $result['attempts'],
            'rule_ids' => $result['rule_ids'],
        ], Response::HTTP_CREATED);
    }
}

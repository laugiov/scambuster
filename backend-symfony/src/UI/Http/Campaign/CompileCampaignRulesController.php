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
        summary: 'Compiler les règles DSL MailGuard d\'une campagne',
        description: 'Génère des règles de détection au format MailGuard DSL à partir du profil YAML de la campagne. Les règles utilisent simhash, patterns d\'URLs, DKIM, et autres features pour détecter les variantes de la campagne.',
        tags: ['Campaign Radar'],
        parameters: [
            new OA\Parameter(
                name: 'campaign_id',
                in: 'path',
                required: true,
                description: 'UUID de la campagne dont on veut compiler les règles',
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
                        description: 'Exemples de messages pour affiner les règles',
                        properties: [
                            new OA\Property(
                                property: 'pos',
                                type: 'array',
                                description: 'Messages positifs (membres de la campagne)',
                                items: new OA\Items(type: 'string', format: 'uuid')
                            ),
                            new OA\Property(
                                property: 'neg',
                                type: 'array',
                                description: 'Messages négatifs (hors campagne)',
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
                description: 'Règles compilées avec succès',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'rules_dsl', type: 'string', description: 'Règles au format MailGuard DSL'),
                        new OA\Property(property: 'rules_count', type: 'integer', description: 'Nombre de règles générées'),
                        new OA\Property(property: 'attempts', type: 'integer', description: 'Nombre de tentatives LLM nécessaires'),
                        new OA\Property(property: 'rule_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), description: 'UUIDs des règles créées en base'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Paramètres invalides',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Campagne introuvable ou profil YAML manquant',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
            new OA\Response(
                response: 500,
                description: 'Erreur lors de la compilation des règles',
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

        // 2. Paramètres optionnels (exemples pour affiner les règles)
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

        // 4. Réponse
        return new JsonResponse([
            'rules_dsl' => $result['rules_dsl'],
            'rules_count' => $result['rules_count'],
            'attempts' => $result['attempts'],
            'rule_ids' => $result['rule_ids'],
        ], Response::HTTP_CREATED);
    }
}

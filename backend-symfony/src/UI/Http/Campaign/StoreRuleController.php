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
        summary: 'Stocker une règle DSL compilée pour une campagne',
        description: 'Crée une nouvelle règle de campagne avec le DSL et le SQL compilé. La règle est activée par défaut en mode shadow.',
        tags: ['Campaign Radar'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['campaign_id', 'dsl', 'compiled_sql'],
                properties: [
                    new OA\Property(property: 'campaign_id', type: 'string', format: 'uuid', description: 'UUID de la campagne'),
                    new OA\Property(property: 'dsl', type: 'string', description: 'Règle DSL MailGuard source'),
                    new OA\Property(
                        property: 'compiled_sql',
                        type: 'object',
                        description: 'SQL compilé avec paramètres (retour de /transpile)',
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
                description: 'Règle créée avec succès',
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
                description: 'Paramètres invalides',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Campagne introuvable',
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

        // Déléguer au Handler
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

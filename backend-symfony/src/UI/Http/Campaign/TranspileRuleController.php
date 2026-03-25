<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Application\Campaign\DSLTranspiler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/campaign/transpile', name: 'api_campaign_transpile', methods: ['POST'])]
final class TranspileRuleController
{
    public function __construct(
        private readonly DSLTranspiler $transpiler
    ) {
    }

    #[OA\Post(
        path: '/api/v1/campaign/transpile',
        summary: 'Transpiler une règle DSL MailGuard en SQL PostgreSQL',
        description: 'Convertit une règle DSL MailGuard en requête SQL PostgreSQL avec paramètres préparés. Support des prédicats : simhash, containsAny, domain_age, sender_fuzzy, dkim, spf.',
        tags: ['Campaign Radar'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['dsl'],
                properties: [
                    new OA\Property(
                        property: 'dsl',
                        type: 'string',
                        description: 'Règle DSL MailGuard à transpiler',
                        example: 'RULE test { WHERE subject.simhash≈"urgent" ±15% AND dkim.pass ∈ {false, null} ACTION tag="test" }'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Transpilation réussie',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'sql', type: 'string', description: 'Requête SQL PostgreSQL générée'),
                        new OA\Property(
                            property: 'params',
                            type: 'object',
                            description: 'Paramètres pour prepared statement (clés: p0, p1, ...)',
                            additionalProperties: true
                        ),
                        new OA\Property(property: 'tests', type: 'array', items: new OA\Items(type: 'string'), description: 'Tests générés (MVP: vide)'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Erreur de parsing DSL ou prédicat non supporté',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            ),
        ]
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || !isset($data['dsl'])) {
            return new JsonResponse(['error' => 'dsl is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->transpiler->transpile($data['dsl']);

            return new JsonResponse($result);
        } catch (\RuntimeException $e) {
            return new JsonResponse([
                'error' => 'Transpilation failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}

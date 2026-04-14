<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Application\Campaign\DSLTranspiler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/campaign/transpile', name: 'api_campaign_transpile', methods: ['POST'])]
#[IsGranted('campaign:read')]
final readonly class TranspileRuleController
{
    public function __construct(
        private DSLTranspiler $transpiler
    ) {
    }
    #[OA\Post(
        path: '/api/v1/campaign/transpile',
        summary: 'Transpile a MailGuard DSL rule to PostgreSQL SQL',
        description: 'Converts a MailGuard DSL rule to a PostgreSQL SQL query with prepared parameters. Supported predicates: simhash, containsAny, domain_age, sender_fuzzy, dkim, spf.',
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
                        description: 'MailGuard DSL rule to transpile',
                        example: 'RULE test { WHERE subject.simhash≈"urgent" ±15% AND dkim.pass ∈ {false, null} ACTION tag="test" }'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Transpilation successful',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'sql', type: 'string', description: 'Generated PostgreSQL SQL query'),
                        new OA\Property(
                            property: 'params',
                            type: 'object',
                            description: 'Parameters for prepared statement (keys: p0, p1, ...)',
                            additionalProperties: true
                        ),
                        new OA\Property(property: 'tests', type: 'array', items: new OA\Items(type: 'string'), description: 'Generated tests (MVP: empty)'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'DSL parsing error or unsupported predicate',
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
            /** @var string $dsl */
            $dsl = $data['dsl'];
            $result = $this->transpiler->transpile($dsl);

            return new JsonResponse($result);
        } catch (\RuntimeException $e) {
            return new JsonResponse([
                'error' => 'Transpilation failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}

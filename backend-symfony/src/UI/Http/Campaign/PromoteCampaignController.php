<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Application\Campaign\CampaignPromoter;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/campaign/rule/{ruleId}/promote', name: 'api_campaign_promote', methods: ['POST'])]
#[IsGranted('campaign:promote')]
final readonly class PromoteCampaignController
{
    public function __construct(
        private CampaignPromoter $promoter
    ) {
    }
    #[OA\Post(
        path: '/api/v1/campaign/rule/{ruleId}/promote',
        summary: 'Promote a campaign rule',
        description: 'Promotes a campaign rule if it meets required thresholds (PPV, hit count). Also triggers an automatic STIX export.',
        security: [['Bearer' => []]],
        tags: ['Campaign'],
        parameters: [
            new OA\Parameter(
                name: 'ruleId',
                in: 'path',
                required: true,
                description: 'UUID of the rule to promote',
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Rule promoted successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Rule promoted successfully'),
                        new OA\Property(property: 'rule_id', type: 'string', format: 'uuid'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Seuils de promotion non atteints ou format invalide',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Rule not found',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
        ]
    )]
    public function __invoke(string $ruleId, Request $request): JsonResponse
    {
        try {
            $ruleUuid = Uuid::fromString($ruleId);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid rule_id format'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->promoter->promote($ruleUuid);
        } catch (\DomainException $e) {
            return new JsonResponse([
                'error' => 'Promotion failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException) {
            return new JsonResponse([
                'error' => 'Rule not found',
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'message' => 'Rule promoted successfully',
            'rule_id' => $ruleId,
        ]);
    }
}

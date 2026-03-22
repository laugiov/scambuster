<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Application\Campaign\CampaignPromoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/campaign/rule/{ruleId}/promote', name: 'api_campaign_promote', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class PromoteCampaignController
{
    public function __construct(
        private readonly CampaignPromoter $promoter
    ) {
    }

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
        } catch (\RuntimeException $e) {
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

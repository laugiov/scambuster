<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Application\Campaign\CampaignPromoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v1/campaign/candidates', name: 'api_campaign_promotion_candidates', methods: ['GET'])]
final class GetPromotionCandidatesController
{
    public function __construct(
        private readonly CampaignPromoter $promoter
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $result = $this->promoter->evaluateCandidates();

        return new JsonResponse([
            'candidates' => $result['candidates'],
            'thresholds' => $this->promoter->getThresholds(),
        ]);
    }
}

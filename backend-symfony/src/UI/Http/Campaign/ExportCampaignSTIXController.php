<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Application\Campaign\STIXExporter;
use App\Domain\CampaignRadar\Campaign;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/campaign/{campaignId}/export/stix', name: 'api_campaign_export_stix', methods: ['POST'])]
final class ExportCampaignSTIXController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly STIXExporter $exporter
    ) {
    }

    public function __invoke(string $campaignId): JsonResponse
    {
        try {
            $campaignUuid = Uuid::fromString($campaignId);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid campaign_id format'], Response::HTTP_BAD_REQUEST);
        }

        $campaign = $this->em->find(Campaign::class, $campaignUuid);

        if (!$campaign) {
            return new JsonResponse(['error' => 'Campaign not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->exporter->export($campaign);
        } catch (\RuntimeException $e) {
            return new JsonResponse([
                'error' => 'STIX export failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'message' => 'STIX export completed',
            'file_path' => $result['file_path'],
            'bundle_id' => $result['bundle_id'],
        ]);
    }
}

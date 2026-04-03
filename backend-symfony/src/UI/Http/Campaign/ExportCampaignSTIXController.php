<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Application\Audit\AuditLogger;
use App\Application\Campaign\STIXExporter;
use App\Application\Stix\StixBundleBuilder;
use App\Domain\CampaignRadar\Campaign;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/campaign/{campaignId}/export/stix', name: 'api_campaign_export_stix', methods: ['POST'])]
#[IsGranted('campaign:read')]
final class ExportCampaignSTIXController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly STIXExporter $exporter,
        private readonly StixBundleBuilder $bundleBuilder,
        private readonly ?AuditLogger $auditLogger = null,
    ) {
    }

    #[OA\Post(
        path: '/api/v1/campaign/{campaignId}/export/stix',
        summary: 'Exporter une campagne au format STIX 2.1',
        description: 'Génère un bundle STIX 2.1 pour une campagne donnée en extrayant les IoCs depuis le profil YAML. Le fichier JSON est sauvegardé sur disque.',
        security: [['Bearer' => []]],
        tags: ['Campaign'],
        parameters: [
            new OA\Parameter(
                name: 'campaignId',
                in: 'path',
                required: true,
                description: 'UUID de la campagne à exporter',
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Export STIX terminé avec succès',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'STIX export completed'),
                        new OA\Property(property: 'file_path', type: 'string', description: 'Chemin du fichier STIX généré'),
                        new OA\Property(property: 'bundle_id', type: 'string', description: 'Identifiant du bundle STIX (bundle--uuid)'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Format campaign_id invalide',
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
            new OA\Response(
                response: 500,
                description: 'Erreur lors de l\'export STIX',
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

            // If YAML-based export produced no indicators, fallback to DB IOCs
            $hasIndicators = false;

            /** @var array<int, array<string, mixed>> $bundleObjects */
            $bundleObjects = is_array($result['bundle']['objects'] ?? null) ? $result['bundle']['objects'] : [];

            foreach ($bundleObjects as $obj) {
                if (($obj['type'] ?? '') === 'indicator') {
                    $hasIndicators = true;

                    break;
                }
            }

            if (!$hasIndicators) {
                $dbBundle = $this->buildBundleFromCampaignMessages($campaign);

                if ($dbBundle !== null) {
                    $result['bundle'] = $dbBundle;
                    $result['bundle_id'] = $dbBundle['id'];
                }
            }
        } catch (\RuntimeException $e) {
            return new JsonResponse([
                'error' => 'STIX export failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->auditLogger?->log(
            \App\Domain\Audit\AuditEventType::EXPORT_STIX,
            $campaignId,
            'export_stix',
            'success',
            'campaign',
            $campaignId,
            [
                'bundle_id' => $result['bundle_id'],
                'file_path' => $result['file_path'],
            ],
        );

        return new JsonResponse([
            'message' => 'STIX export completed',
            'file_path' => $result['file_path'],
            'bundle_id' => $result['bundle_id'],
            'bundle' => $result['bundle'],
        ]);
    }

    /**
     * Fallback: build STIX bundle from campaign's matched messages IOCs in DB.
     *
     * @return array<string, mixed>|null
     */
    private function buildBundleFromCampaignMessages(Campaign $campaign): ?array
    {
        $conn = $this->em->getConnection();
        $campaignId = $campaign->getCampaignId()->toRfc4122();

        // Get IOCs from messages linked to this campaign
        $rows = $conn->executeQuery(
            'SELECT DISTINCT
                oi.indicator_id,
                oi.context_observation,
                oi.confidence_score,
                oi.ts_observed
            FROM message_campaign mc
            JOIN observed_ioc oi ON mc.msg_id = oi.msg_id
            WHERE mc.campaign_id = :campaignId',
            ['campaignId' => $campaignId]
        )->fetchAllAssociative();

        if (empty($rows)) {
            return null;
        }

        $iocs = [];

        foreach ($rows as $row) {
            $context = is_string($row['context_observation']) ? json_decode($row['context_observation'], true) : [];

            if (!is_array($context)) {
                continue;
            }

            $iocs[] = [
                'indicator_id' => $row['indicator_id'],
                'type' => is_string($context['type'] ?? null) ? $context['type'] : 'unknown',
                'value' => is_string($context['value'] ?? null) ? $context['value'] : '',
                'value_norm' => is_string($context['value_norm'] ?? null) ? $context['value_norm'] : '',
                'first_seen' => is_string($row['ts_observed']) ? $row['ts_observed'] : '',
                'confidence' => is_numeric($row['confidence_score']) ? (float) $row['confidence_score'] : null,
                'extraction_method' => is_string($context['extraction_method'] ?? null) ? $context['extraction_method'] : (is_string($context['source'] ?? null) ? $context['source'] : 'unknown'),
                'score' => is_array($context['score'] ?? null) ? $context['score'] : [],
            ];
        }

        return $this->bundleBuilder->buildBundle(
            $iocs,
            [],
            $campaign->getTlp(),
            'ScamBuster Campaign ' . substr($campaignId, 0, 8),
            'Campaign threat intelligence from ScamBuster honeypot (DB fallback)',
        );
    }
}

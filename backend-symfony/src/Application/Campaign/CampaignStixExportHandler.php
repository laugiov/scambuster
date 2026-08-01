<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use App\Application\Audit\AuditLogger;
use App\Application\Stix\StixBundleBuilder;
use App\Domain\Audit\AuditEventType;
use App\Domain\CampaignRadar\Campaign;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CampaignStixExportHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private STIXExporter $exporter,
        private StixBundleBuilder $bundleBuilder,
        private ?AuditLogger $auditLogger = null,
    ) {
    }

    /**
     * @throws \InvalidArgumentException if campaignId format is invalid
     * @throws \RuntimeException         if campaign not found or export fails
     *
     * @return array<string, mixed>
     */
    public function export(string $campaignId): array
    {
        $campaignUuid = Uuid::fromString($campaignId);

        $campaign = $this->em->find(Campaign::class, $campaignUuid);

        if ($campaign === null) {
            throw new \RuntimeException('Campaign not found');
        }

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

        $this->auditLogger?->log(
            AuditEventType::EXPORT_STIX,
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

        return [
            'message' => 'STIX export completed',
            'file_path' => $result['file_path'],
            'bundle_id' => $result['bundle_id'],
            'bundle' => $result['bundle'],
        ];
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

        if ($rows === []) {
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

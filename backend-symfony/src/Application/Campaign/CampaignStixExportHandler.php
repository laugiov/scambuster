<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use App\Application\Audit\AuditLogger;
use App\Application\Stix\StixBundleBuilder;
use App\Application\Stix\ThreatActorStixBuilder;
use App\Domain\Audit\AuditEventType;
use App\Domain\CampaignRadar\Campaign;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class CampaignStixExportHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly STIXExporter $exporter,
        private readonly StixBundleBuilder $bundleBuilder,
        private readonly ?ThreatActorStixBuilder $threatActorBuilder = null,
        private readonly ?AuditLogger $auditLogger = null,
    ) {
    }

    /**
     * @throws \InvalidArgumentException if campaignId format is invalid
     * @throws \RuntimeException         if campaign not found or export fails
     *
     * @return array<string, mixed>
     */
    public function export(string $campaignId, bool $includeThreatActor = true): array
    {
        $campaignUuid = Uuid::fromString($campaignId);

        $campaign = $this->em->find(Campaign::class, $campaignUuid);

        if (!$campaign) {
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

        // Enrich with threat-actor if requested
        if ($includeThreatActor && $this->threatActorBuilder !== null && \is_array($result['bundle']['objects'] ?? null)) {
            $result['bundle'] = $this->enrichBundleWithThreatActor($campaign, $result['bundle']);
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

    /**
     * Enrich a STIX bundle with threat-actor, attack-pattern, and relationships.
     *
     * @param array<string, mixed> $bundle
     *
     * @return array<string, mixed>
     */
    private function enrichBundleWithThreatActor(Campaign $campaign, array $bundle): array
    {
        $conn = $this->em->getConnection();
        $campaignId = $campaign->getCampaignId()->toRfc4122();

        // Load scam type for this campaign
        $scamTypeRow = $conn->fetchAssociative(
            'SELECT st.code, st.attck_technique'
            . ' FROM campaign c'
            . ' JOIN message_campaign mc ON mc.campaign_id = c.campaign_id'
            . ' JOIN message m ON mc.msg_id = m.msg_id'
            . ' JOIN conversation cv ON m.conv_id = cv.conv_id'
            . ' JOIN lkp_scam_type st ON cv.scam_type_id = st.scam_type_id'
            . ' WHERE c.campaign_id = :id'
            . ' LIMIT 1',
            ['id' => $campaignId],
        );

        $scamType = \is_string($scamTypeRow['code'] ?? null) ? $scamTypeRow['code'] : 'UNKNOWN';
        $attckTechnique = \is_string($scamTypeRow['attck_technique'] ?? null) ? $scamTypeRow['attck_technique'] : null;

        // Load actor profile (if exists)
        $actorProfileRow = $conn->fetchAssociative(
            'SELECT style_dna, infra_dna FROM actor_profile WHERE campaigns::text LIKE :pattern LIMIT 1',
            ['pattern' => '%' . $campaignId . '%'],
        );

        $actorProfile = null;

        if ($actorProfileRow) {
            $styleDna = \is_string($actorProfileRow['style_dna'] ?? null)
                ? json_decode($actorProfileRow['style_dna'], true) : null;
            $infraDna = \is_string($actorProfileRow['infra_dna'] ?? null)
                ? json_decode($actorProfileRow['infra_dna'], true) : null;

            if (\is_array($styleDna) || \is_array($infraDna)) {
                $actorProfile = [
                    'style_dna' => \is_array($styleDna) ? $styleDna : null,
                    'infra_dna' => \is_array($infraDna) ? $infraDna : null,
                ];
            }
        }

        // Compute campaign metrics
        $metricsRow = $conn->fetchAssociative(
            'SELECT'
            . ' COUNT(DISTINCT cv.conv_id) as conversation_count,'
            . ' AVG(cv.engagement_duration_sec) / 3600.0 as avg_engagement_hours,'
            . ' AVG(cv.turns_count) as avg_turns,'
            . ' COUNT(DISTINCT i.type) as unique_ioc_type_count'
            . ' FROM message_campaign mc'
            . ' JOIN message m ON mc.msg_id = m.msg_id'
            . ' JOIN conversation cv ON m.conv_id = cv.conv_id'
            . ' LEFT JOIN observed_ioc oi ON oi.msg_id = m.msg_id'
            . ' LEFT JOIN indicator i ON oi.indicator_id = i.indicator_id'
            . ' WHERE mc.campaign_id = :id',
            ['id' => $campaignId],
        );

        $metrics = [
            'conversation_count' => \is_numeric($metricsRow['conversation_count'] ?? null) ? (int) $metricsRow['conversation_count'] : 0,
            'avg_engagement_hours' => \is_numeric($metricsRow['avg_engagement_hours'] ?? null) ? (float) $metricsRow['avg_engagement_hours'] : 0.0,
            'avg_turns' => \is_numeric($metricsRow['avg_turns'] ?? null) ? (float) $metricsRow['avg_turns'] : 0.0,
            'unique_ioc_type_count' => \is_numeric($metricsRow['unique_ioc_type_count'] ?? null) ? (int) $metricsRow['unique_ioc_type_count'] : 0,
            'has_injection_attempts' => false,
        ];

        // Calculate last_seen from messages
        $lastSeen = $conn->fetchOne(
            'SELECT MAX(m.ts_msg) FROM message_campaign mc JOIN message m ON mc.msg_id = m.msg_id WHERE mc.campaign_id = :id',
            ['id' => $campaignId],
        );

        $campaignData = [
            'campaign_id' => $campaignId,
            'scam_type' => $scamType,
            'first_seen' => $campaign->getFirstSeen()->format('Y-m-d H:i:s'),
            'last_seen' => \is_string($lastSeen) ? $lastSeen : $campaign->getFirstSeen()->format('Y-m-d H:i:s'),
            'profile_yaml' => $campaign->getProfileYaml(),
            'tlp' => $campaign->getTlp(),
        ];

        // Build STIX objects
        $threatActor = $this->threatActorBuilder->buildThreatActor($campaignData, $actorProfile, $metrics);
        $attackPatterns = $this->threatActorBuilder->buildAttackPatterns($attckTechnique);

        // Collect indicator IDs from existing bundle
        $indicatorIds = [];
        $campaignStixId = '';

        /** @var array<int, array<string, mixed>> $objects */
        $objects = $bundle['objects'] ?? [];

        foreach ($objects as $obj) {
            if (($obj['type'] ?? '') === 'indicator' && \is_string($obj['id'] ?? null)) {
                $indicatorIds[] = $obj['id'];
            }

            if (($obj['type'] ?? '') === 'campaign' && \is_string($obj['id'] ?? null)) {
                $campaignStixId = $obj['id'];
            }
        }

        // If no campaign object in bundle, create a reference ID
        if ($campaignStixId === '') {
            $campaignStixId = 'campaign--' . $campaignId;
        }

        $attackPatternIds = array_map(fn (array $ap) => $ap['id'], $attackPatterns);

        $relationships = $this->threatActorBuilder->buildActorRelationships(
            $threatActor['id'],
            $campaignStixId,
            $indicatorIds,
            $attackPatternIds,
        );

        // Merge into bundle
        /** @var list<array<string, mixed>> $bundleObjects */
        $bundleObjects = \is_array($bundle['objects'] ?? null) ? $bundle['objects'] : [];

        $bundleObjects[] = $threatActor;

        foreach ($attackPatterns as $ap) {
            $bundleObjects[] = $ap;
        }

        foreach ($relationships as $rel) {
            $bundleObjects[] = $rel;
        }

        $bundle['objects'] = $bundleObjects;

        return $bundle;
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Taxii;

use App\Application\Stix\IocContextStixExtensionBuilder;
use App\Application\Stix\ThreatActorStixBuilder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * TAXII 2.1 data retrieval service.
 *
 * Serves discovery, API root, collection metadata and STIX objects
 * for two fixed collections: IOCs and Campaigns.
 */
final class TaxiiService
{
    private const COLLECTION_IOC_ID = 'a1b2c3d4-0001-4000-8000-000000000001';
    private const COLLECTION_CAMPAIGN_ID = 'a1b2c3d4-0002-4000-8000-000000000002';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?ThreatActorStixBuilder $threatActorBuilder = null,
    ) {
    }

    /**
     * TAXII Discovery endpoint data.
     *
     * @return array<string, mixed>
     */
    public function getDiscovery(): array
    {
        return [
            'title' => 'ScamBuster TAXII Server',
            'description' => 'TAXII 2.1 server for ScamBuster threat intelligence',
            'contact' => 'scambuster@localhost',
            'default' => '/api/v1/taxii2/api/',
            'api_roots' => ['/api/v1/taxii2/api/'],
        ];
    }

    /**
     * API Root information.
     *
     * @return array<string, mixed>
     */
    public function getApiRoot(): array
    {
        return [
            'title' => 'ScamBuster API Root',
            'description' => 'Primary API root for ScamBuster threat intelligence',
            'versions' => ['application/taxii+json;version=2.1'],
            'max_content_length' => 10485760,
        ];
    }

    /**
     * List available collections.
     *
     * @return array<string, mixed>
     */
    public function getCollections(): array
    {
        return [
            'collections' => [
                [
                    'id' => self::COLLECTION_IOC_ID,
                    'title' => 'ScamBuster IOCs',
                    'description' => 'Indicators of Compromise collected by ScamBuster honeypot',
                    'can_read' => true,
                    'can_write' => false,
                    'media_types' => ['application/stix+json;version=2.1'],
                ],
                [
                    'id' => self::COLLECTION_CAMPAIGN_ID,
                    'title' => 'ScamBuster Campaigns',
                    'description' => 'Promoted scam campaigns tracked by ScamBuster',
                    'can_read' => true,
                    'can_write' => false,
                    'media_types' => ['application/stix+json;version=2.1'],
                ],
            ],
        ];
    }

    /**
     * Check if a collection ID is valid.
     */
    public function isValidCollection(string $collectionId): bool
    {
        return \in_array($collectionId, [self::COLLECTION_IOC_ID, self::COLLECTION_CAMPAIGN_ID], true);
    }

    /**
     * Retrieve STIX objects for a collection.
     *
     * @return array{envelope: array<string, mixed>, firstAdded: ?string, lastAdded: ?string}
     */
    public function getCollectionObjects(
        string $collectionId,
        ?\DateTimeImmutable $addedAfter = null,
        int $limit = 100,
        ?string $type = null,
    ): array {
        $limit = min(max(1, $limit), 1000);

        if ($collectionId === self::COLLECTION_IOC_ID) {
            return $this->getIocObjects($addedAfter, $limit, $type);
        }

        return $this->getCampaignObjects($addedAfter, $limit);
    }

    /**
     * @return array{envelope: array<string, mixed>, firstAdded: ?string, lastAdded: ?string}
     */
    private function getIocObjects(?\DateTimeImmutable $addedAfter, int $limit, ?string $type): array
    {
        $conn = $this->em->getConnection();

        $qb = $conn->createQueryBuilder()
            ->select(
                'i.indicator_id',
                'i.type',
                'i.value',
                'i.value_norm',
                'i.first_seen',
                'i.last_seen',
                'i.occurrences',
                'i.enrichment',
                'i.score',
                'i.tlp',
                'i.created_at',
                'i.updated_at',
                'ic.enrichment_status AS ctx_enrichment_status',
                'ic.scam_type_code AS ctx_scam_type_code',
                'ic.scam_type_attck AS ctx_scam_type_attck',
                'ic.persona_code AS ctx_persona_code',
                'ic.extraction_method AS ctx_extraction_method',
                'ic.revelation_turn AS ctx_revelation_turn',
                'ic.revelation_turn_ratio AS ctx_revelation_turn_ratio',
                'ic.total_turns AS ctx_total_turns',
                'ic.engagement_hours AS ctx_engagement_hours',
                'ic.co_revealed_types AS ctx_co_revealed_types',
                'ic.semantic_role AS ctx_semantic_role',
                'ic.stimulus_type AS ctx_stimulus_type',
                'ic.urgency_score AS ctx_urgency_score',
                'ic.context_excerpt AS ctx_context_excerpt',
                'ic.enrichment_confidence AS ctx_enrichment_confidence',
            )
            ->from('indicator', 'i')
            ->leftJoin('i', 'ioc_context', 'ic', 'i.indicator_id = ic.indicator_id')
            ->orderBy('i.updated_at', 'ASC')
            ->setMaxResults($limit + 1);

        if ($addedAfter !== null) {
            $qb->andWhere('i.updated_at > :added_after')
                ->setParameter('added_after', $addedAfter->format('Y-m-d H:i:s'));
        }

        if ($type !== null) {
            $qb->andWhere('i.type = :type')
                ->setParameter('type', $type);
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        $more = \count($rows) > $limit;

        if ($more) {
            $rows = \array_slice($rows, 0, $limit);
        }

        $objects = [];
        $firstAdded = null;
        $lastAdded = null;

        foreach ($rows as $row) {
            $updatedAt = \is_string($row['updated_at']) ? $row['updated_at'] : '';
            $createdAt = \is_string($row['created_at']) ? $row['created_at'] : '';

            if ($firstAdded === null) {
                $firstAdded = $updatedAt;
            }

            $lastAdded = $updatedAt;

            $indicator = [
                'type' => 'indicator',
                'spec_version' => '2.1',
                'id' => 'indicator--' . (\is_string($row['indicator_id']) ? $row['indicator_id'] : ''),
                'created' => $this->formatIso8601($createdAt),
                'modified' => $this->formatIso8601($updatedAt),
                'name' => (\is_string($row['type']) ? $row['type'] : '') . ': ' . (\is_string($row['value_norm']) ? $row['value_norm'] : ''),
                'pattern' => $this->buildStixPattern(\is_string($row['type']) ? $row['type'] : '', \is_string($row['value_norm']) ? $row['value_norm'] : ''),
                'pattern_type' => 'stix',
                'valid_from' => $this->formatIso8601(\is_string($row['first_seen']) ? $row['first_seen'] : ''),
                'confidence' => $this->computeConfidence($row),
                'labels' => ['malicious-activity'],
            ];

            // Add ScamBuster context extension
            $contextRow = $this->extractContextRow($row);

            if ($contextRow !== null) {
                $contextExt = IocContextStixExtensionBuilder::build($contextRow);

                if ($contextExt !== null) {
                    $indicator['extensions'] = [
                        'x_scambuster_context' => $contextExt,
                    ];
                }
            }

            $objects[] = $indicator;
        }

        return [
            'envelope' => [
                'more' => $more,
                'objects' => $objects,
            ],
            'firstAdded' => $firstAdded !== null ? $this->formatIso8601($firstAdded) : null,
            'lastAdded' => $lastAdded !== null ? $this->formatIso8601($lastAdded) : null,
        ];
    }

    /**
     * @return array{envelope: array<string, mixed>, firstAdded: ?string, lastAdded: ?string}
     */
    private function getCampaignObjects(?\DateTimeImmutable $addedAfter, int $limit): array
    {
        $conn = $this->em->getConnection();

        $qb = $conn->createQueryBuilder()
            ->select('campaign_id', 'status', 'severity', 'tlp', 'first_seen', 'profile_yaml', 'created_at')
            ->from('campaign')
            ->where('status = :status')
            ->setParameter('status', 'promoted')
            ->orderBy('created_at', 'ASC')
            ->setMaxResults($limit + 1);

        if ($addedAfter !== null) {
            $qb->andWhere('created_at > :added_after')
                ->setParameter('added_after', $addedAfter->format('Y-m-d H:i:s'));
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        $more = \count($rows) > $limit;

        if ($more) {
            $rows = \array_slice($rows, 0, $limit);
        }

        $objects = [];
        $firstAdded = null;
        $lastAdded = null;

        foreach ($rows as $row) {
            $createdAt = \is_string($row['created_at']) ? $row['created_at'] : '';

            if ($firstAdded === null) {
                $firstAdded = $createdAt;
            }

            $lastAdded = $createdAt;

            $campaignId = \is_string($row['campaign_id']) ? $row['campaign_id'] : '';
            $campaignStixId = 'campaign--' . $campaignId;

            $objects[] = [
                'type' => 'campaign',
                'spec_version' => '2.1',
                'id' => $campaignStixId,
                'created' => $this->formatIso8601($createdAt),
                'modified' => $this->formatIso8601($createdAt),
                'name' => 'ScamBuster Campaign ' . $campaignId,
                'first_seen' => $this->formatIso8601(\is_string($row['first_seen']) ? $row['first_seen'] : ''),
                'labels' => ['scam'],
            ];

            // Add threat-actor if builder available
            if ($this->threatActorBuilder !== null && $campaignId !== '') {
                $threatActorObjects = $this->buildThreatActorForCampaign($conn, $campaignId, $campaignStixId, $row);

                foreach ($threatActorObjects as $obj) {
                    $objects[] = $obj;
                }
            }
        }

        return [
            'envelope' => [
                'more' => $more,
                'objects' => $objects,
            ],
            'firstAdded' => $firstAdded !== null ? $this->formatIso8601($firstAdded) : null,
            'lastAdded' => $lastAdded !== null ? $this->formatIso8601($lastAdded) : null,
        ];
    }

    /**
     * Build threat-actor + attack-pattern STIX objects for a campaign in the TAXII feed.
     *
     * @param \Doctrine\DBAL\Connection $conn
     * @param array<string, mixed>      $campaignRow
     *
     * @return list<array<string, mixed>>
     */
    private function buildThreatActorForCampaign($conn, string $campaignId, string $campaignStixId, array $campaignRow): array
    {
        $objects = [];

        // Get scam type for this campaign
        $scamTypeRow = $conn->fetchAssociative(
            'SELECT st.code, st.attck_technique'
            . ' FROM message_campaign mc'
            . ' JOIN message m ON mc.msg_id = m.msg_id'
            . ' JOIN conversation cv ON m.conv_id = cv.conv_id'
            . ' JOIN lkp_scam_type st ON cv.scam_type_id = st.scam_type_id'
            . ' WHERE mc.campaign_id = :id LIMIT 1',
            ['id' => $campaignId],
        );

        $scamType = \is_string($scamTypeRow['code'] ?? null) ? $scamTypeRow['code'] : 'UNKNOWN';
        $attckTechnique = \is_string($scamTypeRow['attck_technique'] ?? null) ? $scamTypeRow['attck_technique'] : null;

        // Load actor profile
        $actorProfileRow = $conn->fetchAssociative(
            'SELECT style_dna, infra_dna FROM actor_profile WHERE campaigns::text LIKE :p LIMIT 1',
            ['p' => '%' . $campaignId . '%'],
        );

        $actorProfile = null;

        if ($actorProfileRow) {
            $styleDna = \is_string($actorProfileRow['style_dna'] ?? null) ? json_decode($actorProfileRow['style_dna'], true) : null;
            $infraDna = \is_string($actorProfileRow['infra_dna'] ?? null) ? json_decode($actorProfileRow['infra_dna'], true) : null;

            if (\is_array($styleDna) || \is_array($infraDna)) {
                $actorProfile = ['style_dna' => $styleDna, 'infra_dna' => $infraDna];
            }
        }

        // Simple metrics
        $metrics = ['conversation_count' => 0, 'avg_engagement_hours' => 0, 'avg_turns' => 0, 'unique_ioc_type_count' => 0, 'has_injection_attempts' => false];

        $campaignData = [
            'campaign_id' => $campaignId,
            'scam_type' => $scamType,
            'first_seen' => \is_string($campaignRow['first_seen'] ?? null) ? $campaignRow['first_seen'] : '',
            'last_seen' => \is_string($campaignRow['created_at'] ?? null) ? $campaignRow['created_at'] : '',
            'profile_yaml' => \is_string($campaignRow['profile_yaml'] ?? null) ? $campaignRow['profile_yaml'] : null,
            'tlp' => \is_string($campaignRow['tlp'] ?? null) ? $campaignRow['tlp'] : 'AMBER',
        ];

        $threatActor = $this->threatActorBuilder->buildThreatActor($campaignData, $actorProfile, $metrics);
        $objects[] = $threatActor;

        $attackPatterns = $this->threatActorBuilder->buildAttackPatterns($attckTechnique);

        foreach ($attackPatterns as $ap) {
            $objects[] = $ap;
        }

        // Add attributed-to relationship
        $attackPatternIds = array_map(fn (array $ap) => $ap['id'], $attackPatterns);
        $relationships = $this->threatActorBuilder->buildActorRelationships(
            $threatActor['id'],
            $campaignStixId,
            [],
            $attackPatternIds,
        );

        foreach ($relationships as $rel) {
            $objects[] = $rel;
        }

        return $objects;
    }

    private function buildStixPattern(string $type, string $value): string
    {
        $escaped = str_replace("'", "\\'", $value);

        return match ($type) {
            'domain' => "[domain-name:value = '{$escaped}']",
            'url' => "[url:value = '{$escaped}']",
            'ipv4' => "[ipv4-addr:value = '{$escaped}']",
            'ipv6' => "[ipv6-addr:value = '{$escaped}']",
            'email', 'whois_email' => "[email-addr:value = '{$escaped}']",
            'sha256' => "[file:hashes.'SHA-256' = '{$escaped}']",
            'md5' => "[file:hashes.MD5 = '{$escaped}']",
            default => "[x-scambuster:value = '{$escaped}']",
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function computeConfidence(array $row): int
    {
        $occurrences = \is_numeric($row['occurrences'] ?? null) ? (int) $row['occurrences'] : 1;

        // Decode score JSON if present
        $scoreData = [];

        if (\is_string($row['score'] ?? null) && $row['score'] !== '') {
            $decoded = json_decode($row['score'], true);

            if (\is_array($decoded)) {
                $scoreData = $decoded;
            }
        } elseif (\is_array($row['score'] ?? null)) {
            $scoreData = $row['score'];
        }

        $aggScore = isset($scoreData['agg']) && is_numeric($scoreData['agg']) ? (int) $scoreData['agg'] : 0;

        // Base confidence from occurrences
        $confidence = min(50 + $occurrences * 5, 80);

        // Boost from enrichment score
        if ($aggScore > 0) {
            $confidence = min($confidence + (int) ($aggScore / 2), 100);
        }

        return max(0, min(100, $confidence));
    }

    private function formatIso8601(string $value): string
    {
        if ($value === '') {
            return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        }

        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        }
    }

    /**
     * Extract ioc_context columns from a joined row (prefixed with ctx_).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function extractContextRow(array $row): ?array
    {
        $status = \is_string($row['ctx_enrichment_status'] ?? null) ? $row['ctx_enrichment_status'] : null;

        if ($status === null) {
            return null;
        }

        return [
            'enrichment_status' => $status,
            'scam_type_code' => $row['ctx_scam_type_code'] ?? null,
            'scam_type_attck' => $row['ctx_scam_type_attck'] ?? null,
            'persona_code' => $row['ctx_persona_code'] ?? null,
            'extraction_method' => $row['ctx_extraction_method'] ?? null,
            'revelation_turn' => $row['ctx_revelation_turn'] ?? null,
            'revelation_turn_ratio' => $row['ctx_revelation_turn_ratio'] ?? null,
            'total_turns' => $row['ctx_total_turns'] ?? null,
            'engagement_hours' => $row['ctx_engagement_hours'] ?? null,
            'co_revealed_types' => $row['ctx_co_revealed_types'] ?? null,
            'semantic_role' => $row['ctx_semantic_role'] ?? null,
            'stimulus_type' => $row['ctx_stimulus_type'] ?? null,
            'urgency_score' => $row['ctx_urgency_score'] ?? null,
            'context_excerpt' => $row['ctx_context_excerpt'] ?? null,
            'enrichment_confidence' => $row['ctx_enrichment_confidence'] ?? null,
        ];
    }
}

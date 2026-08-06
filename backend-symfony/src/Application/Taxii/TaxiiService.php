<?php

declare(strict_types=1);

namespace App\Application\Taxii;

use App\Application\Communication\IocConfidenceCalculator;
use App\Application\Stix\ActorPsychInteroperableFieldsBuilder;
use App\Application\Stix\ActorPsychProfileStixExtensionBuilder;
use App\Application\Stix\ClusteredThreatActorStixBuilder;
use App\Application\Stix\CognitiveMirrorNoteBuilder;
use App\Application\Stix\IocContextStixExtensionBuilder;
use App\Application\Stix\IocInteroperableFieldsBuilder;
use App\Application\Stix\StixObjectDeduplicator;
use App\Application\Stix\StixProvenance;
use App\Application\Stix\ThreatActorStixBuilder;
use App\Application\ThreatActor\ThreatActorPsychProfileReaderInterface;
use App\Application\Ttp\TtpQueryService;
use App\Domain\ThreatActor\AnalystVerdict;
use Doctrine\ORM\EntityManagerInterface;

/**
 * TAXII 2.1 data retrieval service.
 *
 * Serves discovery, API root, collection metadata and STIX objects
 * for two fixed collections: IOCs and Campaigns.
 */
final readonly class TaxiiService
{
    private const COLLECTION_IOC_ID = 'a1b2c3d4-0001-4000-8000-000000000001';
    private const COLLECTION_CAMPAIGN_ID = 'a1b2c3d4-0002-4000-8000-000000000002';
    private const COLLECTION_THREAT_ACTORS_ID = 'a1b2c3d4-0003-4000-8000-000000000003';

    public function __construct(
        private EntityManagerInterface $em,
        private ?ThreatActorStixBuilder $threatActorBuilder = null,
        private ?CognitiveMirrorNoteBuilder $cognitiveMirrorNoteBuilder = null,
        private ?TtpQueryService $ttpQueryService = null,
        private ?ThreatActorPsychProfileReaderInterface $psychProfileReader = null,
        private ?ActorPsychProfileStixExtensionBuilder $psychProfileExtensionBuilder = null,
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
                [
                    'id' => self::COLLECTION_THREAT_ACTORS_ID,
                    'title' => 'ScamBuster Threat Actors',
                    'description' => 'Consolidated threat-actor clusters from IOC-based correlation',
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
        return \in_array($collectionId, [self::COLLECTION_IOC_ID, self::COLLECTION_CAMPAIGN_ID, self::COLLECTION_THREAT_ACTORS_ID], true);
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
        ?string $cursor = null,
    ): array {
        $limit = min(max(1, $limit), 1000);

        if ($collectionId === self::COLLECTION_IOC_ID) {
            $result = $this->getIocObjects($addedAfter, $limit, $type, $cursor);
        } elseif ($collectionId === self::COLLECTION_THREAT_ACTORS_ID) {
            $result = $this->getClusterObjects($addedAfter, $limit, $type);
        } else {
            $result = $this->getCampaignObjects($addedAfter, $limit);
        }

        // A `type` filter is a contract: the client asked for objects of that
        // type only, so provenance SDOs are added to unfiltered responses only.
        return $type === null ? $this->withResolvableReferences($result) : $result;
    }

    /**
     * Attach the cluster's psychological fingerprint to its threat-actor SDO:
     * the `x_scambuster_actor_psych` extension for consumers that understand it,
     * mirrored into `description` and `labels` for the ones that do not (the
     * HTTP per-conversation export already carried the extension; the feed did
     * not carry the profile at all, so OpenCTI never saw the Cialdini levers).
     *
     * Silent no-op when the readers are not wired or the cluster has no profile.
     *
     * @param array<string, mixed> $threatActor
     *
     * @return array<string, mixed>
     */
    private function attachPsychProfile(array $threatActor, string $clusterId): array
    {
        if (!$this->psychProfileReader instanceof ThreatActorPsychProfileReaderInterface
            || !$this->psychProfileExtensionBuilder instanceof ActorPsychProfileStixExtensionBuilder
            || $clusterId === '') {
            return $threatActor;
        }

        $profile = $this->psychProfileReader->getByClusterId($clusterId);

        if ($profile === null) {
            return $threatActor;
        }

        $extensions = \is_array($threatActor['extensions'] ?? null) ? $threatActor['extensions'] : [];
        $extensions[\App\Application\Stix\ScambusterStixExtensions::PSYCH_ID] = \App\Application\Stix\ScambusterStixExtensions::wrap(
            'x_scambuster_actor_psych',
            $this->psychProfileExtensionBuilder->build($profile),
        );
        $threatActor['extensions'] = $extensions;

        $psychDescription = ActorPsychInteroperableFieldsBuilder::description($profile);
        $existing = \is_string($threatActor['description'] ?? null) ? trim($threatActor['description']) : '';
        $threatActor['description'] = $existing === '' ? $psychDescription : $existing . ' ' . $psychDescription;

        $labels = \is_array($threatActor['labels'] ?? null)
            ? array_values(array_filter($threatActor['labels'], \is_string(...)))
            : [];
        $threatActor['labels'] = array_values(array_unique([
            ...$labels,
            ...ActorPsychInteroperableFieldsBuilder::labels($profile),
        ]));

        return $threatActor;
    }

    /**
     * Ship the identity and marking-definition SDOs the envelope's objects point
     * at. STIX requires those references to resolve; emitting them dangling left
     * consumers unable to attribute the feed or honour its TLP.
     *
     * @param array{envelope: array<string, mixed>, firstAdded: ?string, lastAdded: ?string, next?: ?string} $result
     *
     * @return array{envelope: array<string, mixed>, firstAdded: ?string, lastAdded: ?string, next?: ?string}
     */
    private function withResolvableReferences(array $result): array
    {
        $objects = $result['envelope']['objects'] ?? null;

        if (!\is_array($objects) || $objects === []) {
            return $result;
        }

        /** @var list<array<string, mixed>> $objects */
        $result['envelope']['objects'] = StixProvenance::withReferencedSdos($objects);

        return $result;
    }

    /**
     * @return array{envelope: array<string, mixed>, firstAdded: ?string, lastAdded: ?string, next: ?string}
     */
    private function getIocObjects(?\DateTimeImmutable $addedAfter, int $limit, ?string $type, ?string $cursor = null): array
    {
        $conn = $this->em->getConnection();
        $cursorPos = $this->decodeCursor($cursor);

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
                'ic.enrichment_model AS ctx_enrichment_model',
                'ic.hesitation_detected AS ctx_hesitation_detected',
                'ic.language_switch AS ctx_language_switch',
                'ic.scam_type_misp AS ctx_scam_type_misp',
                'ic.persona_label AS ctx_persona_label',
                'ic.stimulus_msg_id AS ctx_stimulus_msg_id',
                'ic.co_revealed_count AS ctx_co_revealed_count',
                'ic.reward_value AS ctx_reward_value',
                'ic.campaign_id AS ctx_campaign_id',
                'f.verdict AS analyst_verdict',
            )
            ->from('indicator', 'i')
            ->leftJoin('i', 'ioc_context', 'ic', 'i.indicator_id = ic.indicator_id')
            ->leftJoin('i', 'ioc_analyst_feedback', 'f', 'i.indicator_id = f.indicator_id')
            // RED-never-public: TLP:RED indicators are eyes-only and must never
            // appear in the (shared) TAXII feed, whatever prefix form is stored.
            ->where("UPPER(REPLACE(REPLACE(i.tlp, 'TLP:', ''), 'TLP_', '')) <> 'RED'")
            // Total order (updated_at, indicator_id) — the second key is the cursor
            // tie-breaker that makes pagination skip-free across equal timestamps.
            ->orderBy('i.updated_at', 'ASC')
            ->addOrderBy('i.indicator_id', 'ASC')
            ->setMaxResults($limit + 1);

        if ($addedAfter instanceof \DateTimeImmutable) {
            $qb->andWhere('i.updated_at > :added_after')
                ->setParameter('added_after', $addedAfter->format('Y-m-d H:i:s'));
        }

        if ($cursorPos !== null) {
            // Row-value comparison: strictly after the last (updated_at, indicator_id)
            // returned — no object sharing a boundary timestamp is skipped or repeated.
            // Cast to `timestamp` (not timestamptz) to match the column type exactly,
            // so the comparison is free of any session-timezone interpretation.
            $qb->andWhere('(i.updated_at, i.indicator_id) > (CAST(:cur_ts AS timestamp), CAST(:cur_id AS uuid))')
                ->setParameter('cur_ts', $cursorPos['ts'])
                ->setParameter('cur_id', $cursorPos['id']);
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

        // Opaque cursor for the next page = the last (updated_at, indicator_id) served.
        $next = null;

        if ($more && $rows !== []) {
            $last = $rows[\count($rows) - 1];
            $next = $this->encodeCursor(
                \is_string($last['updated_at'] ?? null) ? $last['updated_at'] : '',
                \is_string($last['indicator_id'] ?? null) ? $last['indicator_id'] : '',
            );
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

            $contextRow = $this->extractContextRow($row);
            $analystVerdict = \is_string($row['analyst_verdict'] ?? null) ? $row['analyst_verdict'] : null;

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
                // STIX 2.1 open vocabulary, distinct from `labels` below.
                'indicator_types' => ['malicious-activity'],
                'labels' => IocInteroperableFieldsBuilder::labels($contextRow, $analystVerdict),
                // Attribution and sharing policy: without these the feed's objects
                // land in a consumer with no producer and no TLP.
                'created_by_ref' => StixProvenance::IDENTITY_ID,
                'object_marking_refs' => [StixProvenance::markingRefFor(\is_string($row['tlp'] ?? null) ? $row['tlp'] : null)],
            ];

            if ($contextRow !== null) {
                // Mirror the elicitation context onto the standard properties
                // consumers persist — the custom extension below is dropped by most.
                $description = IocInteroperableFieldsBuilder::description($contextRow);

                if ($description !== null) {
                    $indicator['description'] = $description;
                }

                $externalReferences = IocInteroperableFieldsBuilder::externalReferences($contextRow);

                if ($externalReferences !== []) {
                    $indicator['external_references'] = $externalReferences;
                }

                $contextExt = IocContextStixExtensionBuilder::build($contextRow);

                if ($contextExt !== null) {
                    // STIX 2.1: key by the extension-definition id, not a bare x_... key.
                    $indicator['extensions'] = [
                        \App\Application\Stix\ScambusterStixExtensions::CONTEXT_ID => \App\Application\Stix\ScambusterStixExtensions::wrap('x_scambuster_context', $contextExt),
                    ];
                }
            }

            $objects[] = $indicator;
        }

        // Enrich with threat-actors from conversations behind these IOCs
        if ($this->threatActorBuilder instanceof \App\Application\Stix\ThreatActorStixBuilder && ($type === null || $type === 'threat-actor')) {
            $this->enrichIocsWithThreatActors($objects, $rows);
        }

        $envelope = ['more' => $more, 'objects' => $objects];

        if ($next !== null) {
            // TAXII 2.1 pagination cursor — client passes it back as ?next=.
            $envelope['next'] = $next;
        }

        return [
            'envelope' => $envelope,
            'firstAdded' => $firstAdded !== null ? $this->formatIso8601($firstAdded) : null,
            'lastAdded' => $lastAdded !== null ? $this->formatIso8601($lastAdded) : null,
            'next' => $next,
        ];
    }

    /**
     * Opaque pagination cursor over (updated_at, indicator_id). base64url of a
     * compact JSON tuple — clients treat it as opaque and pass it back verbatim.
     */
    private function encodeCursor(string $ts, string $id): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['t' => $ts, 'i' => $id])), '+/', '-_'), '=');
    }

    /**
     * @return array{ts: string, id: string}|null
     */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $json = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);

        if (!\is_array($decoded) || !\is_string($decoded['t'] ?? null) || !\is_string($decoded['i'] ?? null) || $decoded['t'] === '' || $decoded['i'] === '') {
            return null;
        }

        return ['ts' => $decoded['t'], 'id' => $decoded['i']];
    }

    /**
     * Add threat-actor objects and indicates relationships for conversations behind the IOCs.
     *
     * @param list<array<string, mixed>>       $objects STIX objects (modified by reference)
     * @param array<int, array<string, mixed>> $rows    Raw DB rows
     */
    private function enrichIocsWithThreatActors(array &$objects, array $rows): void
    {
        if (!$this->threatActorBuilder instanceof \App\Application\Stix\ThreatActorStixBuilder || $rows === []) {
            return;
        }

        $conn = $this->em->getConnection();

        // Collect indicator IDs from this batch
        $indicatorIds = [];

        foreach ($rows as $row) {
            if (\is_string($row['indicator_id'] ?? null)) {
                $indicatorIds[] = $row['indicator_id'];
            }
        }

        if ($indicatorIds === []) {
            return;
        }

        // Find conversations for these indicators with metrics
        $placeholders = implode(',', array_fill(0, \count($indicatorIds), '?'));
        $convRows = $conn->fetchAllAssociative(
            'SELECT DISTINCT c.conv_id, st.code AS scam_type, st.attck_technique,'
            . ' c.ts_first, c.ts_last, c.turns_count, c.engagement_duration_sec,'
            . ' p.persona_code,'
            . ' (SELECT COUNT(DISTINCT i2.type) FROM observed_ioc oi2'
            . '  JOIN indicator i2 ON oi2.indicator_id = i2.indicator_id'
            . '  JOIN message m2 ON oi2.msg_id = m2.msg_id'
            . '  WHERE m2.conv_id = c.conv_id) AS ioc_type_count'
            . ' FROM conversation c'
            . ' JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id'
            . ' LEFT JOIN persona p ON c.persona_id = p.persona_id'
            . ' WHERE c.conv_id IN ('
            . '   SELECT DISTINCT m.conv_id FROM observed_ioc oi'
            . '   JOIN message m ON oi.msg_id = m.msg_id'
            . "   WHERE oi.indicator_id IN ({$placeholders})"
            . ' )',
            $indicatorIds,
        );

        if ($convRows === []) {
            return;
        }

        // Build indicator→conversation mapping
        $indicatorConvMap = $conn->fetchAllAssociative(
            'SELECT DISTINCT oi.indicator_id, m.conv_id'
            . ' FROM observed_ioc oi JOIN message m ON oi.msg_id = m.msg_id'
            . " WHERE oi.indicator_id IN ({$placeholders})",
            $indicatorIds,
        );

        /** @var array<string, list<string>> $convToIndicators */
        $convToIndicators = [];

        foreach ($indicatorConvMap as $mapping) {
            $convId = \is_string($mapping['conv_id'] ?? null) ? $mapping['conv_id'] : '';
            $indId = \is_string($mapping['indicator_id'] ?? null) ? $mapping['indicator_id'] : '';

            if ($convId !== '' && $indId !== '') {
                $convToIndicators[$convId][] = 'indicator--' . $indId;
            }
        }

        // Build threat-actor + relationships per conversation
        foreach ($convRows as $convRow) {
            $convId = \is_string($convRow['conv_id'] ?? null) ? $convRow['conv_id'] : '';
            $scamType = \is_string($convRow['scam_type'] ?? null) ? $convRow['scam_type'] : '';
            $attckTechnique = \is_string($convRow['attck_technique'] ?? null) ? $convRow['attck_technique'] : null;

            $tsFirst = \is_string($convRow['ts_first'] ?? null) ? $convRow['ts_first'] : '';
            $tsLast = \is_string($convRow['ts_last'] ?? null) ? $convRow['ts_last'] : '';
            $turns = \is_numeric($convRow['turns_count'] ?? null) ? (int) $convRow['turns_count'] : 0;
            $engagementSec = \is_numeric($convRow['engagement_duration_sec'] ?? null) ? (int) $convRow['engagement_duration_sec'] : 0;
            $personaCode = \is_string($convRow['persona_code'] ?? null) ? $convRow['persona_code'] : 'generic_user';
            $iocTypeCount = \is_numeric($convRow['ioc_type_count'] ?? null) ? (int) $convRow['ioc_type_count'] : 0;

            // Fallback engagement from timestamps
            if ($engagementSec === 0 && $tsFirst !== '' && $tsLast !== '') {
                try {
                    $diff = (new \DateTimeImmutable($tsLast))->getTimestamp() - (new \DateTimeImmutable($tsFirst))->getTimestamp();
                    $engagementSec = max($diff, 0);
                } catch (\Exception) {
                    // ignore
                }
            }

            $engagementHours = $engagementSec / 3600.0;

            $campaignData = [
                'campaign_id' => $convId,
                'scam_type' => $scamType,
                'first_seen' => $tsFirst,
                'last_seen' => $tsLast,
                'tlp' => 'amber',
            ];

            $metrics = [
                'conversation_count' => 1,
                'avg_engagement_hours' => $engagementHours,
                'avg_turns' => (float) $turns,
                'unique_ioc_type_count' => $iocTypeCount,
                'has_injection_attempts' => false,
            ];

            $actorProfile = [
                'style_dna' => ['persona_used' => $personaCode, 'scam_type' => $scamType, 'engagement_turns' => $turns],
                'infra_dna' => ['ioc_type_count' => $iocTypeCount, 'engagement_hours' => round($engagementHours, 2)],
            ];

            $threatActor = $this->threatActorBuilder->buildThreatActor($campaignData, $actorProfile, $metrics);
            $objects[] = $threatActor;

            // Attach Cognitive Mirror Note SDO if one is
            // cached for (persona, scam type). Silent skip when absent.
            if ($this->cognitiveMirrorNoteBuilder instanceof CognitiveMirrorNoteBuilder) {
                $threatActorId = \is_string($threatActor['id'] ?? null) ? $threatActor['id'] : '';
                $note = $this->cognitiveMirrorNoteBuilder->build($threatActorId, $personaCode, $scamType);

                if ($note !== null) {
                    $objects[] = $note;
                }
            }

            $attackPatterns = $this->threatActorBuilder->buildAttackPatterns($attckTechnique);

            foreach ($attackPatterns as $ap) {
                $objects[] = $ap;
            }

            $convIndicatorIds = $convToIndicators[$convId] ?? [];
            $attackPatternIds = array_map(static fn (array $ap): string => \is_string($ap['id']) ? $ap['id'] : '', $attackPatterns);

            /** @var string $threatActorId */
            $threatActorId = $threatActor['id'];
            $relationships = $this->threatActorBuilder->buildActorRelationships(
                $threatActorId,
                $convIndicatorIds,
                $attackPatternIds,
            );

            foreach ($relationships as $rel) {
                $objects[] = $rel;
            }
        }
    }

    /**
     * Retrieve STIX objects from the threat-actors collection (clusters).
     *
     * Only active clusters with conversation_count >= 2 are exposed.
     *
     * @return array{envelope: array<string, mixed>, firstAdded: ?string, lastAdded: ?string}
     */
    private function getClusterObjects(?\DateTimeImmutable $addedAfter, int $limit, ?string $type): array
    {
        $conn = $this->em->getConnection();

        $qb = $conn->createQueryBuilder()
            ->select(
                'tac.cluster_id',
                'tac.stix_id',
                'tac.name',
                'tac.status',
                'tac.conversation_count',
                'tac.anchor_ioc_count',
                'tac.sophistication',
                'tac.primary_scam_types',
                'tac.goals',
                'tac.first_seen',
                'tac.last_seen',
                'tac.algorithm_version',
                'tac.updated_at',
            )
            ->from('threat_actor_cluster', 'tac')
            ->where("tac.status = 'active'")
            ->andWhere('tac.conversation_count >= 2')
            ->orderBy('tac.updated_at', 'ASC')
            ->setMaxResults($limit + 1);

        if ($addedAfter instanceof \DateTimeImmutable) {
            $qb->andWhere('tac.updated_at > :added_after')
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
        $builder = new ClusteredThreatActorStixBuilder();

        foreach ($rows as $row) {
            $updatedAt = \is_string($row['updated_at'] ?? null) ? $row['updated_at'] : '';

            if ($firstAdded === null) {
                $firstAdded = $updatedAt;
            }

            $lastAdded = $updatedAt;

            $clusterId = \is_string($row['cluster_id'] ?? null) ? $row['cluster_id'] : '';

            // Parse PostgreSQL arrays
            $scamTypes = $this->parsePostgresArray(\is_string($row['primary_scam_types'] ?? null) ? $row['primary_scam_types'] : '{}');

            // Get anchor IOC types for this cluster
            $anchorIocTypes = $conn->fetchFirstColumn(
                'SELECT DISTINCT ioc_type FROM threat_actor_cluster_ioc WHERE cluster_id = :id',
                ['id' => $clusterId]
            );

            // Get ATT&CK techniques from scam types
            $attckTechniques = $conn->fetchFirstColumn(
                'SELECT DISTINCT st.attck_technique FROM lkp_scam_type st WHERE st.code = ANY(:codes) AND st.attck_technique IS NOT NULL',
                ['codes' => '{' . implode(',', $scamTypes) . '}']
            );

            // Get indicator STIX IDs for this cluster
            $indicatorStixIds = [];

            if ($type === null || $type === 'relationship') {
                $indicatorIds = $conn->fetchFirstColumn(
                    'SELECT indicator_id FROM threat_actor_cluster_ioc WHERE cluster_id = :id',
                    ['id' => $clusterId]
                );

                $indicatorStixIds = array_map(fn (mixed $id): string => 'indicator--' . (\is_string($id) ? $id : ''), $indicatorIds);
            }

            $clusterData = [
                'cluster_id' => $clusterId,
                'stix_id' => \is_string($row['stix_id'] ?? null) ? $row['stix_id'] : '',
                'name' => \is_string($row['name'] ?? null) ? $row['name'] : '',
                'status' => \is_string($row['status'] ?? null) ? $row['status'] : 'active',
                'conversation_count' => \is_numeric($row['conversation_count'] ?? null) ? (int) $row['conversation_count'] : 0,
                'anchor_ioc_count' => \is_numeric($row['anchor_ioc_count'] ?? null) ? (int) $row['anchor_ioc_count'] : 0,
                'sophistication' => \is_string($row['sophistication'] ?? null) ? $row['sophistication'] : 'none',
                'primary_scam_types' => $scamTypes,
                'goals' => ['financial-theft'],
                'first_seen' => \is_string($row['first_seen'] ?? null) ? $row['first_seen'] : '',
                'last_seen' => \is_string($row['last_seen'] ?? null) ? $row['last_seen'] : '',
                'algorithm_version' => \is_string($row['algorithm_version'] ?? null) ? $row['algorithm_version'] : '1.0',
                'anchor_ioc_types' => array_map(fn (mixed $v): string => \is_string($v) ? $v : '', $anchorIocTypes),
                'attck_techniques' => array_map(fn (mixed $v): string => \is_string($v) ? $v : '', $attckTechniques),
                'indicator_stix_ids' => $indicatorStixIds,
                // Scammer-side TTP aggregates, so the TAXII feed carries the same
                // SB-T* attack-patterns/relationships/sightings as the HTTP export
                // (byte-parity on x_scambuster_ttp_sighting).
                'ttps' => $this->ttpQueryService?->clusterTtpStixData($clusterId) ?? [],
            ];

            $bundle = $builder->buildBundle($clusterData);

            // Filter by type if specified
            foreach ($bundle as $obj) {
                if ($type !== null && ($obj['type'] ?? '') !== $type) {
                    continue;
                }

                if (($obj['type'] ?? '') === 'threat-actor') {
                    // After the type filter: the profile costs a query per
                    // cluster, pointless when the caller filtered actors out.
                    $obj = $this->attachPsychProfile($obj, $clusterId);
                }

                $objects[] = $obj;
            }
        }

        return [
            'envelope' => [
                'more' => $more,
                // Shared SDOs (extension-definitions, MITRE attack-patterns reused
                // across clusters) would otherwise repeat once per cluster.
                'objects' => StixObjectDeduplicator::dedupeById($objects),
            ],
            'firstAdded' => $firstAdded !== null ? $this->formatIso8601($firstAdded) : null,
            'lastAdded' => $lastAdded !== null ? $this->formatIso8601($lastAdded) : null,
            'next' => null,
        ];
    }

    /**
     * Parse a PostgreSQL array literal (e.g. '{foo,bar}') into a PHP array.
     *
     * @return list<string>
     */
    private function parsePostgresArray(string $pgArray): array
    {
        $trimmed = trim($pgArray, '{}');

        if ($trimmed === '') {
            return [];
        }

        return array_map('trim', explode(',', $trimmed));
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
            // RED-never-public: a promoted TLP:RED campaign must never appear in the
            // (shared) TAXII feed, whatever prefix form is stored. Mirrors the IOC collection.
            ->andWhere("UPPER(REPLACE(REPLACE(tlp, 'TLP:', ''), 'TLP_', '')) <> 'RED'")
            ->orderBy('created_at', 'ASC')
            ->setMaxResults($limit + 1);

        if ($addedAfter instanceof \DateTimeImmutable) {
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

            $objects[] = [
                'type' => 'campaign',
                'spec_version' => '2.1',
                'id' => 'campaign--' . (\is_string($row['campaign_id']) ? $row['campaign_id'] : ''),
                'created' => $this->formatIso8601($createdAt),
                'modified' => $this->formatIso8601($createdAt),
                'name' => 'ScamBuster Campaign ' . (\is_string($row['campaign_id']) ? $row['campaign_id'] : ''),
                'first_seen' => $this->formatIso8601(\is_string($row['first_seen']) ? $row['first_seen'] : ''),
                'labels' => ['scam'],
            ];
        }

        return [
            'envelope' => [
                'more' => $more,
                'objects' => $objects,
            ],
            'firstAdded' => $firstAdded !== null ? $this->formatIso8601($firstAdded) : null,
            'lastAdded' => $lastAdded !== null ? $this->formatIso8601($lastAdded) : null,
            'next' => null,
        ];
    }

    private function buildStixPattern(string $type, string $value): string
    {
        $escaped = \App\Application\Stix\StixPatternValue::escape($value);

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

        $confidence = max(0, min(100, $confidence));

        // A human analyst verdict outranks the computed value: confirmed pins confidence
        // high, false-positive drops it near zero (same rule as the STIX/confidence path).
        $verdict = \is_string($row['analyst_verdict'] ?? null) ? AnalystVerdict::tryFrom($row['analyst_verdict']) : null;

        if ($verdict instanceof AnalystVerdict) {
            $confidence = (int) round(IocConfidenceCalculator::applyAnalystVerdict($confidence / 100, $verdict) * 100);
        }

        return $confidence;
    }

    private function formatIso8601(string $value): string
    {
        $utc = new \DateTimeZone('UTC');

        try {
            $dt = $value === '' ? new \DateTimeImmutable('now', $utc) : new \DateTimeImmutable($value);
        } catch (\Exception) {
            $dt = new \DateTimeImmutable('now', $utc);
        }

        // STIX 2.1 requires RFC3339 UTC with a trailing "Z" (never a "+00:00"
        // offset), with millisecond precision to match the STIX builders.
        return $dt->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z');
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
            'scam_type_misp' => $row['ctx_scam_type_misp'] ?? null,
            'persona_code' => $row['ctx_persona_code'] ?? null,
            'persona_label' => $row['ctx_persona_label'] ?? null,
            'extraction_method' => $row['ctx_extraction_method'] ?? null,
            'revelation_turn' => $row['ctx_revelation_turn'] ?? null,
            'revelation_turn_ratio' => $row['ctx_revelation_turn_ratio'] ?? null,
            'total_turns' => $row['ctx_total_turns'] ?? null,
            'engagement_hours' => $row['ctx_engagement_hours'] ?? null,
            'co_revealed_types' => $row['ctx_co_revealed_types'] ?? null,
            'co_revealed_count' => $row['ctx_co_revealed_count'] ?? null,
            'stimulus_msg_id' => $row['ctx_stimulus_msg_id'] ?? null,
            'reward_value' => $row['ctx_reward_value'] ?? null,
            'campaign_id' => $row['ctx_campaign_id'] ?? null,
            'semantic_role' => $row['ctx_semantic_role'] ?? null,
            'stimulus_type' => $row['ctx_stimulus_type'] ?? null,
            'urgency_score' => $row['ctx_urgency_score'] ?? null,
            'context_excerpt' => $row['ctx_context_excerpt'] ?? null,
            'enrichment_confidence' => $row['ctx_enrichment_confidence'] ?? null,
            'enrichment_model' => $row['ctx_enrichment_model'] ?? null,
            'hesitation_detected' => $row['ctx_hesitation_detected'] ?? null,
            'language_switch' => $row['ctx_language_switch'] ?? null,
        ];
    }
}

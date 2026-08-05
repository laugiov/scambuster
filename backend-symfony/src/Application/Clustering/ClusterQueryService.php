<?php

declare(strict_types=1);

namespace App\Application\Clustering;

use App\Application\ThreatActor\ClusterBehaviourReaderInterface;
use App\Application\Ttp\TtpQueryService;
use Doctrine\DBAL\Connection;

/**
 * Read-only query service for cluster data (API layer).
 *
 * All methods return arrays (no entities) for direct JSON serialization.
 */
final readonly class ClusterQueryService implements ClusterBehaviourReaderInterface
{
    public function __construct(
        private Connection $conn,
        private ?TtpQueryService $ttpQueryService = null,
    ) {
    }

    /**
     * List all active clusters with stats.
     *
     * @return list<array<string, mixed>>
     */
    public function listClusters(): array
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT tac.cluster_id, tac.stix_id, tac.name, tac.status,
                    tac.conversation_count, tac.anchor_ioc_count,
                    tac.sophistication, tac.primary_scam_types,
                    tac.first_seen, tac.last_seen
             FROM threat_actor_cluster tac
             WHERE tac.status IN ('active', 'suspect')
             ORDER BY tac.conversation_count DESC, tac.last_seen DESC"
        );

        $clusters = [];

        foreach ($rows as $row) {
            $clusterId = \is_string($row['cluster_id'] ?? null) ? $row['cluster_id'] : '';

            $anchorIocTypes = $this->conn->fetchFirstColumn(
                'SELECT DISTINCT ioc_type FROM threat_actor_cluster_ioc WHERE cluster_id = :id',
                ['id' => $clusterId]
            );

            $clusters[] = [
                'cluster_id' => $clusterId,
                'stix_id' => \is_string($row['stix_id'] ?? null) ? $row['stix_id'] : '',
                'name' => \is_string($row['name'] ?? null) ? $row['name'] : '',
                'status' => \is_string($row['status'] ?? null) ? $row['status'] : 'active',
                'conversation_count' => \is_numeric($row['conversation_count'] ?? null) ? (int) $row['conversation_count'] : 0,
                'anchor_ioc_count' => \is_numeric($row['anchor_ioc_count'] ?? null) ? (int) $row['anchor_ioc_count'] : 0,
                'anchor_ioc_types' => array_map(fn (mixed $v): string => \is_string($v) ? $v : '', $anchorIocTypes),
                'sophistication' => \is_string($row['sophistication'] ?? null) ? $row['sophistication'] : 'none',
                'primary_scam_types' => $this->parsePostgresArray(\is_string($row['primary_scam_types'] ?? null) ? $row['primary_scam_types'] : '{}'),
                'first_seen' => \is_string($row['first_seen'] ?? null) ? $row['first_seen'] : null,
                'last_seen' => \is_string($row['last_seen'] ?? null) ? $row['last_seen'] : null,
            ];
        }

        return $clusters;
    }

    /**
     * Global clustering statistics.
     *
     * Accepts optional `$scamType` filter. When set, all metrics
     * are restricted to clusters whose `primary_scam_types` array contains the
     * code, and to conversations of that scam_type.
     *
     * Accepts optional `$period` filter ('7d'/'30d'/'90d'/'all').
     * Filters CONVERSATION counts (total + clustered + singleton + noise_reduction)
     * to that window. Cluster-level metrics (total_clusters, largest_cluster_size,
     * last_clustered_at, suspect_clusters, anchor_ioc_coverage) remain UNFILTERED
     * because a cluster persists across time — restricting "active clusters" to
     * a 7-day window has no clear semantic (a cluster is a long-lived entity).
     *
     * @return array<string, mixed>
     */
    public function getStats(?string $scamType = null, ?string $period = null): array
    {
        $scamType = (\is_string($scamType) && trim($scamType) !== '') ? trim($scamType) : null;

        // Map period to a Postgres interval for conversation date filtering.
        $periodInterval = match ($period) {
            '7d' => '7 days',
            '30d' => '30 days',
            '90d' => '90 days',
            default => null,
        };
        $convPeriodFilter = null !== $periodInterval
            ? ' AND ts_last >= NOW() - INTERVAL \'' . $periodInterval . '\''
            : '';
        $convPeriodFilterC = null !== $periodInterval
            ? ' AND c.ts_last >= NOW() - INTERVAL \'' . $periodInterval . '\''
            : '';

        // Filter fragments for cluster + conversation scope.
        $params = null !== $scamType ? ['scam_type' => $scamType] : [];
        $convScamFilter = null !== $scamType
            ? ' AND scam_type_id = (SELECT scam_type_id FROM lkp_scam_type WHERE code = :scam_type)'
            : '';
        // `primary_scam_types` is a Postgres text[] of scam-type codes per cluster.
        $clusterScamFilter = null !== $scamType
            ? ' AND :scam_type = ANY(primary_scam_types)'
            : '';
        $clusterScamFilterTac = null !== $scamType
            ? ' AND :scam_type = ANY(tac.primary_scam_types)'
            : '';

        /** @var int|string|false $totalConvs */
        $totalConvs = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM conversation WHERE deleted_at IS NULL' . $convScamFilter . $convPeriodFilter,
            $params,
        );
        $totalConvs = (int) $totalConvs;

        /** @var int|string|false $clusteredConvs */
        $clusteredConvs = $this->conn->fetchOne(
            "SELECT COUNT(*) FROM threat_actor_cluster_conversation tacc
             JOIN threat_actor_cluster tac ON tac.cluster_id = tacc.cluster_id
             JOIN conversation c ON c.conv_id = tacc.conv_id
             WHERE tac.status != 'merged' AND c.deleted_at IS NULL" . $clusterScamFilterTac . $convPeriodFilterC,
            $params,
        );
        $clusteredConvs = (int) $clusteredConvs;

        /** @var int|string|false $totalClusters */
        $totalClusters = $this->conn->fetchOne(
            "SELECT COUNT(*) FROM threat_actor_cluster WHERE status != 'merged'" . $clusterScamFilter,
            $params,
        );
        $totalClusters = (int) $totalClusters;

        /** @var int|string|false $suspectClusters */
        $suspectClusters = $this->conn->fetchOne(
            "SELECT COUNT(*) FROM threat_actor_cluster WHERE status = 'suspect'" . $clusterScamFilter,
            $params,
        );
        $suspectClusters = (int) $suspectClusters;

        /** @var int|string|false $largestSize */
        $largestSize = $this->conn->fetchOne(
            "SELECT MAX(conversation_count) FROM threat_actor_cluster WHERE status != 'merged'" . $clusterScamFilter,
            $params,
        );
        $largestSize = (int) $largestSize;

        /** @var string|false $lastClusteredAt */
        $lastClusteredAt = $this->conn->fetchOne(
            "SELECT MAX(last_clustered_at) FROM threat_actor_cluster WHERE status != 'merged'" . $clusterScamFilter,
            $params,
        );

        $singletonConvs = $totalConvs - $clusteredConvs;
        $avgClusterSize = $totalClusters > 0 ? round($clusteredConvs / $totalClusters, 2) : 0;

        // Noise reduction: total_convs → total_clusters + singletons (threat-actors in TAXII)
        $actorsWithout = $totalConvs;
        $actorsWith = $totalClusters + $singletonConvs;
        $noiseReduction = $actorsWithout > 0 ? round((1 - $actorsWith / $actorsWithout) * 100, 1) : 0;

        // Anchor IOC type coverage
        $coverageRows = $this->conn->fetchAllAssociative(
            "SELECT ioc_type, COUNT(*) as cnt FROM threat_actor_cluster_ioc
             WHERE cluster_id IN (SELECT cluster_id FROM threat_actor_cluster WHERE status != 'merged'" . $clusterScamFilter . ')
             GROUP BY ioc_type ORDER BY cnt DESC',
            $params,
        );

        $anchorCoverage = [];

        foreach ($coverageRows as $cr) {
            /** @var string $iocType */
            $iocType = $cr['ioc_type'] ?? '';
            /** @var int|string $cnt */
            $cnt = $cr['cnt'] ?? 0;
            $anchorCoverage[$iocType] = (int) $cnt;
        }

        return [
            'total_conversations' => $totalConvs,
            'clustered_conversations' => $clusteredConvs,
            'singleton_conversations' => $singletonConvs,
            'total_clusters' => $totalClusters,
            'suspect_clusters' => $suspectClusters,
            'taxii_noise_reduction_pct' => $noiseReduction,
            'avg_cluster_size' => $avgClusterSize,
            'largest_cluster_size' => $largestSize,
            'anchor_ioc_coverage' => $anchorCoverage,
            'last_clustered_at' => \is_string($lastClusteredAt) ? $lastClusteredAt : null,
        ];
    }

    /**
     * Total scambaiting time the cluster's actor was kept engaged, in seconds — the sum of
     * per-conversation engagement duration across the cluster. Only genuinely engaged, live
     * conversations count (>= 2 turns, not soft-deleted), matching the global wasted-time metric;
     * `conv_id` is unique per cluster so the sum never double-counts.
     */
    public function getEngagementDurationSec(string $clusterId): int
    {
        $total = $this->conn->fetchOne(
            'SELECT COALESCE(SUM(c.engagement_duration_sec), 0)
             FROM threat_actor_cluster_conversation tacc
             JOIN conversation c ON c.conv_id = tacc.conv_id
             WHERE tacc.cluster_id = :id AND c.deleted_at IS NULL AND c.turns_count >= 2',
            ['id' => $clusterId]
        );

        return is_numeric($total) ? (int) $total : 0;
    }

    /**
     * Cluster detail with conversations and anchor IOCs.
     *
     * @return array<string, mixed>|null
     */
    public function getDetail(string $clusterId): ?array
    {
        $row = $this->conn->fetchAssociative(
            "SELECT tac.cluster_id, tac.stix_id, tac.name, tac.status,
                    tac.conversation_count, tac.anchor_ioc_count,
                    tac.sophistication, tac.primary_scam_types, tac.goals,
                    tac.first_seen, tac.last_seen, tac.algorithm_version,
                    tac.created_at, tac.updated_at
             FROM threat_actor_cluster tac
             WHERE tac.cluster_id = :id AND tac.status != 'merged'",
            ['id' => $clusterId]
        );

        if ($row === false) {
            return null;
        }

        // Get anchor IOCs with actual values from indicator table
        $anchors = $this->conn->fetchAllAssociative(
            'SELECT taci.indicator_id, taci.ioc_type, taci.value_norm_hash, taci.conv_count,
                    taci.first_observed, taci.last_observed,
                    i.value AS ioc_value, i.value_norm AS ioc_value_norm
             FROM threat_actor_cluster_ioc taci
             JOIN indicator i ON i.indicator_id = taci.indicator_id
             WHERE taci.cluster_id = :id
             ORDER BY taci.conv_count DESC',
            ['id' => $clusterId]
        );

        // Build indicator→conversations map (for frontend filtering)
        $indicatorIds = array_column($anchors, 'indicator_id');
        $indicatorConvMap = [];

        if ($indicatorIds !== []) {
            $mapRows = $this->conn->fetchAllAssociative(
                'SELECT oi.indicator_id, m.conv_id
                 FROM observed_ioc oi
                 JOIN message m ON oi.msg_id = m.msg_id
                 WHERE oi.indicator_id = ANY(:ids)
                 AND m.conv_id IN (SELECT conv_id FROM threat_actor_cluster_conversation WHERE cluster_id = :clusterId)',
                ['ids' => '{' . implode(',', $indicatorIds) . '}', 'clusterId' => $clusterId]
            );

            foreach ($mapRows as $mr) {
                /** @var string $indId */
                $indId = $mr['indicator_id'] ?? '';
                /** @var string $cid */
                $cid = $mr['conv_id'] ?? '';

                if ($indId !== '' && $cid !== '') {
                    $indicatorConvMap[$indId][] = $cid;
                }
            }
        }

        // Attach conv_ids to each anchor IOC
        foreach ($anchors as &$anchor) {
            /** @var string $indId */
            $indId = $anchor['indicator_id'] ?? '';
            $anchor['conv_ids'] = array_values(array_unique($indicatorConvMap[$indId] ?? []));
        }
        unset($anchor);

        // Get conversations
        $conversations = $this->conn->fetchAllAssociative(
            'SELECT c.conv_id, c.status, c.score_risk, c.ts_first, c.ts_last,
                    st.code AS scam_type, tacc.linked_at
             FROM threat_actor_cluster_conversation tacc
             JOIN conversation c ON c.conv_id = tacc.conv_id
             JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
             WHERE tacc.cluster_id = :id
             ORDER BY c.ts_first ASC',
            ['id' => $clusterId]
        );

        // Sample reveals: distinct context excerpts grouped by text, with occurrence count
        // and the first conversation that produced each excerpt (for navigation).
        $sampleExcerptsRows = $this->conn->fetchAllAssociative(
            "SELECT
                ic.context_excerpt AS text,
                COUNT(*) AS occurrence_count,
                (array_agg(m.conv_id ORDER BY ic.created_at ASC))[1] AS source_conv_id
             FROM ioc_context ic
             JOIN observed_ioc oi ON ic.obs_id = oi.obs_id
             JOIN message m ON oi.msg_id = m.msg_id
             JOIN threat_actor_cluster_conversation tacc ON tacc.conv_id = m.conv_id
             WHERE tacc.cluster_id = :clusterId
               AND ic.context_excerpt IS NOT NULL
               AND ic.context_excerpt != ''
               AND ic.enrichment_status = 'enriched'
             GROUP BY ic.context_excerpt
             ORDER BY MIN(ic.created_at) ASC
             LIMIT 5",
            ['clusterId' => $clusterId]
        );

        $sampleExcerpts = array_map(
            fn (array $row): array => [
                'text' => \is_string($row['text'] ?? null) ? $row['text'] : '',
                'occurrence_count' => \is_numeric($row['occurrence_count'] ?? null) ? (int) $row['occurrence_count'] : 1,
                'source_conv_id' => \is_string($row['source_conv_id'] ?? null) ? $row['source_conv_id'] : '',
            ],
            $sampleExcerptsRows
        );

        // Cluster-level behavioral profile (aggregated from ioc_context)
        $behavioralProfile = $this->computeBehavioralProfile($clusterId);

        // Per-anchor behavioral aggregation (semantic role, stimulus, urgency)
        $anchorBehaviors = $this->computeAnchorBehaviors($clusterId);

        foreach ($anchors as &$anchor) {
            /** @var string $indId */
            $indId = $anchor['indicator_id'] ?? '';
            $behavior = $anchorBehaviors[$indId] ?? null;
            $anchor['dominant_semantic_role'] = $behavior['dominant_semantic_role'] ?? null;
            $anchor['dominant_stimulus'] = $behavior['dominant_stimulus'] ?? null;
            $anchor['avg_urgency_score'] = $behavior['avg_urgency_score'] ?? null;
        }
        unset($anchor);

        return [
            'cluster_id' => \is_string($row['cluster_id'] ?? null) ? $row['cluster_id'] : '',
            'stix_id' => \is_string($row['stix_id'] ?? null) ? $row['stix_id'] : '',
            'name' => \is_string($row['name'] ?? null) ? $row['name'] : '',
            'status' => \is_string($row['status'] ?? null) ? $row['status'] : 'active',
            'conversation_count' => \is_numeric($row['conversation_count'] ?? null) ? (int) $row['conversation_count'] : 0,
            'anchor_ioc_count' => \is_numeric($row['anchor_ioc_count'] ?? null) ? (int) $row['anchor_ioc_count'] : 0,
            'sophistication' => \is_string($row['sophistication'] ?? null) ? $row['sophistication'] : 'none',
            'primary_scam_types' => $this->parsePostgresArray(\is_string($row['primary_scam_types'] ?? null) ? $row['primary_scam_types'] : '{}'),
            'first_seen' => \is_string($row['first_seen'] ?? null) ? $row['first_seen'] : null,
            'last_seen' => \is_string($row['last_seen'] ?? null) ? $row['last_seen'] : null,
            'algorithm_version' => \is_string($row['algorithm_version'] ?? null) ? $row['algorithm_version'] : '1.0',
            'anchor_iocs' => $anchors,
            'conversations' => $conversations,
            'sample_excerpts' => $sampleExcerpts,
            'behavioral_profile' => $behavioralProfile,
        ];
    }

    /**
     * Public accessor for the cluster behavioural aggregate (consumed by the
     * threat-actor psych-profile generator). Null if the cluster has no enriched
     * ioc_context rows yet.
     *
     * @return array{
     *     dominant_stimulus: string|null,
     *     dominant_stimulus_count: int,
     *     avg_urgency_score: float,
     *     dominant_revelation_turn: int|null,
     *     hesitation_count: int,
     *     language_switch_count: int,
     *     templated_excerpt_count: int,
     *     total_excerpt_variant_count: int,
     *     total_enriched_iocs: int
     * }|null
     */
    public function getBehavioralProfile(string $clusterId): ?array
    {
        return $this->computeBehavioralProfile($clusterId);
    }

    /**
     * Compute cluster-level behavioral profile from ioc_context.
     *
     * Aggregates dominant stimulus, average urgency, dominant revelation turn,
     * hesitation/language switch counts, and templated excerpt count.
     *
     * @return array{
     *     dominant_stimulus: string|null,
     *     dominant_stimulus_count: int,
     *     avg_urgency_score: float,
     *     dominant_revelation_turn: int|null,
     *     hesitation_count: int,
     *     language_switch_count: int,
     *     templated_excerpt_count: int,
     *     total_excerpt_variant_count: int,
     *     total_enriched_iocs: int
     * }|null Null if no enriched ioc_context rows for the cluster
     */
    private function computeBehavioralProfile(string $clusterId): ?array
    {
        $row = $this->conn->fetchAssociative(
            "SELECT
                MODE() WITHIN GROUP (ORDER BY ic.stimulus_type) AS dominant_stimulus,
                AVG(ic.urgency_score) AS avg_urgency_score,
                MODE() WITHIN GROUP (ORDER BY ic.revelation_turn) AS dominant_revelation_turn,
                COUNT(DISTINCT m.conv_id) FILTER (WHERE ic.hesitation_detected = TRUE) AS hesitation_count,
                COUNT(DISTINCT m.conv_id) FILTER (WHERE ic.language_switch = TRUE) AS language_switch_count,
                COUNT(*) AS total_enriched_iocs,
                COUNT(DISTINCT ic.context_excerpt) FILTER (WHERE ic.context_excerpt IS NOT NULL AND ic.context_excerpt != '') AS total_excerpt_variant_count
             FROM ioc_context ic
             JOIN observed_ioc oi ON ic.obs_id = oi.obs_id
             JOIN message m ON oi.msg_id = m.msg_id
             JOIN threat_actor_cluster_conversation tacc ON tacc.conv_id = m.conv_id
             WHERE tacc.cluster_id = :clusterId
               AND ic.enrichment_status = 'enriched'",
            ['clusterId' => $clusterId]
        );

        if ($row === false) {
            return null;
        }

        $totalEnriched = \is_numeric($row['total_enriched_iocs'] ?? null) ? (int) $row['total_enriched_iocs'] : 0;

        if ($totalEnriched === 0) {
            return null;
        }

        $dominantStimulus = \is_string($row['dominant_stimulus'] ?? null) ? $row['dominant_stimulus'] : null;

        // Count occurrences of the dominant stimulus
        $dominantStimulusCount = 0;

        if ($dominantStimulus !== null) {
            /** @var int|string|false $countRow */
            $countRow = $this->conn->fetchOne(
                "SELECT COUNT(DISTINCT m.conv_id)
                 FROM ioc_context ic
                 JOIN observed_ioc oi ON ic.obs_id = oi.obs_id
                 JOIN message m ON oi.msg_id = m.msg_id
                 JOIN threat_actor_cluster_conversation tacc ON tacc.conv_id = m.conv_id
                 WHERE tacc.cluster_id = :clusterId
                   AND ic.enrichment_status = 'enriched'
                   AND ic.stimulus_type = :stimulus",
                ['clusterId' => $clusterId, 'stimulus' => $dominantStimulus]
            );
            $dominantStimulusCount = (int) $countRow;
        }

        // Templated excerpt count: distinct excerpts repeated >= 3 times
        /** @var int|string|false $templatedRow */
        $templatedRow = $this->conn->fetchOne(
            "SELECT COUNT(*) FROM (
                SELECT ic.context_excerpt
                FROM ioc_context ic
                JOIN observed_ioc oi ON ic.obs_id = oi.obs_id
                JOIN message m ON oi.msg_id = m.msg_id
                JOIN threat_actor_cluster_conversation tacc ON tacc.conv_id = m.conv_id
                WHERE tacc.cluster_id = :clusterId
                  AND ic.enrichment_status = 'enriched'
                  AND ic.context_excerpt IS NOT NULL
                  AND ic.context_excerpt != ''
                GROUP BY ic.context_excerpt
                HAVING COUNT(*) >= 3
             ) sub",
            ['clusterId' => $clusterId]
        );

        return [
            'dominant_stimulus' => $dominantStimulus,
            'dominant_stimulus_count' => $dominantStimulusCount,
            'avg_urgency_score' => \is_numeric($row['avg_urgency_score'] ?? null) ? (float) $row['avg_urgency_score'] : 0.0,
            'dominant_revelation_turn' => \is_numeric($row['dominant_revelation_turn'] ?? null) ? (int) $row['dominant_revelation_turn'] : null,
            'hesitation_count' => \is_numeric($row['hesitation_count'] ?? null) ? (int) $row['hesitation_count'] : 0,
            'language_switch_count' => \is_numeric($row['language_switch_count'] ?? null) ? (int) $row['language_switch_count'] : 0,
            'templated_excerpt_count' => (int) $templatedRow,
            'total_excerpt_variant_count' => \is_numeric($row['total_excerpt_variant_count'] ?? null) ? (int) $row['total_excerpt_variant_count'] : 0,
            'total_enriched_iocs' => $totalEnriched,
        ];
    }

    /**
     * Compute per-anchor IOC behavioral aggregations from ioc_context.
     *
     * @return array<string, array{
     *     dominant_semantic_role: string|null,
     *     dominant_stimulus: string|null,
     *     avg_urgency_score: float
     * }>
     */
    private function computeAnchorBehaviors(string $clusterId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT
                ic.indicator_id,
                MODE() WITHIN GROUP (ORDER BY ic.semantic_role) AS dominant_semantic_role,
                MODE() WITHIN GROUP (ORDER BY ic.stimulus_type) AS dominant_stimulus,
                AVG(ic.urgency_score) AS avg_urgency_score
             FROM ioc_context ic
             JOIN threat_actor_cluster_ioc taci ON taci.indicator_id = ic.indicator_id
             WHERE taci.cluster_id = :clusterId
               AND ic.enrichment_status = 'enriched'
             GROUP BY ic.indicator_id",
            ['clusterId' => $clusterId]
        );

        $result = [];

        foreach ($rows as $row) {
            /** @var string $indicatorId */
            $indicatorId = $row['indicator_id'] ?? '';

            if ($indicatorId === '') {
                continue;
            }

            $result[$indicatorId] = [
                'dominant_semantic_role' => \is_string($row['dominant_semantic_role'] ?? null) ? $row['dominant_semantic_role'] : null,
                'dominant_stimulus' => \is_string($row['dominant_stimulus'] ?? null) ? $row['dominant_stimulus'] : null,
                'avg_urgency_score' => \is_numeric($row['avg_urgency_score'] ?? null) ? (float) $row['avg_urgency_score'] : 0.0,
            ];
        }

        return $result;
    }

    /**
     * Get cluster data enriched for STIX export (anchor IOC types, indicator IDs, ATT&CK techniques).
     *
     * @return array<string, mixed>|null
     */
    public function getStixExportData(string $clusterId): ?array
    {
        $detail = $this->getDetail($clusterId);

        if ($detail === null) {
            return null;
        }

        // Extract anchor IOC types from anchor_iocs
        $anchorIocTypes = [];

        /** @var list<array<string, mixed>> $anchors */
        $anchors = $detail['anchor_iocs'] ?? [];

        foreach ($anchors as $ioc) {
            $type = \is_string($ioc['ioc_type'] ?? null) ? $ioc['ioc_type'] : '';

            if ($type !== '' && !\in_array($type, $anchorIocTypes, true)) {
                $anchorIocTypes[] = $type;
            }
        }

        // Get indicator STIX IDs
        $indicatorIds = $this->conn->fetchFirstColumn(
            'SELECT indicator_id FROM threat_actor_cluster_ioc WHERE cluster_id = :id',
            ['id' => $clusterId]
        );

        $indicatorStixIds = array_map(
            fn (mixed $id): string => 'indicator--' . (\is_string($id) ? $id : ''),
            $indicatorIds
        );

        // Get ATT&CK techniques from scam types
        /** @var list<string> $scamTypes */
        $scamTypes = $detail['primary_scam_types'] ?? [];
        $attckTechniques = [];

        if (!empty($scamTypes)) {
            $attckTechniques = $this->conn->fetchFirstColumn(
                'SELECT DISTINCT st.attck_technique FROM lkp_scam_type st WHERE st.code = ANY(:codes) AND st.attck_technique IS NOT NULL',
                ['codes' => '{' . implode(',', $scamTypes) . '}']
            );
            $attckTechniques = array_map(fn (mixed $v): string => \is_string($v) ? $v : '', $attckTechniques);
        }

        // Get full indicator data for STIX indicator objects
        $indicatorData = $this->conn->fetchAllAssociative(
            'SELECT taci.indicator_id, i.type, i.value, i.value_norm
             FROM threat_actor_cluster_ioc taci
             JOIN indicator i ON i.indicator_id = taci.indicator_id
             WHERE taci.cluster_id = :id',
            ['id' => $clusterId]
        );

        // Compute scam type frequency distribution for goal weighting
        $scamDistribution = $this->conn->fetchAllAssociative(
            'SELECT st.code, COUNT(*) as cnt
             FROM threat_actor_cluster_conversation tacc
             JOIN conversation c ON c.conv_id = tacc.conv_id
             JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
             WHERE tacc.cluster_id = :id
             GROUP BY st.code
             ORDER BY cnt DESC',
            ['id' => $clusterId]
        );

        $totalConvs = 0;
        $scamCounts = [];

        foreach ($scamDistribution as $row) {
            /** @var string $code */
            $code = $row['code'] ?? '';
            /** @var int|string $cnt */
            $cnt = $row['cnt'] ?? 0;
            $scamCounts[$code] = (int) $cnt;
            $totalConvs += (int) $cnt;
        }

        $weightedScamTypes = [];

        foreach ($scamCounts as $code => $count) {
            $pct = $totalConvs > 0 ? round($count / $totalConvs * 100, 1) : 0;
            $weightedScamTypes[] = ['code' => $code, 'count' => $count, 'pct' => $pct];
        }

        // Only include ATT&CK techniques from significant scam types (>=10%)
        $significantScamTypes = array_map(
            fn (array $s): string => $s['code'],
            array_filter($weightedScamTypes, fn (array $s): bool => $s['pct'] >= 10.0)
        );

        if ($significantScamTypes !== []) {
            $attckTechniques = $this->conn->fetchFirstColumn(
                'SELECT DISTINCT st.attck_technique FROM lkp_scam_type st WHERE st.code = ANY(:codes) AND st.attck_technique IS NOT NULL',
                ['codes' => '{' . implode(',', $significantScamTypes) . '}']
            );
            $attckTechniques = array_map(fn (mixed $v): string => \is_string($v) ? $v : '', $attckTechniques);
        }

        $detail['anchor_ioc_types'] = $anchorIocTypes;
        $detail['indicator_stix_ids'] = $indicatorStixIds;
        $detail['indicator_data'] = $indicatorData;
        $detail['attck_techniques'] = $attckTechniques;
        $detail['weighted_scam_types'] = $weightedScamTypes;

        // Scammer-side TTP aggregates (confirmed only), so the STIX builder can
        // emit SB-T* attack-patterns/relationships/sightings from this single
        // assembly point. Empty list when TTP querying is unwired or the cluster
        // has no confirmed observations.
        $detail['ttps'] = $this->ttpQueryService?->clusterTtpStixData($clusterId) ?? [];

        return $detail;
    }

    /**
     * Find cluster for an indicator (anchor IOC lookup).
     *
     * @return array<string, mixed>|null
     */
    public function getClusterForIndicator(string $indicatorId): ?array
    {
        $row = $this->conn->fetchAssociative(
            "SELECT tac.cluster_id, tac.stix_id, tac.name, tac.status,
                    tac.conversation_count, tac.sophistication
             FROM threat_actor_cluster_ioc taci
             JOIN threat_actor_cluster tac ON tac.cluster_id = taci.cluster_id
             WHERE taci.indicator_id = :id AND tac.status != 'merged'
             LIMIT 1",
            ['id' => $indicatorId]
        );

        if ($row === false) {
            return null;
        }

        return [
            'cluster_id' => \is_string($row['cluster_id'] ?? null) ? $row['cluster_id'] : '',
            'stix_id' => \is_string($row['stix_id'] ?? null) ? $row['stix_id'] : '',
            'name' => \is_string($row['name'] ?? null) ? $row['name'] : '',
            'status' => \is_string($row['status'] ?? null) ? $row['status'] : 'active',
            'conversation_count' => \is_numeric($row['conversation_count'] ?? null) ? (int) $row['conversation_count'] : 0,
            'sophistication' => \is_string($row['sophistication'] ?? null) ? $row['sophistication'] : 'none',
        ];
    }

    /**
     * Get the active cluster ID a conversation belongs to, or null if singleton.
     *
     * Used by ConversationStixExportHandler to decide whether
     * to delegate threat-actor production to ClusteredThreatActorStixBuilder
     * (clustered) or ThreatActorStixBuilder::buildSingleton() (unclustered).
     */
    public function getClusterIdForConversation(string $convId): ?string
    {
        $result = $this->conn->fetchOne(
            'SELECT tacc.cluster_id FROM threat_actor_cluster_conversation tacc
             JOIN threat_actor_cluster tac ON tac.cluster_id = tacc.cluster_id
             WHERE tacc.conv_id = :convId AND tac.merged_into_id IS NULL
             LIMIT 1',
            ['convId' => $convId]
        );

        return \is_string($result) ? $result : null;
    }

    /**
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
}

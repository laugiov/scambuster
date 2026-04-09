<?php

declare(strict_types=1);

namespace App\Application\Clustering;

use Doctrine\DBAL\Connection;

/**
 * Read-only query service for cluster data (API layer).
 *
 * All methods return arrays (no entities) for direct JSON serialization.
 */
final class ClusterQueryService
{
    public function __construct(
        private readonly Connection $conn,
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
                'anchor_ioc_types' => array_map(fn (mixed $v) => \is_string($v) ? $v : '', $anchorIocTypes),
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
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        /** @var int|string|false $totalConvs */
        $totalConvs = $this->conn->fetchOne('SELECT COUNT(*) FROM conversation');
        $totalConvs = (int) $totalConvs;

        /** @var int|string|false $clusteredConvs */
        $clusteredConvs = $this->conn->fetchOne(
            "SELECT COUNT(*) FROM threat_actor_cluster_conversation tacc
             JOIN threat_actor_cluster tac ON tac.cluster_id = tacc.cluster_id
             WHERE tac.status != 'merged'"
        );
        $clusteredConvs = (int) $clusteredConvs;

        /** @var int|string|false $totalClusters */
        $totalClusters = $this->conn->fetchOne("SELECT COUNT(*) FROM threat_actor_cluster WHERE status != 'merged'");
        $totalClusters = (int) $totalClusters;

        /** @var int|string|false $suspectClusters */
        $suspectClusters = $this->conn->fetchOne("SELECT COUNT(*) FROM threat_actor_cluster WHERE status = 'suspect'");
        $suspectClusters = (int) $suspectClusters;

        /** @var int|string|false $largestSize */
        $largestSize = $this->conn->fetchOne("SELECT MAX(conversation_count) FROM threat_actor_cluster WHERE status != 'merged'");
        $largestSize = (int) $largestSize;

        /** @var string|false $lastClusteredAt */
        $lastClusteredAt = $this->conn->fetchOne("SELECT MAX(last_clustered_at) FROM threat_actor_cluster WHERE status != 'merged'");

        $singletonConvs = $totalConvs - $clusteredConvs;
        $avgClusterSize = $totalClusters > 0 ? round($clusteredConvs / $totalClusters, 2) : 0;

        // Noise reduction: total_convs → total_clusters + singletons (threat-actors in TAXII)
        $actorsWithout = $totalConvs;
        $actorsWith = $totalClusters + $singletonConvs;
        $noiseReduction = $actorsWithout > 0 ? round((1 - $actorsWith / $actorsWithout) * 100, 1) : 0;

        // Anchor IOC type coverage
        $coverageRows = $this->conn->fetchAllAssociative(
            "SELECT ioc_type, COUNT(*) as cnt FROM threat_actor_cluster_ioc
             WHERE cluster_id IN (SELECT cluster_id FROM threat_actor_cluster WHERE status != 'merged')
             GROUP BY ioc_type ORDER BY cnt DESC"
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

        if (!empty($indicatorIds)) {
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
        ];
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
            fn (mixed $id) => 'indicator--' . (\is_string($id) ? $id : ''),
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
            $attckTechniques = array_map(fn (mixed $v) => \is_string($v) ? $v : '', $attckTechniques);
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
            fn (array $s) => $s['code'],
            array_filter($weightedScamTypes, fn (array $s) => $s['pct'] >= 10.0)
        );

        if (!empty($significantScamTypes)) {
            $attckTechniques = $this->conn->fetchFirstColumn(
                'SELECT DISTINCT st.attck_technique FROM lkp_scam_type st WHERE st.code = ANY(:codes) AND st.attck_technique IS NOT NULL',
                ['codes' => '{' . implode(',', $significantScamTypes) . '}']
            );
            $attckTechniques = array_map(fn (mixed $v) => \is_string($v) ? $v : '', $attckTechniques);
        }

        $detail['anchor_ioc_types'] = $anchorIocTypes;
        $detail['indicator_stix_ids'] = $indicatorStixIds;
        $detail['indicator_data'] = $indicatorData;
        $detail['attck_techniques'] = $attckTechniques;
        $detail['weighted_scam_types'] = $weightedScamTypes;

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

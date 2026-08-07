<?php

declare(strict_types=1);

namespace App\Application\Clustering;

use App\Application\Communication\IocConfidenceCalculator;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Real-time IOC-based clustering service.
 *
 * Groups conversations that share HIGH-severity IOCs (IBAN, crypto wallets,
 * phone numbers) into threat-actor clusters. Called during email ingestion
 * (< 10ms overhead) and via batch commands.
 *
 * The anchor IOC types are determined by IocConfidenceCalculator::computeSeverity()
 * — never hardcoded. This ensures the clustering automatically adapts if new
 * HIGH-severity types are added to the calculator.
 *
 * @see IocConfidenceCalculator::computeSeverity()
 */
final readonly class IocClusteringService
{
    /** @var array<string> Cached list of HIGH-severity IOC types (intrinsic, not enrichment-upgraded) */
    private array $anchorTypes;

    /**
     * Well-known ETH contract addresses that appear in many transactions
     * and should NOT be used as clustering anchors (they are not scammer wallets).
     *
     * @var array<string, true>
     */
    private const EXCLUDED_ETH_CONTRACTS = [
        '0xdac17f958d2ee523a2206206994597c13d831ec7' => true, // USDT (Tether)
        '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48' => true, // USDC (Circle)
        '0xc02aaa39b223fe8d0a0e5c4f27ead9083c756cc2' => true, // WETH (Wrapped Ether)
        '0x6b175474e89094c44da98b954eedeac495271d0f' => true, // DAI (MakerDAO)
        '0x95ad61b0a150d79219dcf64e1e6cc01f0b64c4ce' => true, // SHIB
    ];

    // Structural specificity thresholds for an anchor to form an edge — mirror
    // VerifyClusterQualityCommand (previously report-only). A shorter value is
    // generic and must not merge conversations.
    private const MIN_IBAN_LENGTH = 15;
    private const MIN_PHONE_DIGITS = 10;
    private const MIN_CRYPTO_LENGTH = 25;

    public function __construct(
        private Connection $conn,
        private LoggerInterface $logger,
        // An anchor shared across more than this many DISTINCT conversations is not
        // actor-specific (a reused mule / exchange-deposit / payment-processor
        // account) and must not form a clustering edge. Tunable per deployment.
        private int $maxAnchorConversations = 25,
        // Weak anchor types (phone) are more easily shared/reused/spoofed, so a
        // shared IBAN weighs more than a shared phone: phones hit a tighter cap.
        private int $maxWeakAnchorConversations = 10,
    ) {
        // Determine anchor types from IocConfidenceCalculator (single source of truth)
        // Only types that are HIGH WITHOUT enrichment (computeSeverity with vt=0, urlscan=0)
        $this->anchorTypes = $this->resolveAnchorTypes();
    }

    /**
     * Find all conversations that share HIGH-severity IOCs with the given conversation.
     *
     * This is the core lookup query for clustering. Uses CTEs with 3 JOINs:
     * indicator → observed_ioc → message → conversation.
     *
     * Performance: < 3ms at 1000 conversations / 20K IOCs (with idx_observed_ioc_indicator_id).
     *
     * @param string $convId The conversation to find shared IOCs for
     *
     * @return array<int, array{conv_id: string, shared_iocs: string}> Each row has conv_id + comma-separated shared IOC descriptions
     *
     * Time complexity: O(A × M) where A = anchor IOCs in this conversation, M = avg messages per indicator
     */
    public function findSharedConversations(string $convId): array
    {
        if ($this->anchorTypes === []) {
            $this->logger->debug('[IocClustering] No anchor types configured, skipping lookup');

            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($this->anchorTypes), '?'));
        $canonCai = $this->anchorCanonicalExpr('i');
        $canonI2 = $this->anchorCanonicalExpr('i2');

        // Fuzzy anchor match: link conversations that share an anchor IOC that is
        // canonically equivalent (formatting-only: ETH case, IBAN/card/phone
        // separators) — not just the identical indicator row. This is deliberately
        // formatting-equivalence, NOT edit-distance: two IBANs one digit apart are
        // different accounts and must NOT merge. The join is on the same anchor
        // TYPE + canonical value, so MEDIUM types can never be pulled in.
        $sql = "
            WITH conv_anchor_iocs AS (
                SELECT DISTINCT i.type, ({$canonCai}) AS canon
                FROM indicator i
                JOIN observed_ioc oi ON i.indicator_id = oi.indicator_id
                JOIN message m ON oi.msg_id = m.msg_id
                WHERE m.conv_id = ?
                  AND i.type IN ({$placeholders})
            ),
            anchor_freq AS (
                -- Distinct conversations referencing each anchor globally. An anchor
                -- shared across too many conversations is not actor-specific (a
                -- reused mule / exchange-deposit / processor account) and is dropped
                -- so it never merges unrelated actors into one cluster.
                SELECT i.type, ({$canonCai}) AS canon, COUNT(DISTINCT m.conv_id) AS conv_count
                FROM indicator i
                JOIN observed_ioc oi ON i.indicator_id = oi.indicator_id
                JOIN message m ON oi.msg_id = m.msg_id
                WHERE i.type IN ({$placeholders})
                GROUP BY i.type, ({$canonCai})
            ),
            eligible_anchors AS (
                SELECT cai.type, cai.canon
                FROM conv_anchor_iocs cai
                JOIN anchor_freq af ON af.type = cai.type AND af.canon = cai.canon
                -- Type weighting: phones (weak, easily shared) hit a tighter
                -- frequency cap than financial anchors (IBAN/wallets weigh more).
                WHERE (
                    (cai.type = 'phone' AND af.conv_count <= ?)
                    OR (cai.type <> 'phone' AND af.conv_count <= ?)
                  )
                  -- Structural specificity (mirrors VerifyClusterQualityCommand,
                  -- previously report-only): a too-short/generic anchor never forms
                  -- an edge. Canon is stripped, so length == digits for phone.
                  AND (
                    (cai.type = 'iban' AND length(cai.canon) >= " . self::MIN_IBAN_LENGTH . ")
                    OR (cai.type = 'phone' AND length(cai.canon) >= " . self::MIN_PHONE_DIGITS . ")
                    OR (cai.type IN ('wallet_btc', 'wallet_eth', 'wallet_xmr') AND length(cai.canon) >= " . self::MIN_CRYPTO_LENGTH . ")
                    OR (cai.type IN ('bank_account', 'credit_card'))
                  )
            ),
            shared_conversations AS (
                SELECT DISTINCT m2.conv_id, ea.type, ea.canon AS value_norm
                FROM eligible_anchors ea
                JOIN indicator i2 ON i2.type = ea.type AND ({$canonI2}) = ea.canon
                JOIN observed_ioc oi2 ON oi2.indicator_id = i2.indicator_id
                JOIN message m2 ON oi2.msg_id = m2.msg_id
                WHERE m2.conv_id != ?
            )
            SELECT conv_id, string_agg(DISTINCT type || ':' || value_norm, ', ') AS shared_iocs
            FROM shared_conversations
            GROUP BY conv_id
        ";

        $params = array_merge(
            [$convId],
            $this->anchorTypes,
            $this->anchorTypes,
            [$this->maxWeakAnchorConversations, $this->maxAnchorConversations, $convId],
        );

        /** @var array<int, array{conv_id: string, shared_iocs: string}> */
        return $this->conn->fetchAllAssociative($sql, $params);
    }

    /**
     * SQL expression that canonicalises an anchor IOC's value_norm for fuzzy
     * matching — formatting-only, per type:
     *  - iban: strip non-alphanumerics + uppercase (IBANs are case-insensitive).
     *  - wallet_eth: strip non-alphanumerics + lowercase (ETH hex is case-insensitive).
     *  - phone: keep digits, then drop a leading "00" international-call prefix so
     *    +33…, 0033… and 33… collapse to one E.164-ish key (against under-merge).
     *  - bank_account / credit_card: keep digits only (drop +, spaces, dashes).
     *  - wallet_btc / wallet_xmr (and anything else): unchanged — base58 is
     *    case-sensitive, so we never fold it (folding would corrupt the address).
     *
     * @param string $alias table alias exposing .type and .value_norm
     */
    private function anchorCanonicalExpr(string $alias): string
    {
        return "CASE
            WHEN {$alias}.type = 'iban' THEN upper(regexp_replace({$alias}.value_norm, '[^A-Za-z0-9]', '', 'g'))
            WHEN {$alias}.type = 'wallet_eth' THEN lower(regexp_replace({$alias}.value_norm, '[^A-Za-z0-9]', '', 'g'))
            WHEN {$alias}.type = 'phone' THEN regexp_replace(regexp_replace({$alias}.value_norm, '[^0-9]', '', 'g'), '^00', '')
            WHEN {$alias}.type IN ('bank_account', 'credit_card') THEN regexp_replace({$alias}.value_norm, '[^0-9]', '', 'g')
            ELSE {$alias}.value_norm
        END";
    }

    /**
     * Resolve the list of IOC types that are intrinsically HIGH severity.
     *
     * These are types where computeSeverity(type, 0, 0) === 'HIGH' — meaning
     * they are HIGH regardless of enrichment scores. This excludes MEDIUM types
     * that get upgraded to HIGH only when VT/URLScan > 0.
     *
     * @return array<string>
     */
    private function resolveAnchorTypes(): array
    {
        // All known IOC types to test
        $candidates = [
            'iban', 'bic', 'bank_account', 'credit_card',
            'wallet_btc', 'wallet_eth', 'wallet_xmr',
            'phone',
            'url', 'domain', 'email', 'whois_email',
            'ipv4', 'ipv6',
            'sha256', 'sha1', 'md5',
            'filename', 'registrar',
            'subject', 'message_id',
        ];

        $highTypes = [];

        foreach ($candidates as $type) {
            // Only intrinsically HIGH (vt=0, urlscan=0)
            if (IocConfidenceCalculator::computeSeverity($type, 0, 0) === 'HIGH') {
                $highTypes[] = $type;
            }
        }

        return $highTypes;
    }

    /**
     * Get the list of anchor IOC types used for clustering.
     *
     * @return array<string>
     */
    public function getAnchorTypes(): array
    {
        return $this->anchorTypes;
    }

    /**
     * Cluster a conversation based on shared HIGH-severity anchor IOCs.
     *
     * Called during email ingestion (< 10ms overhead) and by the backfill command.
     *
     * Decision logic:
     * 1. Find anchor IOCs for this conversation
     * 2. Find other conversations sharing these anchors
     * 3. If none → singleton, return
     * 4. Find existing clusters for shared conversations
     * 5. Create/join/merge cluster as needed
     *
     * @param string $convId The conversation UUID to cluster
     *
     * Time complexity: O(A × M) where A = anchor IOCs, M = avg messages per indicator
     */
    public function clusterConversation(string $convId): void
    {
        // Step 1: Find shared conversations via anchor IOCs
        $shared = $this->findSharedConversations($convId);

        if ($shared === []) {
            // No shared anchor IOCs → singleton, nothing to do
            return;
        }

        $sharedConvIds = array_column($shared, 'conv_id');

        // Step 2: Advisory lock on anchor IOC values (prevent race conditions)
        $this->acquireAdvisoryLocks($convId);

        // Step 3: Find existing clusters for the shared conversations
        $clusterMap = $this->getClusterMapForConversations($sharedConvIds);

        // Also check if current conv is already in a cluster
        $currentClusterId = $this->getClusterForConv($convId);

        // Collect all unique cluster IDs involved
        $existingClusterIds = array_unique(array_filter(array_merge(
            array_values($clusterMap),
            $currentClusterId ? [$currentClusterId] : []
        )));

        // Step 4: Decision
        if ($existingClusterIds === []) {
            // Case A: No existing clusters → create new cluster with all involved conversations
            $allConvIds = array_merge([$convId], $sharedConvIds);
            $this->createCluster($allConvIds, $convId);
        } elseif (\count($existingClusterIds) === 1) {
            // Case B: All in one cluster → add current conv to it (if not already)
            $clusterId = $existingClusterIds[0];

            if (!$currentClusterId) {
                $this->addConversationToCluster($clusterId, $convId);
            }

            // Also add any unclustered shared convs
            foreach ($sharedConvIds as $sharedConvId) {
                if (!isset($clusterMap[$sharedConvId])) {
                    $this->addConversationToCluster($clusterId, $sharedConvId);
                }
            }
        } else {
            // Case C: Multiple clusters → merge all into the oldest one
            $survivorId = $this->mergeClusters($existingClusterIds);

            // Add current conv if not in any cluster
            if (!$currentClusterId) {
                $this->addConversationToCluster($survivorId, $convId);
            }

            // Add any unclustered shared convs
            foreach ($sharedConvIds as $sharedConvId) {
                if (!isset($clusterMap[$sharedConvId])) {
                    $this->addConversationToCluster($survivorId, $sharedConvId);
                }
            }
        }
    }

    /**
     * Create a new cluster from a set of conversations.
     *
     * @param array<string> $convIds       All conversation UUIDs to include
     * @param string        $triggerConvId The conversation that triggered the clustering (for anchor IOC resolution)
     */
    private function createCluster(array $convIds, string $triggerConvId): void
    {
        // Get anchor IOCs for the trigger conversation
        $anchorIocs = $this->getAnchorIocsForConversation($triggerConvId);

        if ($anchorIocs === []) {
            return;
        }

        // Generate deterministic STIX ID
        $generator = new \App\Domain\Clustering\Service\ClusterStixIdGenerator();
        $normalizedValues = array_map(
            fn (array $ioc): string => \App\Domain\Clustering\ValueObject\NormalizedIocValue::normalize($ioc['type'], $ioc['value']),
            $anchorIocs
        );
        $stixId = $generator->generate($normalizedValues);

        // Generate cluster name
        $shortId = strtoupper(substr(md5($stixId), 0, 4));
        $count = \count($convIds);
        $name = "ScamBuster Cluster #{$shortId} ({$count} conversations)";

        // Get cluster metadata from conversations
        $meta = $this->getClusterMetadata($convIds);

        // Insert cluster
        $clusterId = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->conn->executeStatement(
            "INSERT INTO threat_actor_cluster
             (cluster_id, stix_id, name, status, conversation_count, anchor_ioc_count,
              sophistication, primary_scam_types, goals, first_seen, last_seen,
              algorithm_version, last_clustered_at, created_at, updated_at)
             VALUES (:clusterId, :stixId, :name, 'active', :convCount, :anchorCount,
                     :sophistication, :scamTypes, :goals, :firstSeen, :lastSeen,
                     '1.1', :now, :now, :now)",
            [
                'clusterId' => $clusterId,
                'stixId' => $stixId,
                'name' => $name,
                'convCount' => $count,
                'anchorCount' => \count($anchorIocs),
                'sophistication' => self::computeSophistication(
                    $count,
                    \count($meta['scam_types']),
                    \count($anchorIocs)
                ),
                'scamTypes' => '{' . implode(',', $meta['scam_types']) . '}',
                'goals' => '{financial-theft}',
                'firstSeen' => $meta['first_seen'],
                'lastSeen' => $meta['last_seen'],
                'now' => $now,
            ]
        );

        // Link conversations
        foreach ($convIds as $cid) {
            $this->conn->executeStatement(
                'INSERT INTO threat_actor_cluster_conversation (cluster_id, conv_id, linked_at)
                 VALUES (:clusterId, :convId, :now) ON CONFLICT DO NOTHING',
                ['clusterId' => $clusterId, 'convId' => $cid, 'now' => $now]
            );
        }

        // Store anchor IOCs (hashed values for GDPR)
        // first_observed / last_observed are computed from observed_ioc.ts_observed
        // restricted to messages linked to this cluster's conversations (not the backfill time).
        foreach ($anchorIocs as $ioc) {
            $normalized = \App\Domain\Clustering\ValueObject\NormalizedIocValue::normalize($ioc['type'], $ioc['value']);
            $this->conn->executeStatement(
                'INSERT INTO threat_actor_cluster_ioc
                 (cluster_id, indicator_id, ioc_type, value_norm_hash, conv_count, first_observed, last_observed)
                 SELECT
                     :clusterId, :indicatorId, :type, :hash, :convCount,
                     COALESCE(MIN(oi.ts_observed), :now::timestamptz),
                     COALESCE(MAX(oi.ts_observed), :now::timestamptz)
                 FROM observed_ioc oi
                 JOIN message m ON m.msg_id = oi.msg_id
                 WHERE oi.indicator_id = :indicatorId
                   AND m.conv_id = ANY(:convIds)
                 ON CONFLICT DO NOTHING',
                [
                    'clusterId' => $clusterId,
                    'indicatorId' => $ioc['indicator_id'],
                    'type' => $ioc['type'],
                    'hash' => \App\Domain\Clustering\ValueObject\NormalizedIocValue::hash($normalized),
                    'convCount' => $count,
                    'convIds' => '{' . implode(',', $convIds) . '}',
                    'now' => $now,
                ]
            );
        }

        $this->logger->info('[IocClustering] Created cluster', [
            'cluster_id' => $clusterId,
            'stix_id' => $stixId,
            'conversations' => $count,
            'anchor_iocs' => \count($anchorIocs),
        ]);
    }

    /**
     * Add a conversation to an existing cluster.
     *
     * @param string $clusterId The cluster UUID
     * @param string $convId    The conversation UUID to add
     */
    private function addConversationToCluster(string $clusterId, string $convId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->conn->executeStatement(
            'INSERT INTO threat_actor_cluster_conversation (cluster_id, conv_id, linked_at)
             VALUES (:clusterId, :convId, :now) ON CONFLICT DO NOTHING',
            ['clusterId' => $clusterId, 'convId' => $convId, 'now' => $now]
        );

        // Update cluster metrics
        $this->updateClusterMetrics($clusterId);
    }

    /**
     * Merge multiple clusters into the oldest one (survivor).
     *
     * @param array<string> $clusterIds Cluster UUIDs to merge
     *
     * @return string The survivor cluster ID
     */
    public function mergeClusters(array $clusterIds): string
    {
        if (\count($clusterIds) < 2) {
            return $clusterIds[0];
        }

        // Survivor = oldest cluster (by created_at)
        $rows = $this->conn->fetchAllAssociative(
            'SELECT cluster_id, created_at FROM threat_actor_cluster WHERE cluster_id = ANY(:ids) ORDER BY created_at ASC',
            ['ids' => '{' . implode(',', $clusterIds) . '}']
        );

        /** @var string $survivorId */
        $survivorId = $rows[0]['cluster_id'];
        $absorbedIds = array_filter($clusterIds, fn ($id): bool => $id !== $survivorId);

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($absorbedIds as $absorbedId) {
            // Move conversations to survivor
            $this->conn->executeStatement(
                'UPDATE threat_actor_cluster_conversation SET cluster_id = :survivorId WHERE cluster_id = :absorbedId',
                ['survivorId' => $survivorId, 'absorbedId' => $absorbedId]
            );

            // Move anchor IOCs to survivor.
            // If the anchor already exists in the survivor, consolidate the timestamps
            // (MIN of first_observed, MAX of last_observed) and sum conv_count.
            // Otherwise, INSERT as-is.
            $this->conn->executeStatement(
                'INSERT INTO threat_actor_cluster_ioc
                     (cluster_id, indicator_id, ioc_type, value_norm_hash, conv_count, first_observed, last_observed)
                 SELECT :survivorId, indicator_id, ioc_type, value_norm_hash, conv_count, first_observed, last_observed
                 FROM threat_actor_cluster_ioc WHERE cluster_id = :absorbedId
                 ON CONFLICT (cluster_id, indicator_id) DO UPDATE SET
                     first_observed = LEAST(threat_actor_cluster_ioc.first_observed, EXCLUDED.first_observed),
                     last_observed = GREATEST(threat_actor_cluster_ioc.last_observed, EXCLUDED.last_observed),
                     conv_count = threat_actor_cluster_ioc.conv_count + EXCLUDED.conv_count',
                ['survivorId' => $survivorId, 'absorbedId' => $absorbedId]
            );

            // Remove the absorbed cluster's IOC rows to avoid orphan references.
            $this->conn->executeStatement(
                'DELETE FROM threat_actor_cluster_ioc WHERE cluster_id = :absorbedId',
                ['absorbedId' => $absorbedId]
            );

            // Mark absorbed cluster
            $this->conn->executeStatement(
                "UPDATE threat_actor_cluster SET status = 'merged', merged_into_id = :survivorId, updated_at = :now WHERE cluster_id = :absorbedId",
                ['survivorId' => $survivorId, 'absorbedId' => $absorbedId, 'now' => $now]
            );
        }

        // Update survivor metrics
        $this->updateClusterMetrics($survivorId);

        $this->logger->info('[IocClustering] Merged clusters', [
            'survivor' => $survivorId,
            'absorbed' => $absorbedIds,
        ]);

        return $survivorId;
    }

    /**
     * Update cluster aggregate metrics from its conversations.
     */
    private function updateClusterMetrics(string $clusterId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Count conversations
        /** @var int|string|false $rawConvCount */
        $rawConvCount = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM threat_actor_cluster_conversation WHERE cluster_id = :id',
            ['id' => $clusterId]
        );
        $convCount = (int) $rawConvCount;

        // Count anchor IOCs
        /** @var int|string|false $rawAnchorCount */
        $rawAnchorCount = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM threat_actor_cluster_ioc WHERE cluster_id = :id',
            ['id' => $clusterId]
        );
        $anchorCount = (int) $rawAnchorCount;

        // Get metadata from conversations
        /** @var array<string> $convIds */
        $convIds = $this->conn->fetchFirstColumn(
            'SELECT conv_id FROM threat_actor_cluster_conversation WHERE cluster_id = :id',
            ['id' => $clusterId]
        );
        $meta = $this->getClusterMetadata($convIds);

        // Update name
        $shortId = strtoupper(substr(md5($clusterId), 0, 4));
        $name = "ScamBuster Cluster #{$shortId} ({$convCount} conversations)";

        // Guard: mega-cluster
        $status = $convCount > 50 ? 'suspect' : 'active';

        $this->conn->executeStatement(
            'UPDATE threat_actor_cluster SET
                name = :name, status = :status, conversation_count = :convCount,
                anchor_ioc_count = :anchorCount, sophistication = :sophistication,
                primary_scam_types = :scamTypes, last_seen = :lastSeen,
                last_clustered_at = :now, updated_at = :now
             WHERE cluster_id = :id',
            [
                'name' => $name,
                'status' => $status,
                'convCount' => $convCount,
                'anchorCount' => $anchorCount,
                'sophistication' => self::computeSophistication(
                    $convCount,
                    \count($meta['scam_types']),
                    $anchorCount
                ),
                'scamTypes' => '{' . implode(',', $meta['scam_types']) . '}',
                'lastSeen' => $meta['last_seen'],
                'now' => $now,
                'id' => $clusterId,
            ]
        );
    }

    /**
     * Acquire PostgreSQL advisory locks for anchor IOCs to prevent race conditions.
     */
    private function acquireAdvisoryLocks(string $convId): void
    {
        $anchors = $this->getAnchorIocsForConversation($convId);

        foreach ($anchors as $anchor) {
            $lockKey = $anchor['type'] . ':' . $anchor['value'];
            $this->conn->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtext(:key))',
                ['key' => $lockKey]
            );
        }
    }

    /**
     * Get anchor IOCs (HIGH severity) for a conversation.
     *
     * @return array<int, array{indicator_id: string, type: string, value: string}>
     */
    private function getAnchorIocsForConversation(string $convId): array
    {
        if ($this->anchorTypes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($this->anchorTypes), '?'));

        /** @var array<int, array{indicator_id: string, type: string, value: string}> */
        $results = $this->conn->fetchAllAssociative(
            "SELECT DISTINCT i.indicator_id, i.type, i.value
             FROM indicator i
             JOIN observed_ioc oi ON i.indicator_id = oi.indicator_id
             JOIN message m ON oi.msg_id = m.msg_id
             WHERE m.conv_id = ? AND i.type IN ({$placeholders})",
            array_merge([$convId], $this->anchorTypes)
        );

        return array_values(array_filter($results, fn (array $ioc): bool => !$this->isExcludedAnchor($ioc['type'], $ioc['value'])));
    }

    /**
     * Get cluster IDs for a list of conversations.
     *
     * @param array<string> $convIds
     *
     * @return array<string, string> Map of conv_id → cluster_id
     */
    private function getClusterMapForConversations(array $convIds): array
    {
        if ($convIds === []) {
            return [];
        }

        $rows = $this->conn->fetchAllAssociative(
            'SELECT conv_id, cluster_id FROM threat_actor_cluster_conversation
             WHERE conv_id = ANY(:ids)',
            ['ids' => '{' . implode(',', $convIds) . '}']
        );

        $map = [];

        foreach ($rows as $row) {
            /** @var string $cid */
            $cid = $row['conv_id'];
            /** @var string $clId */
            $clId = $row['cluster_id'];
            $map[$cid] = $clId;
        }

        return $map;
    }

    /**
     * Get cluster ID for a single conversation, or null if not clustered.
     */
    private function getClusterForConv(string $convId): ?string
    {
        $result = $this->conn->fetchOne(
            'SELECT tacc.cluster_id FROM threat_actor_cluster_conversation tacc
             JOIN threat_actor_cluster tac ON tac.cluster_id = tacc.cluster_id
             WHERE tacc.conv_id = :convId AND tac.merged_into_id IS NULL',
            ['convId' => $convId]
        );

        return \is_string($result) || \is_int($result) ? (string) $result : null;
    }

    /**
     * Get aggregated metadata for a set of conversations (for cluster fields).
     *
     * @param array<string> $convIds
     *
     * @return array{sophistication: string, scam_types: array<string>, first_seen: string, last_seen: string}
     */
    private function getClusterMetadata(array $convIds): array
    {
        if ($convIds === []) {
            return ['sophistication' => 'none', 'scam_types' => [], 'first_seen' => '', 'last_seen' => ''];
        }

        $rows = $this->conn->fetchAllAssociative(
            'SELECT c.ts_first, c.ts_last, st.code AS scam_type_code
             FROM conversation c
             JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
             WHERE c.conv_id = ANY(:ids)',
            ['ids' => '{' . implode(',', $convIds) . '}']
        );

        $scamTypes = [];
        $firstSeen = null;
        $lastSeen = null;

        foreach ($rows as $row) {
            /** @var string $scamTypeCode */
            $scamTypeCode = $row['scam_type_code'];
            $scamTypes[] = $scamTypeCode;
            /** @var string $tsFirst */
            $tsFirst = $row['ts_first'];

            if ($firstSeen === null || $tsFirst < $firstSeen) {
                $firstSeen = $tsFirst;
            }
            /** @var string $tsLast */
            $tsLast = $row['ts_last'];

            if ($lastSeen === null || $tsLast > $lastSeen) {
                $lastSeen = $tsLast;
            }
        }

        return [
            // Sophistication is now computed by computeSophistication() at the
            // call site, where anchor_ioc_count is also known. Kept as
            // 'minimal' here as a defensive default for callers that read the
            // metadata array directly without going through the insert/update
            // path. The value WILL be overwritten in computeSophistication().
            'sophistication' => 'minimal',
            'scam_types' => array_unique($scamTypes),
            'first_seen' => $firstSeen ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'last_seen' => $lastSeen ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Compute the cluster sophistication tier from cluster-level facts known
     * at insert / update time. Pure deterministic function — testable in
     * isolation without a DB. Output is one of: 'none' | 'minimal' |
     * 'intermediate' | 'advanced' (matches the existing enum in
     * threat_actor_cluster.sophistication).
     *
     * Scoring rationale: each axis below adds 1 point. The tiers reflect
     * what a SOC analyst reading the cluster card cares about — how many
     * victims, how many distinct scam types, how broad is the
     * infrastructure surface. Template signal is captured separately by
     * behavioural_profile.templated_excerpt_count and is not folded in here
     * to keep the function callable at insert time, before any ioc_context
     * row has been enriched.
     *
     *   conv_count >= 4          → +1
     *   conv_count >= 10         → +1
     *   conv_count >= 20         → +1
     *   distinct_scam_types >= 2 → +1
     *   anchor_ioc_count >= 2    → +1
     *   anchor_ioc_count >= 4    → +1
     *
     *   score >= 4 → advanced
     *   score >= 2 → intermediate
     *   score >= 1 → minimal
     *
     * Hard floor: a cluster with < 2 conversations OR no anchor IOC is
     * 'none' regardless of the other axes (defensive — the clustering
     * service should never insert such a row, but we guard anyway).
     */
    public static function computeSophistication(
        int $convCount,
        int $distinctScamTypeCount,
        int $anchorIocCount
    ): string {
        if ($convCount < 2 || $anchorIocCount < 1) {
            return 'none';
        }

        $score = 0;

        if ($convCount >= 4) {
            $score++;
        }

        if ($convCount >= 10) {
            $score++;
        }

        if ($convCount >= 20) {
            $score++;
        }

        if ($distinctScamTypeCount >= 2) {
            $score++;
        }

        if ($anchorIocCount >= 2) {
            $score++;
        }

        if ($anchorIocCount >= 4) {
            $score++;
        }

        if ($score >= 4) {
            return 'advanced';
        }

        if ($score >= 2) {
            return 'intermediate';
        }

        return 'minimal';
    }

    /**
     * Check if an anchor IOC should be excluded from clustering.
     *
     * Excludes:
     * - Well-known ETH contract addresses (USDT, USDC, etc.)
     * - Fictional North American 555 phone numbers
     */
    private function isExcludedAnchor(string $type, string $value): bool
    {
        // Well-known ETH contracts
        if ($type === 'wallet_eth' && isset(self::EXCLUDED_ETH_CONTRACTS[strtolower($value)])) {
            return true;
        }

        // Fictional 555 phone numbers (North American reserved range)
        // Covers: +1-NPA-555-XXXX, (555) XXX-XXXX, 555XXXXXXX, +1555XXXXXXX
        if ($type === 'phone') {
            $digits = preg_replace('/[^0-9]/', '', $value);

            if (\is_string($digits) && (
                preg_match('/^1?\d{3}555\d{4}$/', $digits)  // +1-NPA-555-XXXX
                || preg_match('/^1?555\d{7}$/', $digits)     // +1-555-XXX-XXXX or 555-XXX-XXXX
            )) {
                return true;
            }
        }

        return false;
    }
}

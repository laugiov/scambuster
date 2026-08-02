<?php

declare(strict_types=1);

namespace App\Application\Ttp;

use App\Domain\Communication\Policy\IocActionablePolicy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Read-only query service for TTP observation data (API layer).
 *
 * All methods return arrays (no entities) for direct JSON serialization.
 * Evidence verbatims are intentionally never selected: responses carry the
 * stored character offsets only, and consumers reconstruct quotes from the
 * message bodies they already have. Soft-deleted messages are filtered out of
 * every query; the cluster, taxonomy and co-occurrence aggregates also exclude
 * soft-deleted conversations, while the conversation-scoped reads rely on the
 * controller's prior existence check to reject a soft-deleted conversation.
 */
final readonly class TtpQueryService
{
    private const TOP_SEQUENCES_LIMIT = 5;

    /**
     * Minimum distinct conversations a bigram must appear in to be reported
     * by {@see sequences()} (sequence-mining "support" = number of
     * conversations containing the pattern, never raw occurrences): pairs
     * below the threshold are dropped server-side and the constant is echoed
     * in the response so the UI can state the hiding rule honestly.
     */
    public const MIN_SEQUENCE_SUPPORT = 2;

    /** Upper bound on groups returned by {@see sequences()} (widest conversation volume first). */
    private const SEQUENCE_GROUPS_LIMIT = 20;

    /** Upper bound on clusters returned by the shared-playbook matrix (widest playbook first). */
    private const CLUSTER_MATRIX_LIMIT = 50;

    /** Upper bound on personas returned by the persona x TTP matrix (widest conversation volume first). */
    private const PERSONA_MATRIX_LIMIT = 30;

    /** Upper bound on rows returned by either co-occurrence pivot. */
    private const COOCCURRENCE_LIMIT_MAX = 500;

    /** Upper bound on review-queue rows returned in one response (the client paginates in-memory). */
    private const REVIEW_QUEUE_LIMIT = 500;

    /** Number of weekly buckets covered by the phase trend (current week included). */
    private const TREND_WEEKS = 8;

    /** Upper bound on clusters returned by the per-TTP cluster pivot. */
    private const TTP_CLUSTERS_LIMIT = 50;

    /** Default page size of the per-TTP conversation pivot. */
    public const CONVERSATIONS_PAGE_DEFAULT = 20;

    /** Maximum page size of the per-TTP conversation pivot. */
    public const CONVERSATIONS_PAGE_MAX = 100;

    /**
     * Canonical scam-phase order (the scambuster-scam-phases kill chain). Drives
     * the matrix column ordering; a phase outside this list sorts after all known
     * ones so a future taxonomy phase can never be silently dropped.
     */
    private const PHASE_ORDER = ['hook', 'trust-building', 'payment-request', 'escalation', 'channel-switch', 'exit'];

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Whether a conversation exists and is not soft-deleted.
     */
    public function conversationExists(string $convId): bool
    {
        $found = $this->connection->fetchOne(
            'SELECT 1 FROM conversation WHERE conv_id = :convId AND deleted_at IS NULL',
            ['convId' => $convId]
        );

        return $found !== false;
    }

    /**
     * Whether a TTP code exists in the closed taxonomy.
     */
    public function ttpExists(string $ttpCode): bool
    {
        $found = $this->connection->fetchOne(
            'SELECT 1 FROM lkp_ttp WHERE code = :code',
            ['code' => $ttpCode]
        );

        return $found !== false;
    }

    /**
     * Whether an IOC indicator exists.
     */
    public function indicatorExists(string $indicatorId): bool
    {
        $found = $this->connection->fetchOne(
            'SELECT 1 FROM indicator WHERE indicator_id = :indicatorId',
            ['indicatorId' => $indicatorId]
        );

        return $found !== false;
    }

    /**
     * Ordered TTP observations for one conversation (both confirmed and
     * review statuses — the review flag is surfaced so analysts can triage).
     *
     * @return array{conv_id: string, observations: list<array<string, mixed>>}
     */
    public function conversationTtps(string $convId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT o.msg_id, m.ts_msg, t.code AS ttp_code, t.label AS ttp_label, t.phase,
                    o.confidence, o.status, o.evidence_start, o.evidence_end
             FROM ttp_observation o
             JOIN message m ON m.msg_id = o.msg_id AND m.deleted_at IS NULL
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             WHERE o.conv_id = :convId
             ORDER BY m.ts_msg ASC, o.msg_id ASC, t.code ASC',
            ['convId' => $convId]
        );

        $observations = [];

        foreach ($rows as $row) {
            $observations[] = [
                'msg_id' => \is_string($row['msg_id'] ?? null) ? $row['msg_id'] : '',
                'ts_msg' => $this->toIso(\is_string($row['ts_msg'] ?? null) ? $row['ts_msg'] : null),
                'ttp_code' => \is_string($row['ttp_code'] ?? null) ? $row['ttp_code'] : '',
                'ttp_label' => \is_string($row['ttp_label'] ?? null) ? $row['ttp_label'] : '',
                'phase' => \is_string($row['phase'] ?? null) ? $row['phase'] : '',
                'confidence' => \is_numeric($row['confidence'] ?? null) ? (float) $row['confidence'] : 0.0,
                'status' => \is_string($row['status'] ?? null) ? $row['status'] : '',
                'evidence_start' => \is_numeric($row['evidence_start'] ?? null) ? (int) $row['evidence_start'] : null,
                'evidence_end' => \is_numeric($row['evidence_end'] ?? null) ? (int) $row['evidence_end'] : null,
            ];
        }

        return [
            'conv_id' => $convId,
            'observations' => $observations,
        ];
    }

    /**
     * Per-message elicitation timeline for one conversation, both directions,
     * ordered by ts_msg ASC (msg_id tiebreak). Each entry carries the TTPs
     * observed on the message (always empty for outbound — extraction is
     * inbound-only), the IOCs revealed in it, and — for outbound messages —
     * the dominant stimulus type attributed to it by enriched ioc_context
     * rows (stimulus_msg_id attribution).
     *
     * @return list<array<string, mixed>>
     */
    public function conversationTimeline(string $convId): array
    {
        $messageRows = $this->connection->fetchAllAssociative(
            'SELECT m.msg_id, d.code AS direction, m.ts_msg, m.subject
             FROM message m
             JOIN lkp_direction d ON d.dir_id = m.direction
             WHERE m.conv_id = :convId AND m.deleted_at IS NULL
             ORDER BY m.ts_msg ASC, m.msg_id ASC',
            ['convId' => $convId]
        );

        if ($messageRows === []) {
            return [];
        }

        $ttpsByMsg = $this->ttpsByMessage($convId);
        $iocsByMsg = $this->iocsByMessage($convId);
        $stimulusByMsg = $this->stimulusByMessage($convId);

        $timeline = [];

        foreach ($messageRows as $row) {
            $msgId = \is_string($row['msg_id'] ?? null) ? $row['msg_id'] : '';
            $direction = \is_string($row['direction'] ?? null) ? $row['direction'] : '';

            $timeline[] = [
                'msg_id' => $msgId,
                'direction' => $direction,
                'ts_msg' => $this->toIso(\is_string($row['ts_msg'] ?? null) ? $row['ts_msg'] : null),
                'subject' => \is_string($row['subject'] ?? null) ? $row['subject'] : null,
                'ttps' => $ttpsByMsg[$msgId] ?? [],
                'iocs_revealed' => $iocsByMsg[$msgId] ?? [],
                'stimulus_type' => $direction === 'out' ? ($stimulusByMsg[$msgId] ?? null) : null,
            ];
        }

        return $timeline;
    }

    /**
     * Aggregated TTP profile for a threat-actor cluster: per-TTP frequencies,
     * first/last seen and top adjacent-pair sequences across the cluster's
     * conversations. Null when the cluster does not exist (or was merged
     * away), so callers can 404; a known cluster without observations yields
     * empty lists.
     *
     * Only confirmed-status observations feed the aggregates: review rows are
     * unvalidated extractions awaiting analyst triage and must not distort
     * cluster-level intelligence.
     *
     * @return array{cluster_id: string, ttps: list<array<string, mixed>>, top_sequences: list<array{sequence: list<string>, count: int}>}|null
     */
    public function clusterTtpProfile(string $clusterId): ?array
    {
        $found = $this->connection->fetchOne(
            "SELECT 1 FROM threat_actor_cluster WHERE cluster_id = :clusterId AND status != 'merged'",
            ['clusterId' => $clusterId]
        );

        if ($found === false) {
            return null;
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT t.code AS ttp_code, t.label AS ttp_label, t.phase,
                    COUNT(*) AS observation_count,
                    COUNT(DISTINCT m.conv_id) AS conversation_count,
                    AVG(o.confidence) AS avg_confidence,
                    MIN(m.ts_msg) AS first_seen,
                    MAX(m.ts_msg) AS last_seen
             FROM threat_actor_cluster_conversation tacc
             JOIN conversation c ON c.conv_id = tacc.conv_id AND c.deleted_at IS NULL
             JOIN message m ON m.conv_id = tacc.conv_id AND m.deleted_at IS NULL
             JOIN ttp_observation o ON o.msg_id = m.msg_id AND o.status = 'confirmed'
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             WHERE tacc.cluster_id = :clusterId
             GROUP BY t.code, t.label, t.phase
             ORDER BY observation_count DESC, t.code ASC",
            ['clusterId' => $clusterId]
        );

        $ttps = [];

        foreach ($rows as $row) {
            $ttps[] = [
                'ttp_code' => \is_string($row['ttp_code'] ?? null) ? $row['ttp_code'] : '',
                'ttp_label' => \is_string($row['ttp_label'] ?? null) ? $row['ttp_label'] : '',
                'phase' => \is_string($row['phase'] ?? null) ? $row['phase'] : '',
                'observation_count' => \is_numeric($row['observation_count'] ?? null) ? (int) $row['observation_count'] : 0,
                'conversation_count' => \is_numeric($row['conversation_count'] ?? null) ? (int) $row['conversation_count'] : 0,
                'avg_confidence' => \is_numeric($row['avg_confidence'] ?? null) ? round((float) $row['avg_confidence'], 3) : 0.0,
                'first_seen' => $this->toIso(\is_string($row['first_seen'] ?? null) ? $row['first_seen'] : null),
                'last_seen' => $this->toIso(\is_string($row['last_seen'] ?? null) ? $row['last_seen'] : null),
            ];
        }

        return [
            'cluster_id' => $clusterId,
            'ttps' => $ttps,
            'top_sequences' => $this->topSequences($clusterId),
        ];
    }

    /**
     * Shared-playbook matrix: confirmed-only TTP observation counts for every
     * live (non-merged) threat-actor cluster that has at least one confirmed
     * observation, shaped as a sparse cluster x TTP grid. Zero cells are omitted
     * (the consumer fills the gaps). Clusters are ordered by their total
     * confirmed-observation volume (widest playbook first, cluster_id tiebreak)
     * and capped at {@see CLUSTER_MATRIX_LIMIT}; when the cap bites, `truncated`
     * is true and `total_clusters` reports the full population, so no truncation
     * is silent. Only TTP codes that appear in a returned cell become columns,
     * ordered by canonical scam phase then code.
     *
     * The cluster/conversation/message/observation join path and the
     * confirmed-only + soft-delete boundaries mirror {@see clusterTtpProfile}
     * exactly, so a cluster's row here is consistent with its own profile.
     *
     * Alongside the raw observation counts, each cluster row carries its
     * conversation_total (distinct conversations with any confirmed observation)
     * and each cell its conversation_count (distinct conversations exhibiting the
     * TTP in that cluster), so the consumer can normalize per conversation — a
     * fair "share of the playbook" that does not inflate on chatty conversations.
     * The distinct-conversation totals cannot be recovered by summing the
     * per-cell distinct counts (a conversation exhibiting several TTPs would be
     * counted more than once), so they come from a second grouping set in the
     * same scan (the {@see personaMatrix} pattern). The legacy keys (`count`,
     * `observation_total`) are preserved for backward compatibility.
     *
     * @return array{
     *     clusters: list<array{cluster_id: string, label: string, observation_total: int, conversation_total: int}>,
     *     ttps: list<array{ttp_code: string, ttp_label: string, phase: string}>,
     *     cells: list<array{cluster_id: string, ttp_code: string, count: int, conversation_count: int}>,
     *     truncated: bool,
     *     total_clusters: int
     * }
     */
    public function clusterTtpMatrix(): array
    {
        // One scan, two grouping sets: the (cluster, ttp) rows are the cells;
        // the (cluster) rows carry each cluster's distinct-conversation total
        // (and its observation total), which cannot be derived by summing the
        // per-cell distinct counts.
        $rows = $this->connection->fetchAllAssociative(
            "SELECT tac.cluster_id, tac.name AS cluster_name,
                    t.code AS ttp_code, t.label AS ttp_label, t.phase,
                    COUNT(*) AS observation_count,
                    COUNT(DISTINCT m.conv_id) AS conversation_count
             FROM threat_actor_cluster tac
             JOIN threat_actor_cluster_conversation tacc ON tacc.cluster_id = tac.cluster_id
             JOIN conversation c ON c.conv_id = tacc.conv_id AND c.deleted_at IS NULL
             JOIN message m ON m.conv_id = tacc.conv_id AND m.deleted_at IS NULL
             JOIN ttp_observation o ON o.msg_id = m.msg_id AND o.status = 'confirmed'
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             WHERE tac.status != 'merged'
             GROUP BY GROUPING SETS (
                 (tac.cluster_id, tac.name, t.code, t.label, t.phase),
                 (tac.cluster_id, tac.name)
             )"
        );

        /** @var array<string, array{cluster_id: string, label: string, observation_total: int, conversation_total: int}> $clusters */
        $clusters = [];
        /** @var list<array{cluster_id: string, ttp_code: string, count: int, conversation_count: int}> $allCells */
        $allCells = [];
        /** @var array<string, array{ttp_code: string, ttp_label: string, phase: string}> $ttpColumns */
        $ttpColumns = [];

        foreach ($rows as $row) {
            $clusterId = \is_string($row['cluster_id'] ?? null) ? $row['cluster_id'] : '';

            if ($clusterId === '') {
                continue;
            }

            $ttpCode = \is_string($row['ttp_code'] ?? null) ? $row['ttp_code'] : '';
            $name = \is_string($row['cluster_name'] ?? null) ? $row['cluster_name'] : '';

            // The cluster-total grouping-set row (TTP columns are NULL) carries
            // the cluster's distinct-conversation denominator and observation total.
            if ($ttpCode === '') {
                $clusters[$clusterId] = [
                    'cluster_id' => $clusterId,
                    'label' => $name !== '' ? $name : substr($clusterId, 0, 8),
                    'observation_total' => \is_numeric($row['observation_count'] ?? null) ? (int) $row['observation_count'] : 0,
                    'conversation_total' => \is_numeric($row['conversation_count'] ?? null) ? (int) $row['conversation_count'] : 0,
                ];

                continue;
            }

            $count = \is_numeric($row['observation_count'] ?? null) ? (int) $row['observation_count'] : 0;

            if ($count <= 0) {
                continue;
            }

            $allCells[] = [
                'cluster_id' => $clusterId,
                'ttp_code' => $ttpCode,
                'count' => $count,
                'conversation_count' => \is_numeric($row['conversation_count'] ?? null) ? (int) $row['conversation_count'] : 0,
            ];

            $ttpColumns[$ttpCode] ??= [
                'ttp_code' => $ttpCode,
                'ttp_label' => \is_string($row['ttp_label'] ?? null) ? $row['ttp_label'] : '',
                'phase' => \is_string($row['phase'] ?? null) ? $row['phase'] : '',
            ];
        }

        $totalClusters = \count($clusters);

        // Widest playbook first (observation_total DESC), deterministic id tiebreak, then cap.
        $clusterList = array_values($clusters);
        usort($clusterList, static function (array $a, array $b): int {
            return [$b['observation_total'], $a['cluster_id']] <=> [$a['observation_total'], $b['cluster_id']];
        });

        $truncated = $totalClusters > self::CLUSTER_MATRIX_LIMIT;
        $clusterList = \array_slice($clusterList, 0, self::CLUSTER_MATRIX_LIMIT);

        // Restrict cells (and therefore the TTP columns) to the retained clusters.
        $keptClusterIds = array_fill_keys(array_column($clusterList, 'cluster_id'), true);

        $cells = [];
        /** @var array<string, true> $keptTtpCodes */
        $keptTtpCodes = [];

        foreach ($allCells as $cell) {
            if (!isset($keptClusterIds[$cell['cluster_id']])) {
                continue;
            }

            $cells[] = $cell;
            $keptTtpCodes[$cell['ttp_code']] = true;
        }

        usort($cells, static function (array $a, array $b): int {
            return [$a['cluster_id'], $a['ttp_code']] <=> [$b['cluster_id'], $b['ttp_code']];
        });

        // Only emit columns present in a retained cell, ordered by phase then code.
        $ttps = [];

        foreach ($ttpColumns as $code => $column) {
            if (isset($keptTtpCodes[$code])) {
                $ttps[] = $column;
            }
        }

        usort($ttps, function (array $a, array $b): int {
            return [$this->phaseRank($a['phase']), $a['ttp_code']] <=> [$this->phaseRank($b['phase']), $b['ttp_code']];
        });

        return [
            'clusters' => $clusterList,
            'ttps' => $ttps,
            'cells' => $cells,
            'truncated' => $truncated,
            'total_clusters' => $totalClusters,
        ];
    }

    /**
     * Persona x TTP matrix: confirmed-only observation counts for every
     * persona that ran at least one conversation carrying a confirmed
     * observation, shaped as a sparse persona x TTP grid. The join path is
     * ttp_observation.conv_id -> conversation.persona_id -> persona;
     * conversations with no persona assigned are EXCLUDED from the grid and
     * reported separately in null_persona_conversations so the omission is
     * never silent.
     *
     * Each cell carries both the raw observation_count and the fair
     * conversation_count (distinct conversations exhibiting the TTP — the count
     * that does not inflate on chatty conversations); each persona row carries
     * its conversation_total (distinct conversations with any confirmed
     * observation) so the consumer can normalize per conversation. Personas are
     * ranked by conversation_total (widest first, persona_code tiebreak) and
     * capped at {@see PERSONA_MATRIX_LIMIT}; truncated + total_personas report a
     * bitten cap. Only TTP codes present in a retained cell become columns,
     * ordered by canonical scam phase then code. Soft-deleted messages and
     * conversations are excluded; no evidence text is selected.
     *
     * @return array{
     *     personas: list<array{code: string, label: string, conversation_total: int}>,
     *     ttps: list<array{code: string, label: string, phase: string}>,
     *     cells: list<array{persona_code: string, ttp_code: string, observation_count: int, conversation_count: int}>,
     *     truncated: bool,
     *     total_personas: int,
     *     null_persona_conversations: int
     * }
     */
    public function personaMatrix(): array
    {
        // One scan, two grouping sets: the (persona, ttp) rows are the cells;
        // the (persona) rows carry each persona's distinct-conversation total,
        // which cannot be recovered by summing the per-cell distinct counts (a
        // conversation exhibiting several TTPs would be counted more than once).
        $rows = $this->connection->fetchAllAssociative(
            "SELECT p.persona_code, p.persona_label,
                    t.code AS ttp_code, t.label AS ttp_label, t.phase,
                    COUNT(*) AS observation_count,
                    COUNT(DISTINCT o.conv_id) AS conversation_count
             FROM ttp_observation o
             JOIN message m ON m.msg_id = o.msg_id AND m.deleted_at IS NULL
             JOIN conversation c ON c.conv_id = o.conv_id AND c.deleted_at IS NULL
             JOIN persona p ON p.persona_id = c.persona_id
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             WHERE o.status = 'confirmed'
             GROUP BY GROUPING SETS (
                 (p.persona_code, p.persona_label, t.code, t.label, t.phase),
                 (p.persona_code, p.persona_label)
             )"
        );

        /** @var array<string, array{code: string, label: string, conversation_total: int}> $personas */
        $personas = [];
        /** @var list<array{persona_code: string, ttp_code: string, observation_count: int, conversation_count: int}> $allCells */
        $allCells = [];
        /** @var array<string, array{code: string, label: string, phase: string}> $ttpColumns */
        $ttpColumns = [];

        foreach ($rows as $row) {
            $personaCode = \is_string($row['persona_code'] ?? null) ? $row['persona_code'] : '';

            if ($personaCode === '') {
                continue;
            }

            $ttpCode = \is_string($row['ttp_code'] ?? null) ? $row['ttp_code'] : '';

            // The persona-total grouping-set row (TTP columns are NULL) carries
            // the persona's distinct-conversation denominator.
            if ($ttpCode === '') {
                $personas[$personaCode] = [
                    'code' => $personaCode,
                    'label' => \is_string($row['persona_label'] ?? null) ? $row['persona_label'] : '',
                    'conversation_total' => \is_numeric($row['conversation_count'] ?? null) ? (int) $row['conversation_count'] : 0,
                ];

                continue;
            }

            $observationCount = \is_numeric($row['observation_count'] ?? null) ? (int) $row['observation_count'] : 0;

            if ($observationCount <= 0) {
                continue;
            }

            $allCells[] = [
                'persona_code' => $personaCode,
                'ttp_code' => $ttpCode,
                'observation_count' => $observationCount,
                'conversation_count' => \is_numeric($row['conversation_count'] ?? null) ? (int) $row['conversation_count'] : 0,
            ];

            $ttpColumns[$ttpCode] ??= [
                'code' => $ttpCode,
                'label' => \is_string($row['ttp_label'] ?? null) ? $row['ttp_label'] : '',
                'phase' => \is_string($row['phase'] ?? null) ? $row['phase'] : '',
            ];
        }

        $totalPersonas = \count($personas);

        // Widest conversation volume first, deterministic code tiebreak, then cap.
        $personaList = array_values($personas);
        usort($personaList, static function (array $a, array $b): int {
            return [$b['conversation_total'], $a['code']] <=> [$a['conversation_total'], $b['code']];
        });

        $truncated = $totalPersonas > self::PERSONA_MATRIX_LIMIT;
        $personaList = \array_slice($personaList, 0, self::PERSONA_MATRIX_LIMIT);

        // Restrict cells (and therefore the TTP columns) to the retained personas.
        $keptPersonaCodes = array_fill_keys(array_column($personaList, 'code'), true);

        $cells = [];
        /** @var array<string, true> $keptTtpCodes */
        $keptTtpCodes = [];

        foreach ($allCells as $cell) {
            if (!isset($keptPersonaCodes[$cell['persona_code']])) {
                continue;
            }

            $cells[] = $cell;
            $keptTtpCodes[$cell['ttp_code']] = true;
        }

        usort($cells, static function (array $a, array $b): int {
            return [$a['persona_code'], $a['ttp_code']] <=> [$b['persona_code'], $b['ttp_code']];
        });

        // Only emit columns present in a retained cell, ordered by phase then code.
        $ttps = [];

        foreach ($ttpColumns as $code => $column) {
            if (isset($keptTtpCodes[$code])) {
                $ttps[] = $column;
            }
        }

        usort($ttps, function (array $a, array $b): int {
            return [$this->phaseRank($a['phase']), $a['code']] <=> [$this->phaseRank($b['phase']), $b['code']];
        });

        return [
            'personas' => $personaList,
            'ttps' => $ttps,
            'cells' => $cells,
            'truncated' => $truncated,
            'total_personas' => $totalPersonas,
            'null_persona_conversations' => $this->countNullPersonaConversations(),
        ];
    }

    /**
     * Distinct conversations carrying a confirmed observation but with no
     * persona assigned (conversation.persona_id IS NULL). Reported alongside
     * {@see personaMatrix} so the null-persona exclusion is never silent.
     */
    private function countNullPersonaConversations(): int
    {
        $count = $this->connection->fetchOne(
            "SELECT COUNT(DISTINCT o.conv_id)
             FROM ttp_observation o
             JOIN message m ON m.msg_id = o.msg_id AND m.deleted_at IS NULL
             JOIN conversation c ON c.conv_id = o.conv_id AND c.deleted_at IS NULL
             WHERE o.status = 'confirmed' AND c.persona_id IS NULL"
        );

        return \is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Stimulus x TTP matrix over the revelation-message population: confirmed
     * TTP observations on messages that ALSO carry an enriched ioc_context
     * with a non-null stimulus_type (validated join o.msg_id = oi.msg_id,
     * oi.obs_id = ic.obs_id, enrichment_status = 'enriched'). A confirmed
     * observation on a message with no enriched stimulus context is therefore
     * absent from the grid — the population is exactly the revelation messages,
     * and its size is reported as population_messages (distinct messages in
     * scope) so the consumer can state the scope honestly. UNKNOWN is kept as a
     * stimulus value (the consumer decides whether to collapse it).
     *
     * Each cell carries message_count (distinct messages where the TTP and the
     * stimulus co-occur) and conversation_count (distinct conversations).
     * Stimulus rows are ordered by their distinct-message volume (widest first,
     * name tiebreak); only TTP codes present in a cell become columns, ordered
     * by canonical scam phase then code. Soft-deleted messages and
     * conversations are excluded; no evidence text is selected.
     *
     * @return array{
     *     stimuli: list<string>,
     *     ttps: list<array{code: string, label: string, phase: string}>,
     *     cells: list<array{stimulus_type: string, ttp_code: string, message_count: int, conversation_count: int}>,
     *     population_messages: int
     * }
     */
    public function stimulusMatrix(): array
    {
        $scopeJoin = "FROM ttp_observation o
             JOIN message m ON m.msg_id = o.msg_id AND m.deleted_at IS NULL
             JOIN conversation c ON c.conv_id = o.conv_id AND c.deleted_at IS NULL
             JOIN observed_ioc oi ON oi.msg_id = o.msg_id
             JOIN ioc_context ic ON ic.obs_id = oi.obs_id
                 AND ic.enrichment_status = 'enriched' AND ic.stimulus_type IS NOT NULL
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             WHERE o.status = 'confirmed'";

        $cellRows = $this->connection->fetchAllAssociative(
            'SELECT ic.stimulus_type,
                    t.code AS ttp_code, t.label AS ttp_label, t.phase,
                    COUNT(DISTINCT o.msg_id) AS message_count,
                    COUNT(DISTINCT o.conv_id) AS conversation_count
             ' . $scopeJoin . '
             GROUP BY ic.stimulus_type, t.code, t.label, t.phase'
        );

        // Per-stimulus distinct-message volume, for the widest-first row order.
        $totalRows = $this->connection->fetchAllAssociative(
            'SELECT ic.stimulus_type, COUNT(DISTINCT o.msg_id) AS message_total
             ' . $scopeJoin . '
             GROUP BY ic.stimulus_type'
        );

        // Distinct messages in the whole scoped population (a scalar query so
        // the empty case is unambiguous — a message is counted once even when
        // it carries several enriched stimulus contexts).
        $populationRaw = $this->connection->fetchOne('SELECT COUNT(DISTINCT o.msg_id) ' . $scopeJoin);
        $population = \is_numeric($populationRaw) ? (int) $populationRaw : 0;

        /** @var array<string, int> $stimulusTotals */
        $stimulusTotals = [];

        foreach ($totalRows as $row) {
            $stimulus = \is_string($row['stimulus_type'] ?? null) ? $row['stimulus_type'] : '';

            if ($stimulus === '') {
                continue;
            }

            $stimulusTotals[$stimulus] = \is_numeric($row['message_total'] ?? null) ? (int) $row['message_total'] : 0;
        }

        /** @var list<array{stimulus_type: string, ttp_code: string, message_count: int, conversation_count: int}> $cells */
        $cells = [];
        /** @var array<string, array{code: string, label: string, phase: string}> $ttpColumns */
        $ttpColumns = [];
        /** @var array<string, true> $presentStimuli */
        $presentStimuli = [];

        foreach ($cellRows as $row) {
            $stimulus = \is_string($row['stimulus_type'] ?? null) ? $row['stimulus_type'] : '';
            $ttpCode = \is_string($row['ttp_code'] ?? null) ? $row['ttp_code'] : '';
            $messageCount = \is_numeric($row['message_count'] ?? null) ? (int) $row['message_count'] : 0;

            if ($stimulus === '' || $ttpCode === '' || $messageCount <= 0) {
                continue;
            }

            $cells[] = [
                'stimulus_type' => $stimulus,
                'ttp_code' => $ttpCode,
                'message_count' => $messageCount,
                'conversation_count' => \is_numeric($row['conversation_count'] ?? null) ? (int) $row['conversation_count'] : 0,
            ];

            $presentStimuli[$stimulus] = true;

            $ttpColumns[$ttpCode] ??= [
                'code' => $ttpCode,
                'label' => \is_string($row['ttp_label'] ?? null) ? $row['ttp_label'] : '',
                'phase' => \is_string($row['phase'] ?? null) ? $row['phase'] : '',
            ];
        }

        // UNKNOWN is the catch-all — it sinks to the last row regardless of
        // volume; the remaining stimuli keep the widest-first order (name
        // tiebreak).
        $stimuli = array_keys($presentStimuli);
        usort($stimuli, static function (string $a, string $b) use ($stimulusTotals): int {
            $aUnknown = $a === 'UNKNOWN' ? 1 : 0;
            $bUnknown = $b === 'UNKNOWN' ? 1 : 0;

            return [$aUnknown, $stimulusTotals[$b] ?? 0, $a] <=> [$bUnknown, $stimulusTotals[$a] ?? 0, $b];
        });

        usort($cells, static function (array $a, array $b): int {
            return [$a['stimulus_type'], $a['ttp_code']] <=> [$b['stimulus_type'], $b['ttp_code']];
        });

        $ttps = array_values($ttpColumns);
        usort($ttps, function (array $a, array $b): int {
            return [$this->phaseRank($a['phase']), $a['code']] <=> [$this->phaseRank($b['phase']), $b['code']];
        });

        return [
            'stimuli' => $stimuli,
            'ttps' => $ttps,
            'cells' => $cells,
            'population_messages' => $population,
        ];
    }

    /**
     * IOCs co-observed in the same messages as a TTP (confirmed observations
     * only): per distinct indicator its type + normalized value, the number of
     * distinct messages where both the TTP and the IOC were seen
     * (co_occurrence_count) and the distinct conversation span. Ordered by
     * co-occurrence DESC. Soft-deleted messages and conversations are excluded;
     * no evidence text is selected. Type/value_norm come from the canonical
     * indicator row (the deduplicated IOC identity), matching how the IOC graph
     * and STIX export read IOC values.
     *
     * @return array{ttp_code: string, iocs: list<array{indicator_id: string, type: string, value_norm: string, co_occurrence_count: int, conversation_count: int}>}
     */
    public function iocsForTtp(string $ttpCode, int $limit = 100): array
    {
        $limit = max(1, min($limit, self::COOCCURRENCE_LIMIT_MAX));

        $rows = $this->connection->fetchAllAssociative(
            "SELECT i.indicator_id AS indicator_id, i.type AS type, i.value_norm AS value_norm,
                    COUNT(DISTINCT m.msg_id) AS co_occurrence_count,
                    COUNT(DISTINCT m.conv_id) AS conversation_count
             FROM ttp_observation o
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             JOIN message m ON m.msg_id = o.msg_id AND m.deleted_at IS NULL
             JOIN conversation c ON c.conv_id = m.conv_id AND c.deleted_at IS NULL
             JOIN observed_ioc oi ON oi.msg_id = o.msg_id
             JOIN indicator i ON i.indicator_id = oi.indicator_id
             WHERE t.code = :ttpCode AND o.status = 'confirmed'
             GROUP BY i.indicator_id, i.type, i.value_norm
             ORDER BY co_occurrence_count DESC, i.type ASC, i.value_norm ASC
             LIMIT :limit",
            ['ttpCode' => $ttpCode, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER]
        );

        $iocs = [];

        foreach ($rows as $row) {
            $iocs[] = [
                'indicator_id' => \is_string($row['indicator_id'] ?? null) ? $row['indicator_id'] : '',
                'type' => \is_string($row['type'] ?? null) ? $row['type'] : '',
                'value_norm' => \is_string($row['value_norm'] ?? null) ? $row['value_norm'] : '',
                'co_occurrence_count' => \is_numeric($row['co_occurrence_count'] ?? null) ? (int) $row['co_occurrence_count'] : 0,
                'conversation_count' => \is_numeric($row['conversation_count'] ?? null) ? (int) $row['conversation_count'] : 0,
            ];
        }

        return [
            'ttp_code' => $ttpCode,
            'iocs' => $iocs,
        ];
    }

    /**
     * Inverse pivot: TTPs co-observed in the same messages as a given IOC
     * indicator (confirmed observations only), with per-TTP co-occurrence and
     * conversation counts, ordered by co-occurrence DESC. Same soft-delete and
     * no-evidence boundaries as {@see iocsForTtp}.
     *
     * @return array{ioc: string, ttps: list<array{ttp_code: string, ttp_label: string, phase: string, co_occurrence_count: int, conversation_count: int}>}
     */
    public function ttpsForIoc(string $indicatorId, int $limit = 100): array
    {
        $limit = max(1, min($limit, self::COOCCURRENCE_LIMIT_MAX));

        $rows = $this->connection->fetchAllAssociative(
            "SELECT t.code AS ttp_code, t.label AS ttp_label, t.phase,
                    COUNT(DISTINCT m.msg_id) AS co_occurrence_count,
                    COUNT(DISTINCT m.conv_id) AS conversation_count
             FROM observed_ioc oi
             JOIN message m ON m.msg_id = oi.msg_id AND m.deleted_at IS NULL
             JOIN conversation c ON c.conv_id = m.conv_id AND c.deleted_at IS NULL
             JOIN ttp_observation o ON o.msg_id = oi.msg_id AND o.status = 'confirmed'
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             WHERE oi.indicator_id = :indicatorId
             GROUP BY t.code, t.label, t.phase
             ORDER BY co_occurrence_count DESC, t.code ASC
             LIMIT :limit",
            ['indicatorId' => $indicatorId, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER]
        );

        $ttps = [];

        foreach ($rows as $row) {
            $ttps[] = [
                'ttp_code' => \is_string($row['ttp_code'] ?? null) ? $row['ttp_code'] : '',
                'ttp_label' => \is_string($row['ttp_label'] ?? null) ? $row['ttp_label'] : '',
                'phase' => \is_string($row['phase'] ?? null) ? $row['phase'] : '',
                'co_occurrence_count' => \is_numeric($row['co_occurrence_count'] ?? null) ? (int) $row['co_occurrence_count'] : 0,
                'conversation_count' => \is_numeric($row['conversation_count'] ?? null) ? (int) $row['conversation_count'] : 0,
            ];
        }

        return [
            'ioc' => $indicatorId,
            'ttps' => $ttps,
        ];
    }

    /**
     * Full taxonomy with per-TTP usage counters. LEFT JOIN keeps
     * zero-observation entries visible so the taxonomy is always complete.
     *
     * observation_count / conversation_count / first_seen / last_seen cover
     * confirmed observations only (same validation boundary as the cluster
     * profile); review_count tallies the rows still awaiting analyst triage.
     * Each entry also carries the taxonomy's example formulations and its
     * external references (both decoded from the lkp_ttp JSONB columns), so
     * the detail surface can render them without an extra endpoint.
     *
     * @return list<array<string, mixed>>
     */
    public function taxonomyOverview(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT t.code AS ttp_code, t.label AS ttp_label, t.phase, t.definition,
                    t.examples, t.external_refs,
                    COUNT(o.obs_id) FILTER (WHERE o.status = 'confirmed') AS observation_count,
                    COUNT(DISTINCT m.conv_id) FILTER (WHERE o.status = 'confirmed') AS conversation_count,
                    MIN(m.ts_msg) FILTER (WHERE o.status = 'confirmed') AS first_seen,
                    MAX(m.ts_msg) FILTER (WHERE o.status = 'confirmed') AS last_seen,
                    COUNT(o.obs_id) FILTER (WHERE o.status = 'review') AS review_count
             FROM lkp_ttp t
             LEFT JOIN (ttp_observation o
                 JOIN message m ON m.msg_id = o.msg_id AND m.deleted_at IS NULL
                 JOIN conversation c ON c.conv_id = o.conv_id AND c.deleted_at IS NULL
             ) ON o.ttp_id = t.ttp_id
             GROUP BY t.code, t.label, t.phase, t.definition, t.examples, t.external_refs
             ORDER BY t.code ASC"
        );

        $overview = [];

        foreach ($rows as $row) {
            $overview[] = [
                'ttp_code' => \is_string($row['ttp_code'] ?? null) ? $row['ttp_code'] : '',
                'ttp_label' => \is_string($row['ttp_label'] ?? null) ? $row['ttp_label'] : '',
                'phase' => \is_string($row['phase'] ?? null) ? $row['phase'] : '',
                'definition' => \is_string($row['definition'] ?? null) ? $row['definition'] : '',
                'examples' => $this->decodeStringList($row['examples'] ?? null),
                'external_refs' => $this->decodeExternalRefs($row['external_refs'] ?? null),
                'observation_count' => \is_numeric($row['observation_count'] ?? null) ? (int) $row['observation_count'] : 0,
                'conversation_count' => \is_numeric($row['conversation_count'] ?? null) ? (int) $row['conversation_count'] : 0,
                'first_seen' => $this->toIso(\is_string($row['first_seen'] ?? null) ? $row['first_seen'] : null),
                'last_seen' => $this->toIso(\is_string($row['last_seen'] ?? null) ? $row['last_seen'] : null),
                'review_count' => \is_numeric($row['review_count'] ?? null) ? (int) $row['review_count'] : 0,
            ];
        }

        return $overview;
    }

    /**
     * Observations awaiting analyst triage (status 'review'), newest message
     * first. Strictly read-only triage data: taxonomy identity, confidence,
     * conversation/message anchors and provenance — never the evidence text
     * (offsets only, quotes are reconstructed client-side). Soft-deleted
     * messages and conversations are excluded. The item list is capped at
     * {@see REVIEW_QUEUE_LIMIT}; `total` always reports the full queue size so
     * a bitten cap is never silent.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function reviewQueue(): array
    {
        $totalRaw = $this->connection->fetchOne(
            "SELECT COUNT(*)
             FROM ttp_observation o
             JOIN message m ON m.msg_id = o.msg_id AND m.deleted_at IS NULL
             JOIN conversation c ON c.conv_id = o.conv_id AND c.deleted_at IS NULL
             WHERE o.status = 'review'"
        );
        $total = \is_numeric($totalRaw) ? (int) $totalRaw : 0;

        $rows = $this->connection->fetchAllAssociative(
            "SELECT o.obs_id, t.code AS ttp_code, t.label AS ttp_label, t.phase,
                    o.confidence, o.conv_id, o.msg_id, m.ts_msg,
                    o.evidence_start, o.evidence_end, o.extraction_model
             FROM ttp_observation o
             JOIN message m ON m.msg_id = o.msg_id AND m.deleted_at IS NULL
             JOIN conversation c ON c.conv_id = o.conv_id AND c.deleted_at IS NULL
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             WHERE o.status = 'review'
             ORDER BY m.ts_msg DESC, o.obs_id ASC
             LIMIT :limit",
            ['limit' => self::REVIEW_QUEUE_LIMIT],
            ['limit' => ParameterType::INTEGER]
        );

        $items = [];

        foreach ($rows as $row) {
            $items[] = [
                'obs_id' => \is_string($row['obs_id'] ?? null) ? $row['obs_id'] : '',
                'ttp_code' => \is_string($row['ttp_code'] ?? null) ? $row['ttp_code'] : '',
                'ttp_label' => \is_string($row['ttp_label'] ?? null) ? $row['ttp_label'] : '',
                'phase' => \is_string($row['phase'] ?? null) ? $row['phase'] : '',
                'confidence' => \is_numeric($row['confidence'] ?? null) ? (float) $row['confidence'] : 0.0,
                'conv_id' => \is_string($row['conv_id'] ?? null) ? $row['conv_id'] : '',
                'msg_id' => \is_string($row['msg_id'] ?? null) ? $row['msg_id'] : '',
                'ts_msg' => $this->toIso(\is_string($row['ts_msg'] ?? null) ? $row['ts_msg'] : null),
                'evidence_start' => \is_numeric($row['evidence_start'] ?? null) ? (int) $row['evidence_start'] : null,
                'evidence_end' => \is_numeric($row['evidence_end'] ?? null) ? (int) $row['evidence_end'] : null,
                'extraction_model' => \is_string($row['extraction_model'] ?? null) ? $row['extraction_model'] : '',
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * Weekly confirmed-observation counts per scam phase over the last
     * {@see TREND_WEEKS} ISO weeks (current week included), bucketed on the
     * message timestamp. Every week and every canonical phase is zero-filled
     * server-side, so the consumer always receives a dense 8x6 grid; a phase
     * outside the canonical order would be appended rather than dropped.
     *
     * @return array{weeks: list<array{week: string, counts: array<string, int>}>}
     */
    public function phaseTrend(): array
    {
        $currentWeekStart = $this->currentWeekStart();
        $windowStart = $currentWeekStart->modify(sprintf('-%d weeks', self::TREND_WEEKS - 1));
        $windowEnd = $currentWeekStart->modify('+1 week');

        $rows = $this->connection->fetchAllAssociative(
            "SELECT DATE_TRUNC('week', m.ts_msg) AS week_start, t.phase,
                    COUNT(*) AS observation_count
             FROM ttp_observation o
             JOIN message m ON m.msg_id = o.msg_id AND m.deleted_at IS NULL
             JOIN conversation c ON c.conv_id = o.conv_id AND c.deleted_at IS NULL
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             WHERE o.status = 'confirmed'
               AND m.ts_msg >= :windowStart AND m.ts_msg < :windowEnd
             GROUP BY week_start, t.phase",
            [
                'windowStart' => $windowStart->format('Y-m-d H:i:s'),
                'windowEnd' => $windowEnd->format('Y-m-d H:i:s'),
            ]
        );

        // Canonical phases first; an unexpected phase becomes an extra column
        // in every week instead of being silently dropped.
        $phases = self::PHASE_ORDER;

        foreach ($rows as $row) {
            $phase = \is_string($row['phase'] ?? null) ? $row['phase'] : '';

            if ($phase !== '' && !\in_array($phase, $phases, true)) {
                $phases[] = $phase;
            }
        }

        /** @var array<string, array<string, int>> $countsByWeek */
        $countsByWeek = [];

        for ($i = 0; $i < self::TREND_WEEKS; ++$i) {
            $week = $windowStart->modify(sprintf('+%d weeks', $i))->format('Y-m-d');
            $countsByWeek[$week] = array_fill_keys($phases, 0);
        }

        foreach ($rows as $row) {
            $week = substr(\is_string($row['week_start'] ?? null) ? $row['week_start'] : '', 0, 10);
            $phase = \is_string($row['phase'] ?? null) ? $row['phase'] : '';

            if ($phase === '' || !isset($countsByWeek[$week])) {
                continue;
            }

            $countsByWeek[$week][$phase] = \is_numeric($row['observation_count'] ?? null) ? (int) $row['observation_count'] : 0;
        }

        $weeks = [];

        foreach ($countsByWeek as $week => $counts) {
            $weeks[] = [
                'week' => $week,
                'counts' => $counts,
            ];
        }

        return ['weeks' => $weeks];
    }

    /**
     * Live (non-merged) threat-actor clusters whose conversations carry
     * confirmed observations of a TTP, widest conversation span first.
     * Same confirmed-only and soft-delete boundaries as the cluster profile;
     * the label falls back to a cluster_id prefix when the cluster is unnamed.
     * Capped at {@see TTP_CLUSTERS_LIMIT} with an explicit truncation flag.
     *
     * @return array{items: list<array{cluster_id: string, label: string, observation_count: int, conversation_count: int, first_seen: ?string, last_seen: ?string}>, truncated: bool}
     */
    public function clustersForTtp(string $ttpCode): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT tac.cluster_id, tac.name AS cluster_name,
                    COUNT(*) AS observation_count,
                    COUNT(DISTINCT m.conv_id) AS conversation_count,
                    MIN(m.ts_msg) AS first_seen,
                    MAX(m.ts_msg) AS last_seen
             FROM ttp_observation o
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             JOIN message m ON m.msg_id = o.msg_id AND m.deleted_at IS NULL
             JOIN conversation c ON c.conv_id = m.conv_id AND c.deleted_at IS NULL
             JOIN threat_actor_cluster_conversation tacc ON tacc.conv_id = m.conv_id
             JOIN threat_actor_cluster tac ON tac.cluster_id = tacc.cluster_id AND tac.status != 'merged'
             WHERE t.code = :ttpCode AND o.status = 'confirmed'
             GROUP BY tac.cluster_id, tac.name
             ORDER BY conversation_count DESC, observation_count DESC, tac.cluster_id ASC
             LIMIT :limit",
            ['ttpCode' => $ttpCode, 'limit' => self::TTP_CLUSTERS_LIMIT + 1],
            ['limit' => ParameterType::INTEGER]
        );

        $truncated = \count($rows) > self::TTP_CLUSTERS_LIMIT;
        $rows = \array_slice($rows, 0, self::TTP_CLUSTERS_LIMIT);

        $items = [];

        foreach ($rows as $row) {
            $clusterId = \is_string($row['cluster_id'] ?? null) ? $row['cluster_id'] : '';
            $name = \is_string($row['cluster_name'] ?? null) ? $row['cluster_name'] : '';

            $items[] = [
                'cluster_id' => $clusterId,
                'label' => $name !== '' ? $name : substr($clusterId, 0, 8),
                'observation_count' => \is_numeric($row['observation_count'] ?? null) ? (int) $row['observation_count'] : 0,
                'conversation_count' => \is_numeric($row['conversation_count'] ?? null) ? (int) $row['conversation_count'] : 0,
                'first_seen' => $this->toIso(\is_string($row['first_seen'] ?? null) ? $row['first_seen'] : null),
                'last_seen' => $this->toIso(\is_string($row['last_seen'] ?? null) ? $row['last_seen'] : null),
            ];
        }

        return [
            'items' => $items,
            'truncated' => $truncated,
        ];
    }

    /**
     * Paginated conversations in which a TTP was observed (either status —
     * the confirmed/review split is reported per row so review-only
     * conversations stay visible for triage), most recent observation first.
     * The subject is the first non-deleted message of the conversation
     * (nullable); last_seen is the newest message carrying the TTP.
     * Soft-deleted messages and conversations are excluded.
     *
     * @return list<array{conv_id: string, subject: ?string, scam_type_code: ?string, observation_count: int, review_count: int, last_seen: ?string}>
     */
    public function conversationsForTtp(string $ttpCode, int $limit = self::CONVERSATIONS_PAGE_DEFAULT, int $offset = 0): array
    {
        $limit = max(1, min($limit, self::CONVERSATIONS_PAGE_MAX));
        $offset = max(0, $offset);

        $rows = $this->connection->fetchAllAssociative(
            "SELECT agg.conv_id, fm.subject, st.code AS scam_type_code,
                    agg.observation_count, agg.review_count, agg.last_seen
             FROM (
                 SELECT o.conv_id,
                        COUNT(*) FILTER (WHERE o.status = 'confirmed') AS observation_count,
                        COUNT(*) FILTER (WHERE o.status = 'review') AS review_count,
                        MAX(m.ts_msg) AS last_seen
                 FROM ttp_observation o
                 JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
                 JOIN message m ON m.msg_id = o.msg_id AND m.deleted_at IS NULL
                 JOIN conversation c ON c.conv_id = o.conv_id AND c.deleted_at IS NULL
                 WHERE t.code = :ttpCode
                 GROUP BY o.conv_id
             ) agg
             JOIN conversation c2 ON c2.conv_id = agg.conv_id
             LEFT JOIN lkp_scam_type st ON st.scam_type_id = c2.scam_type_id
             LEFT JOIN LATERAL (
                 SELECT m2.subject
                 FROM message m2
                 WHERE m2.conv_id = agg.conv_id AND m2.deleted_at IS NULL
                 ORDER BY m2.ts_msg ASC, m2.msg_id ASC
                 LIMIT 1
             ) fm ON TRUE
             ORDER BY agg.last_seen DESC, agg.conv_id ASC
             LIMIT :limit OFFSET :offset",
            ['ttpCode' => $ttpCode, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]
        );

        $items = [];

        foreach ($rows as $row) {
            $items[] = [
                'conv_id' => \is_string($row['conv_id'] ?? null) ? $row['conv_id'] : '',
                'subject' => \is_string($row['subject'] ?? null) ? $row['subject'] : null,
                'scam_type_code' => \is_string($row['scam_type_code'] ?? null) ? $row['scam_type_code'] : null,
                'observation_count' => \is_numeric($row['observation_count'] ?? null) ? (int) $row['observation_count'] : 0,
                'review_count' => \is_numeric($row['review_count'] ?? null) ? (int) $row['review_count'] : 0,
                'last_seen' => $this->toIso(\is_string($row['last_seen'] ?? null) ? $row['last_seen'] : null),
            ];
        }

        return $items;
    }

    /**
     * Total conversations in which a TTP was observed (either status), for the
     * pagination envelope of {@see conversationsForTtp}. Same boundaries.
     */
    public function countConversationsForTtp(string $ttpCode): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT o.conv_id)
             FROM ttp_observation o
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             JOIN message m ON m.msg_id = o.msg_id AND m.deleted_at IS NULL
             JOIN conversation c ON c.conv_id = o.conv_id AND c.deleted_at IS NULL
             WHERE t.code = :ttpCode',
            ['ttpCode' => $ttpCode]
        );

        return \is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Confirmed-only TTP aggregates for a cluster, shaped for STIX export: per
     * observed TTP the taxonomy text (code/label/definition/phase/external_refs)
     * plus the count and the raw first/last observation timestamps that drive the
     * "uses" relationship start/stop_time and the sighting count/first/last_seen.
     *
     * Same validation boundary as {@see clusterTtpProfile} (confirmed only,
     * soft-deleted conversations and messages excluded). Timestamps are returned
     * raw (the STIX builder formats them); evidence is never selected.
     *
     * @return list<array{code: string, label: string, definition: string, phase: string, external_refs: list<array<string, mixed>>, count: int, first_seen: ?string, last_seen: ?string}>
     */
    public function clusterTtpStixData(string $clusterId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT t.code, t.label, t.definition, t.phase, t.external_refs,
                    COUNT(*) AS observation_count,
                    MIN(m.ts_msg) AS first_seen,
                    MAX(m.ts_msg) AS last_seen
             FROM threat_actor_cluster_conversation tacc
             JOIN conversation c ON c.conv_id = tacc.conv_id AND c.deleted_at IS NULL
             JOIN message m ON m.conv_id = tacc.conv_id AND m.deleted_at IS NULL
             JOIN ttp_observation o ON o.msg_id = m.msg_id AND o.status = 'confirmed'
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             WHERE tacc.cluster_id = :clusterId
             GROUP BY t.code, t.label, t.definition, t.phase, t.external_refs
             ORDER BY observation_count DESC, t.code ASC",
            ['clusterId' => $clusterId]
        );

        return array_map(fn (array $row): array => $this->toStixTtpRow($row), $rows);
    }

    /**
     * Confirmed-only TTP aggregates for a single conversation, shaped for STIX
     * export (used by the unattributed-conversation path). Same shape and
     * boundaries as {@see clusterTtpStixData}, scoped to one conversation.
     *
     * @return list<array{code: string, label: string, definition: string, phase: string, external_refs: list<array<string, mixed>>, count: int, first_seen: ?string, last_seen: ?string}>
     */
    public function conversationTtpStixData(string $convId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT t.code, t.label, t.definition, t.phase, t.external_refs,
                    COUNT(*) AS observation_count,
                    MIN(m.ts_msg) AS first_seen,
                    MAX(m.ts_msg) AS last_seen
             FROM ttp_observation o
             JOIN message m ON m.msg_id = o.msg_id AND m.deleted_at IS NULL
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             WHERE o.conv_id = :convId AND o.status = 'confirmed'
             GROUP BY t.code, t.label, t.definition, t.phase, t.external_refs
             ORDER BY observation_count DESC, t.code ASC",
            ['convId' => $convId]
        );

        return array_map(fn (array $row): array => $this->toStixTtpRow($row), $rows);
    }

    /**
     * Normalize a confirmed-aggregate DB row into the STIX export shape (decode
     * the external_refs JSONB, cast the count, keep timestamps raw).
     *
     * @param array<string, mixed> $row
     *
     * @return array{code: string, label: string, definition: string, phase: string, external_refs: list<array<string, mixed>>, count: int, first_seen: ?string, last_seen: ?string}
     */
    private function toStixTtpRow(array $row): array
    {
        return [
            'code' => \is_string($row['code'] ?? null) ? $row['code'] : '',
            'label' => \is_string($row['label'] ?? null) ? $row['label'] : '',
            'definition' => \is_string($row['definition'] ?? null) ? $row['definition'] : '',
            'phase' => \is_string($row['phase'] ?? null) ? $row['phase'] : '',
            'external_refs' => $this->decodeExternalRefs($row['external_refs'] ?? null),
            'count' => \is_numeric($row['observation_count'] ?? null) ? (int) $row['observation_count'] : 0,
            'first_seen' => \is_string($row['first_seen'] ?? null) ? $row['first_seen'] : null,
            'last_seen' => \is_string($row['last_seen'] ?? null) ? $row['last_seen'] : null,
        ];
    }

    /**
     * Decode the lkp_ttp.external_refs JSONB column into a list of ref arrays.
     *
     * @return list<array<string, mixed>>
     */
    private function decodeExternalRefs(mixed $raw): array
    {
        if (\is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (!\is_array($raw)) {
            return [];
        }

        $refs = [];

        foreach ($raw as $entry) {
            if (\is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $refs[] = $entry;
            }
        }

        return $refs;
    }

    /**
     * Adjacent-pair (bigram) TTP sequences across a cluster's conversations,
     * confirmed observations only, folded with the cross-boundary sequence
     * semantics of {@see foldCrossBoundaryBigrams}. Kept in PHP so the SQL
     * stays a plain ordered scan.
     *
     * @return list<array{sequence: list<string>, count: int}>
     */
    private function topSequences(string $clusterId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT m.conv_id, m.msg_id, t.code
             FROM threat_actor_cluster_conversation tacc
             JOIN conversation c ON c.conv_id = tacc.conv_id AND c.deleted_at IS NULL
             JOIN message m ON m.conv_id = tacc.conv_id AND m.deleted_at IS NULL
             JOIN ttp_observation o ON o.msg_id = m.msg_id AND o.status = 'confirmed'
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             WHERE tacc.cluster_id = :clusterId
             ORDER BY m.conv_id ASC, m.ts_msg ASC, m.msg_id ASC, t.code ASC",
            ['clusterId' => $clusterId]
        );

        $top = [];

        foreach ($this->rankedBigrams($this->foldCrossBoundaryBigrams($rows)) as $key => $pair) {
            $top[] = [
                'sequence' => explode('>', $key),
                'count' => $pair['count'],
            ];
        }

        return $top;
    }

    /**
     * Top adjacent-pair TTP sequences per group — threat-actor cluster or
     * scam type — confirmed observations only, message-timestamp axis,
     * soft-deletes excluded, folded with the cross-boundary sequence
     * semantics of {@see foldCrossBoundaryBigrams}. Support is the number of
     * distinct conversations a pair appears in (the sequence-mining meaning),
     * so a bigram that merely repeats inside one conversation never surfaces;
     * count reports the raw group-wide occurrences alongside it (a repeat
     * inside one conversation counts every time). Pairs seen in fewer than
     * {@see MIN_SEQUENCE_SUPPORT} conversations are dropped server-side and
     * groups left without any reportable pair are omitted. Surviving pairs
     * are ranked by occurrences (count DESC, key tiebreak); surviving groups
     * are ranked by the number of conversations contributing confirmed
     * observations (group key tiebreak) and capped at
     * {@see SEQUENCE_GROUPS_LIMIT} with an explicit truncation flag.
     *
     * Cluster groups follow the shared-playbook boundaries (non-merged
     * clusters, tac.name label with a cluster_id-prefix fallback); scam-type
     * groups are keyed by the lkp_scam_type code (conversations without a
     * scam type have no group and are excluded).
     *
     * @return array{groups: list<array{key: string, label: string, sequences: list<array{sequence: list<string>, count: int, conversation_count: int}>}>, min_support: int, truncated: bool}
     */
    public function sequences(string $group): array
    {
        $sql = match ($group) {
            'cluster' => "SELECT tac.cluster_id AS group_key, tac.name AS group_label,
                    m.conv_id, m.msg_id, t.code
             FROM threat_actor_cluster tac
             JOIN threat_actor_cluster_conversation tacc ON tacc.cluster_id = tac.cluster_id
             JOIN conversation c ON c.conv_id = tacc.conv_id AND c.deleted_at IS NULL
             JOIN message m ON m.conv_id = tacc.conv_id AND m.deleted_at IS NULL
             JOIN ttp_observation o ON o.msg_id = m.msg_id AND o.status = 'confirmed'
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             WHERE tac.status != 'merged'
             ORDER BY tac.cluster_id ASC, m.conv_id ASC, m.ts_msg ASC, m.msg_id ASC, t.code ASC",
            'scam_type' => "SELECT st.code AS group_key, st.label AS group_label,
                    m.conv_id, m.msg_id, t.code
             FROM ttp_observation o
             JOIN message m ON m.msg_id = o.msg_id AND m.deleted_at IS NULL
             JOIN conversation c ON c.conv_id = o.conv_id AND c.deleted_at IS NULL
             JOIN lkp_scam_type st ON st.scam_type_id = c.scam_type_id
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             WHERE o.status = 'confirmed'
             ORDER BY st.code ASC, m.conv_id ASC, m.ts_msg ASC, m.msg_id ASC, t.code ASC",
            default => throw new \InvalidArgumentException(sprintf('Unknown sequence group "%s"', $group)),
        };

        $rows = $this->connection->fetchAllAssociative($sql);

        /** @var array<string, list<array<string, mixed>>> $rowsByGroup */
        $rowsByGroup = [];
        /** @var array<string, string> $labels */
        $labels = [];
        /** @var array<string, array<string, true>> $convsByGroup */
        $convsByGroup = [];

        foreach ($rows as $row) {
            $key = \is_string($row['group_key'] ?? null) ? $row['group_key'] : '';
            $convId = \is_string($row['conv_id'] ?? null) ? $row['conv_id'] : '';

            if ($key === '' || $convId === '') {
                continue;
            }

            $label = \is_string($row['group_label'] ?? null) ? $row['group_label'] : '';
            $labels[$key] ??= $label !== '' ? $label : substr($key, 0, 8);
            $rowsByGroup[$key][] = $row;
            $convsByGroup[$key][$convId] = true;
        }

        $groups = [];

        foreach ($rowsByGroup as $key => $groupRows) {
            $pairs = array_filter(
                $this->foldCrossBoundaryBigrams($groupRows),
                static fn (array $pair): bool => \count($pair['conversations']) >= self::MIN_SEQUENCE_SUPPORT
            );

            if ($pairs === []) {
                continue;
            }

            $sequences = [];

            foreach ($this->rankedBigrams($pairs) as $pairKey => $pair) {
                $sequences[] = [
                    'sequence' => explode('>', $pairKey),
                    'count' => $pair['count'],
                    'conversation_count' => \count($pair['conversations']),
                ];
            }

            $groups[] = [
                'key' => $key,
                'label' => $labels[$key],
                'sequences' => $sequences,
            ];
        }

        // Widest conversation volume first, deterministic key tiebreak, then cap.
        usort($groups, static function (array $a, array $b) use ($convsByGroup): int {
            return [\count($convsByGroup[$b['key']]), $a['key']] <=> [\count($convsByGroup[$a['key']]), $b['key']];
        });

        $truncated = \count($groups) > self::SEQUENCE_GROUPS_LIMIT;

        return [
            'groups' => \array_slice($groups, 0, self::SEQUENCE_GROUPS_LIMIT),
            'min_support' => self::MIN_SEQUENCE_SUPPORT,
            'truncated' => $truncated,
        ];
    }

    /**
     * Global phase-transition aggregate: the same cross-boundary bigrams as
     * the sequence surfaces (confirmed-only, soft-deletes excluded,
     * message-timestamp axis) aggregated by the kill-chain phase of each
     * pair's endpoints, across every conversation. Zero transitions are
     * omitted (the consumer renders the dense matrix); cells are ordered by
     * canonical phase rank. total_pairs reports the full bigram volume so
     * shares can be derived honestly.
     *
     * Self-pairs are excluded at the CODE level (never the phase level), so
     * a hook → hook cell legitimately counts transitions between two
     * different hook-phase TTPs.
     *
     * @return array{transitions: list<array{from_phase: string, to_phase: string, count: int}>, total_pairs: int}
     */
    public function phaseTransitions(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT m.conv_id, m.msg_id, t.code, t.phase
             FROM ttp_observation o
             JOIN message m ON m.msg_id = o.msg_id AND m.deleted_at IS NULL
             JOIN conversation c ON c.conv_id = o.conv_id AND c.deleted_at IS NULL
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             WHERE o.status = 'confirmed'
             ORDER BY m.conv_id ASC, m.ts_msg ASC, m.msg_id ASC, t.code ASC"
        );

        /** @var array<string, string> $phaseByCode */
        $phaseByCode = [];

        foreach ($rows as $row) {
            $code = \is_string($row['code'] ?? null) ? $row['code'] : '';
            $phase = \is_string($row['phase'] ?? null) ? $row['phase'] : '';

            if ($code !== '' && $phase !== '') {
                $phaseByCode[$code] = $phase;
            }
        }

        /** @var array<string, int> $countsByPhasePair */
        $countsByPhasePair = [];
        $totalPairs = 0;

        foreach ($this->foldCrossBoundaryBigrams($rows) as $key => $pair) {
            [$from, $to] = explode('>', $key);
            $fromPhase = $phaseByCode[$from] ?? '';
            $toPhase = $phaseByCode[$to] ?? '';

            if ($fromPhase === '' || $toPhase === '') {
                continue;
            }

            $phaseKey = $fromPhase . '>' . $toPhase;
            $countsByPhasePair[$phaseKey] = ($countsByPhasePair[$phaseKey] ?? 0) + $pair['count'];
            $totalPairs += $pair['count'];
        }

        uksort($countsByPhasePair, function (string $a, string $b): int {
            [$fromA, $toA] = explode('>', $a);
            [$fromB, $toB] = explode('>', $b);

            return [$this->phaseRank($fromA), $this->phaseRank($toA), $a]
                <=> [$this->phaseRank($fromB), $this->phaseRank($toB), $b];
        });

        $transitions = [];

        foreach ($countsByPhasePair as $phaseKey => $count) {
            [$fromPhase, $toPhase] = explode('>', $phaseKey);
            $transitions[] = [
                'from_phase' => $fromPhase,
                'to_phase' => $toPhase,
                'count' => $count,
            ];
        }

        return [
            'transitions' => $transitions,
            'total_pairs' => $totalPairs,
        ];
    }

    /**
     * The single sequence fold: per conversation, confirmed observations are
     * grouped into per-message co-occurrence SETs (rows must arrive ordered
     * by ts_msg then msg_id within each conversation — intra-message order is
     * an alphabetical storage artifact, never a sequence signal), and bigram
     * pairs are emitted EXCLUSIVELY across adjacent message boundaries: every
     * (x, y) with x in set_i and y in set_{i+1}. Self-pairs (x → x) are
     * excluded as noise and no intra-message pair is ever emitted. Repeats
     * across non-adjacent messages recur naturally (no first-appearance
     * dedup).
     *
     * @param list<array<string, mixed>> $rows rows carrying conv_id, msg_id and code
     *
     * @return array<string, array{count: int, conversations: array<string, true>}> keyed "from>to"
     */
    private function foldCrossBoundaryBigrams(array $rows): array
    {
        /** @var array<string, list<array<string, true>>> $setsByConv */
        $setsByConv = [];
        /** @var array<string, string> $lastMsgByConv */
        $lastMsgByConv = [];

        foreach ($rows as $row) {
            $convId = \is_string($row['conv_id'] ?? null) ? $row['conv_id'] : '';
            $msgId = \is_string($row['msg_id'] ?? null) ? $row['msg_id'] : '';
            $code = \is_string($row['code'] ?? null) ? $row['code'] : '';

            if ($convId === '' || $msgId === '' || $code === '') {
                continue;
            }

            if (($lastMsgByConv[$convId] ?? null) !== $msgId) {
                $setsByConv[$convId][] = [];
                $lastMsgByConv[$convId] = $msgId;
            }

            $setsByConv[$convId][\count($setsByConv[$convId]) - 1][$code] = true;
        }

        /** @var array<string, array{count: int, conversations: array<string, true>}> $pairs */
        $pairs = [];

        foreach ($setsByConv as $convId => $sets) {
            for ($i = 1, $len = \count($sets); $i < $len; ++$i) {
                foreach (array_keys($sets[$i - 1]) as $from) {
                    foreach (array_keys($sets[$i]) as $to) {
                        if ($from === $to) {
                            continue;
                        }

                        $key = $from . '>' . $to;
                        $pairs[$key] ??= ['count' => 0, 'conversations' => []];
                        ++$pairs[$key]['count'];
                        $pairs[$key]['conversations'][$convId] = true;
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * Order folded bigrams deterministically (count DESC, pair key ASC) and
     * keep the top {@see TOP_SEQUENCES_LIMIT}, keys preserved.
     *
     * @param array<string, array{count: int, conversations: array<string, true>}> $pairs
     *
     * @return array<string, array{count: int, conversations: array<string, true>}>
     */
    private function rankedBigrams(array $pairs): array
    {
        uksort($pairs, static function (string $a, string $b) use ($pairs): int {
            return [$pairs[$b]['count'], $a] <=> [$pairs[$a]['count'], $b];
        });

        return \array_slice($pairs, 0, self::TOP_SEQUENCES_LIMIT, true);
    }

    /**
     * Decode a JSONB list of strings (lkp_ttp.examples) into a PHP list,
     * dropping anything that is not a string.
     *
     * @return list<string>
     */
    private function decodeStringList(mixed $raw): array
    {
        if (\is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (!\is_array($raw)) {
            return [];
        }

        $values = [];

        foreach ($raw as $entry) {
            if (\is_string($entry)) {
                $values[] = $entry;
            }
        }

        return $values;
    }

    /**
     * Start of the current ISO week (Monday 00:00 UTC), matching how
     * Postgres DATE_TRUNC('week', ...) buckets the message timestamps.
     */
    private function currentWeekStart(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $isoDayOfWeek = (int) $now->format('N');

        return $now->setTime(0, 0)->modify(sprintf('-%d days', $isoDayOfWeek - 1));
    }

    /**
     * Rank a phase by its position in the canonical kill-chain order; an unknown
     * phase sorts after all known ones so it is never silently dropped.
     */
    private function phaseRank(string $phase): int
    {
        $index = array_search($phase, self::PHASE_ORDER, true);

        return $index === false ? \count(self::PHASE_ORDER) : $index;
    }

    /**
     * TTP observations of a conversation grouped by message id (timeline shape).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function ttpsByMessage(string $convId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT o.msg_id, t.code AS ttp_code, t.phase, o.confidence, o.status,
                    o.evidence_start, o.evidence_end
             FROM ttp_observation o
             JOIN message m ON m.msg_id = o.msg_id AND m.deleted_at IS NULL
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             WHERE o.conv_id = :convId
             ORDER BY m.ts_msg ASC, o.msg_id ASC, t.code ASC',
            ['convId' => $convId]
        );

        $byMsg = [];

        foreach ($rows as $row) {
            $msgId = \is_string($row['msg_id'] ?? null) ? $row['msg_id'] : '';

            if ($msgId === '') {
                continue;
            }

            $byMsg[$msgId][] = [
                'ttp_code' => \is_string($row['ttp_code'] ?? null) ? $row['ttp_code'] : '',
                'phase' => \is_string($row['phase'] ?? null) ? $row['phase'] : '',
                'confidence' => \is_numeric($row['confidence'] ?? null) ? (float) $row['confidence'] : 0.0,
                'status' => \is_string($row['status'] ?? null) ? $row['status'] : '',
                'evidence_start' => \is_numeric($row['evidence_start'] ?? null) ? (int) $row['evidence_start'] : null,
                'evidence_end' => \is_numeric($row['evidence_end'] ?? null) ? (int) $row['evidence_end'] : null,
            ];
        }

        return $byMsg;
    }

    /**
     * IOCs revealed per message of a conversation (type + normalized value
     * from the observation context), restricted to actionable IOC types so
     * transport noise (SPF/DKIM results, header metadata) never clutters the
     * timeline. Each entry carries the canonical indicator reference and —
     * when an enriched ioc_context row exists (1:1 on obs_id) — the outbound
     * message the revelation was attributed to (stimulus_msg_id, NULL-safe).
     *
     * @return array<string, list<array{type: string, value_norm: string, indicator_id: ?string, stimulus_msg_id: ?string}>>
     */
    private function iocsByMessage(string $convId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT oi.msg_id, oi.context_observation->>'type' AS type,
                    oi.context_observation->>'value_norm' AS value_norm,
                    oi.indicator_id::text AS indicator_id,
                    ic.stimulus_msg_id::text AS stimulus_msg_id
             FROM observed_ioc oi
             JOIN message m ON m.msg_id = oi.msg_id
             LEFT JOIN ioc_context ic ON ic.obs_id = oi.obs_id AND ic.enrichment_status = 'enriched'
             WHERE m.conv_id = :convId AND m.deleted_at IS NULL
             ORDER BY oi.ts_observed ASC, oi.obs_id ASC",
            ['convId' => $convId]
        );

        $byMsg = [];

        foreach ($rows as $row) {
            $msgId = \is_string($row['msg_id'] ?? null) ? $row['msg_id'] : '';

            if ($msgId === '') {
                continue;
            }

            $type = \is_string($row['type'] ?? null) ? $row['type'] : '';

            if (!IocActionablePolicy::isActionable($type)) {
                continue;
            }

            $byMsg[$msgId][] = [
                'type' => $type,
                'value_norm' => \is_string($row['value_norm'] ?? null) ? $row['value_norm'] : '',
                'indicator_id' => \is_string($row['indicator_id'] ?? null) ? $row['indicator_id'] : null,
                'stimulus_msg_id' => \is_string($row['stimulus_msg_id'] ?? null) ? $row['stimulus_msg_id'] : null,
            ];
        }

        return $byMsg;
    }

    /**
     * Dominant (modal) stimulus type per stimulus message: enriched
     * ioc_context rows of the conversation's IOCs, keyed by the outbound
     * message the enrichment attributed the revelation to.
     *
     * @return array<string, string>
     */
    private function stimulusByMessage(string $convId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT ic.stimulus_msg_id::text AS stimulus_msg_id,
                    MODE() WITHIN GROUP (ORDER BY ic.stimulus_type) AS stimulus_type
             FROM ioc_context ic
             JOIN observed_ioc oi ON oi.obs_id = ic.obs_id
             JOIN message m ON m.msg_id = oi.msg_id
             WHERE m.conv_id = :convId AND m.deleted_at IS NULL
               AND ic.enrichment_status = 'enriched'
               AND ic.stimulus_msg_id IS NOT NULL
               AND ic.stimulus_type IS NOT NULL
             GROUP BY ic.stimulus_msg_id",
            ['convId' => $convId]
        );

        $byMsg = [];

        foreach ($rows as $row) {
            $msgId = \is_string($row['stimulus_msg_id'] ?? null) ? $row['stimulus_msg_id'] : '';
            $stimulus = \is_string($row['stimulus_type'] ?? null) ? $row['stimulus_type'] : '';

            if ($msgId !== '' && $stimulus !== '') {
                $byMsg[$msgId] = $stimulus;
            }
        }

        return $byMsg;
    }

    /**
     * ts_msg is stored as UTC wall-clock (timestamp without time zone);
     * render it as an ISO-8601 string for the API.
     */
    private function toIso(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($raw, new \DateTimeZone('UTC')))->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}

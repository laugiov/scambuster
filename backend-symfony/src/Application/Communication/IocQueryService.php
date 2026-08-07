<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\ObservedIoc;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handles all read-only IOC queries: list, detail, co-occurrence, conversation IOCs.
 *
 * Extracted from IocHandler (CT-0 decomposition).
 */
class IocQueryService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IocExportMapper $exportMapper,
    ) {
    }

    /**
     * Get deduplicated IOCs for a conversation.
     *
     * @return array<ObservedIoc>
     */
    public function getConversationIocs(string $convId, bool $actionableOnly = false): array
    {
        // ORDER BY message.ts_msg ASC, then ts_observed ASC, so the
        // FIRST occurrence of an indicator in conversation-time wins
        // on dedup. The "money shot" displays the IOC reveal
        // turn — must be the turn it first appeared, not the most
        // recent repetition. Bug surfaced when a BIC code was quoted
        // in two consecutive scammer messages: the panel showed the
        // BIC at turn 10/13 (repetition) while the BANK_ACCOUNT it
        // was alongside in the original message showed at turn 8/13.
        $qb = $this->em->createQueryBuilder();
        $qb->select('ioc')
            ->from(ObservedIoc::class, 'ioc')
            ->join('ioc.message', 'm')
            ->where('m.conversation = :convId')
            ->setParameter('convId', $convId)
            ->orderBy('m.tsMsg', 'ASC')
            ->addOrderBy('ioc.tsObserved', 'ASC');

        /** @var array<ObservedIoc> $allIocs */
        $allIocs = $qb->getQuery()->getResult();

        /** @var array<ObservedIoc> $unique */
        $unique = [];
        /** @var array<string, true> $seenIds */
        $seenIds = [];

        foreach ($allIocs as $ioc) {
            $iocId = $ioc->getIndicatorId();

            if (!isset($seenIds[$iocId])) {
                $seenIds[$iocId] = true;
                $unique[] = $ioc;
            }
        }

        // Optional actionable-only filter. When true, drop
        // every indicator whose type is in the IocActionablePolicy's
        // non-actionable set (header metadata, file hashes, auth
        // results, reference identifiers). When false (default),
        // preserve legacy behaviour so existing callers that need the
        // full picture (Theater enrichment overlays, audit) are
        // unaffected.
        if ($actionableOnly) {
            $unique = array_values(array_filter(
                $unique,
                static function (ObservedIoc $ioc): bool {
                    $type = $ioc->getContext()['type'] ?? null;

                    return \is_string($type)
                        && \App\Domain\Communication\Policy\IocActionablePolicy::isActionable($type);
                },
            ));
        }

        return $unique;
    }

    /**
     * Get all IOCs with confidence scoring, optionally filtered by min_score.
     *
     * @param float|null $minScore Minimum effective_score to include (0.0-1.0)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllIocsWithConfidence(?float $minScore = null): array
    {
        $conn = $this->em->getConnection();

        $sql = '
            SELECT
                oi.obs_id,
                oi.indicator_id AS ioc_id,
                oi.context_observation,
                oi.ts_observed,
                oi.confidence_score,
                i.type AS indicator_type,
                i.last_seen AS indicator_last_seen,
                st.code AS scam_type_code,
                CASE WHEN ic.id IS NOT NULL AND ic.enrichment_status IN (\'structural\', \'enriched\') THEN true ELSE false END AS has_context
            FROM observed_ioc oi
            LEFT JOIN indicator i ON oi.indicator_id = i.indicator_id
            LEFT JOIN message m ON oi.msg_id = m.msg_id
            LEFT JOIN conversation c ON m.conv_id = c.conv_id
            LEFT JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
            LEFT JOIN ioc_context ic ON oi.obs_id = ic.obs_id
            ORDER BY oi.ts_observed DESC
        ';

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $conn->executeQuery($sql)->fetchAllAssociative();

        $result = [];

        foreach ($rows as $row) {
            $contextRaw = $row['context_observation'];
            $context = is_string($contextRaw) ? json_decode($contextRaw, true) : [];

            if (!is_array($context)) {
                $context = [];
            }

            $confScoreRaw = $row['confidence_score'];
            $confidenceRaw = is_numeric($confScoreRaw) ? (float) $confScoreRaw : 0.80;
            $iocType = is_string($row['indicator_type']) ? $row['indicator_type'] : (is_string($context['type'] ?? null) ? $context['type'] : 'unknown');
            $tsObservedRaw = $row['ts_observed'];
            $lastSeenStr = is_string($row['indicator_last_seen']) ? $row['indicator_last_seen'] : (is_string($tsObservedRaw) ? $tsObservedRaw : '');

            try {
                $lastSeen = new \DateTimeImmutable($lastSeenStr);
            } catch (\Exception) {
                $lastSeen = new \DateTimeImmutable();
            }

            $decayFactor = IocConfidenceCalculator::computeDecayFactor($iocType, $lastSeen);
            $effectiveScore = round($confidenceRaw * $decayFactor, 4);

            if ($minScore !== null && $effectiveScore < $minScore) {
                continue;
            }

            $scamTypeCode = is_string($row['scam_type_code'] ?? null) ? $row['scam_type_code'] : null;
            $displayCategory = $scamTypeCode ?? (is_string($context['category'] ?? null) ? $context['category'] : '');

            $scoreArr = is_array($context['score'] ?? null) ? $context['score'] : [];
            $vtScore = is_numeric($scoreArr['vt'] ?? null) ? (int) $scoreArr['vt'] : 0;
            $urlscanScore = is_numeric($scoreArr['urlscan'] ?? null) ? (int) $scoreArr['urlscan'] : 0;

            $result[] = [
                'obs_id' => $row['obs_id'],
                'ioc_id' => $row['ioc_id'],
                'type' => $context['type'] ?? '',
                'value' => $context['value'] ?? '',
                'value_norm' => $context['value_norm'] ?? '',
                'score' => $scoreArr,
                'category' => $displayCategory,
                'ts_observed' => $this->normalizeTimestamp($row['ts_observed']),
                'confidence' => round($confidenceRaw, 4),
                'decay_factor' => round($decayFactor, 4),
                'effective_score' => $effectiveScore,
                'severity' => IocConfidenceCalculator::computeSeverity($iocType, $vtScore, $urlscanScore),
                'has_context' => !empty($row['has_context']) && $row['has_context'] !== 'f',
            ];
        }

        return $result;
    }

    /**
     * Get detailed information for a single indicator.
     *
     * @param string $indicatorId Indicator UUID
     *
     * @throws \RuntimeException If indicator not found
     *
     * @return array<string, mixed>
     */
    public function getIocDetail(string $indicatorId): array
    {
        $conn = $this->em->getConnection();

        // 1. Get indicator base data
        $indicator = $conn->executeQuery(
            'SELECT * FROM indicator WHERE indicator_id = :id',
            ['id' => $indicatorId]
        )->fetchAssociative();

        if (!$indicator) {
            throw new \RuntimeException("Indicator not found: {$indicatorId}");
        }

        $type = is_string($indicator['type']) ? $indicator['type'] : 'unknown';
        $value = is_string($indicator['value']) ? $indicator['value'] : '';
        $valueNorm = is_string($indicator['value_norm']) ? $indicator['value_norm'] : '';
        $firstSeen = is_string($indicator['first_seen']) ? $indicator['first_seen'] : '';
        $lastSeen = is_string($indicator['last_seen']) ? $indicator['last_seen'] : $firstSeen;
        $occurrences = is_numeric($indicator['occurrences']) ? (int) $indicator['occurrences'] : 1;
        $tlp = is_string($indicator['tlp']) ? $indicator['tlp'] : 'AMBER';
        $enrichmentRaw = is_string($indicator['enrichment']) ? $indicator['enrichment'] : '{}';
        $scoreRaw = is_string($indicator['score']) ? $indicator['score'] : '{}';

        /** @var array<string, mixed> $enrichment */
        $enrichment = json_decode($enrichmentRaw, true) ?: [];
        /** @var array<string, mixed> $score */
        $score = json_decode($scoreRaw, true) ?: [];

        // 2. Compute confidence/decay
        try {
            $lastSeenDt = new \DateTimeImmutable($lastSeen);
        } catch (\Exception) {
            $lastSeenDt = new \DateTimeImmutable();
        }

        $baseConfidence = IocConfidenceCalculator::getBaseConfidence('unknown');
        // Corroboration = distinct conversations that observed the value, not the
        // raw `occurrences` count (which a single adversary can inflate).
        $distinctSourcesRow = $conn->fetchOne(
            'SELECT COUNT(DISTINCT m.conv_id)
             FROM observed_ioc oi JOIN message m ON oi.msg_id = m.msg_id
             WHERE oi.indicator_id = :id',
            ['id' => $indicatorId],
        );
        $distinctSources = is_numeric($distinctSourcesRow) ? (int) $distinctSourcesRow : 1;
        $confidence = IocConfidenceCalculator::boostConfidence($baseConfidence, $distinctSources);
        $decayFactor = IocConfidenceCalculator::computeDecayFactor($type, $lastSeenDt);
        $effectiveScore = round($confidence * $decayFactor, 4);

        // 3. Get MISP/STIX mappings
        $exportContext = $this->exportMapper->enrichWithExportMetadata([
            'type' => $type,
            'value' => $value,
            'value_norm' => $valueNorm,
        ]);

        // 4. Get category from parent conversation scam type
        $scamTypeRow = $conn->executeQuery(
            'SELECT st.code FROM observed_ioc oi
             JOIN message m ON oi.msg_id = m.msg_id
             JOIN conversation c ON m.conv_id = c.conv_id
             LEFT JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
             WHERE oi.indicator_id = :id
             ORDER BY oi.ts_observed DESC LIMIT 1',
            ['id' => $indicatorId]
        )->fetchOne();

        $category = is_string($scamTypeRow) ? $scamTypeRow : 'Unknown';

        // 5. Get observations with conversation context
        $observations = $conn->executeQuery(
            'SELECT
                oi.obs_id,
                oi.msg_id,
                oi.ts_observed,
                oi.confidence_score,
                oi.context_observation,
                m.subject AS msg_subject,
                c.conv_id,
                c.status AS conv_status,
                st.code AS scam_type_code
            FROM observed_ioc oi
            JOIN message m ON oi.msg_id = m.msg_id
            JOIN conversation c ON m.conv_id = c.conv_id
            LEFT JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
            WHERE oi.indicator_id = :indicatorId
            ORDER BY oi.ts_observed DESC',
            ['indicatorId' => $indicatorId]
        )->fetchAllAssociative();

        $formattedObservations = [];

        foreach ($observations as $obs) {
            $obsContext = is_string($obs['context_observation']) ? json_decode($obs['context_observation'], true) : [];
            $extractionMethod = 'unknown';

            if (is_array($obsContext)) {
                if (is_string($obsContext['extraction_method'] ?? null)) {
                    $extractionMethod = $obsContext['extraction_method'];
                } elseif (is_string($obsContext['source'] ?? null)) {
                    $extractionMethod = $obsContext['source'];
                }
            }

            $formattedObservations[] = [
                'obs_id' => $obs['obs_id'],
                'msg_id' => $obs['msg_id'],
                'conv_id' => $obs['conv_id'],
                'conv_subject' => is_string($obs['msg_subject']) ? $obs['msg_subject'] : null,
                'conv_status' => is_string($obs['conv_status']) ? $obs['conv_status'] : 'unknown',
                'conv_scam_type' => is_string($obs['scam_type_code']) ? $obs['scam_type_code'] : 'unknown',
                'extraction_method' => $extractionMethod,
                'ts_observed' => $this->normalizeTimestamp($obs['ts_observed']),
                // Integrity flags recorded at ingest (null for older rows).
                'grounded' => \is_array($obsContext) && \is_bool($obsContext['grounded'] ?? null) ? $obsContext['grounded'] : null,
                'valid' => \is_array($obsContext) && \is_bool($obsContext['valid'] ?? null) ? $obsContext['valid'] : null,
            ];
        }

        // 6. Get related IOCs (co-occurring in same conversations).
        // Excludes header IOC types — they're authentication metadata, not real
        // attacker-controlled artifacts. Must match the same filter applied in
        // getCoOccurrenceGraph() so the badge count matches the graph node count.
        $relatedIocs = $conn->executeQuery(
            "SELECT
                i.indicator_id,
                i.type,
                i.value_norm,
                i.score::text AS score,
                COUNT(DISTINCT c.conv_id) AS co_occurrence_count
            FROM observed_ioc oi_other
            JOIN indicator i ON oi_other.indicator_id = i.indicator_id
            JOIN message m ON oi_other.msg_id = m.msg_id
            JOIN conversation c ON m.conv_id = c.conv_id
            WHERE c.conv_id IN (
                SELECT DISTINCT c2.conv_id
                FROM observed_ioc oi2
                JOIN message m2 ON oi2.msg_id = m2.msg_id
                JOIN conversation c2 ON m2.conv_id = c2.conv_id
                WHERE oi2.indicator_id = :indicatorId
            )
            AND i.indicator_id != :indicatorId
            AND i.type NOT IN ('message_id','subject','spf_result','dkim_result','dmarc_result','x_mailer','return_path')
            GROUP BY i.indicator_id, i.type, i.value_norm, i.score::text
            ORDER BY co_occurrence_count DESC
            LIMIT 50",
            ['indicatorId' => $indicatorId]
        )->fetchAllAssociative();

        $formattedRelated = [];

        foreach ($relatedIocs as $rel) {
            $relScore = is_string($rel['score']) ? json_decode($rel['score'], true) : [];

            $formattedRelated[] = [
                'indicator_id' => $rel['indicator_id'],
                'type' => is_string($rel['type']) ? $rel['type'] : 'unknown',
                'value_norm' => is_string($rel['value_norm']) ? $rel['value_norm'] : '',
                'score' => is_array($relScore) ? $relScore : [],
                'co_occurrence_count' => is_numeric($rel['co_occurrence_count']) ? (int) $rel['co_occurrence_count'] : 0,
            ];
        }

        return [
            'indicator_id' => $indicatorId,
            'type' => $type,
            'value' => $value,
            'value_norm' => $valueNorm,
            'first_seen' => $firstSeen,
            'last_seen' => $lastSeen,
            'occurrences' => $occurrences,
            'tlp' => $tlp,
            'enrichment' => $enrichment,
            'score' => $score,
            'confidence' => round($confidence, 4),
            'decay_factor' => round($decayFactor, 4),
            'effective_score' => $effectiveScore,
            'category' => $category,
            'misp' => $exportContext['misp'] ?? null,
            'stix' => $exportContext['stix'] ?? null,
            'observations' => $formattedObservations,
            'related_iocs' => $formattedRelated,
        ];
    }

    /**
     * Get co-occurrence graph data for an indicator.
     *
     * @param string $indicatorId Center indicator UUID
     * @param int    $maxNodes    Maximum related nodes to return
     *
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    public function getCoOccurrenceGraph(string $indicatorId, int $maxNodes = 30): array
    {
        $conn = $this->em->getConnection();

        $center = $conn->executeQuery(
            'SELECT indicator_id, type, value, value_norm, score::text AS score FROM indicator WHERE indicator_id = :id',
            ['id' => $indicatorId]
        )->fetchAssociative();

        if (!$center) {
            return ['nodes' => [], 'edges' => []];
        }

        $headerTypes = "'message_id','subject','spf_result','dkim_result','dmarc_result','x_mailer','return_path'";

        $rows = $conn->executeQuery(
            "SELECT
                i.indicator_id,
                i.type,
                i.value_norm,
                i.score::text AS score,
                COUNT(DISTINCT c.conv_id) AS weight,
                array_agg(DISTINCT c.conv_id::text) AS conv_ids
            FROM observed_ioc oi
            JOIN indicator i ON oi.indicator_id = i.indicator_id
            JOIN message m ON oi.msg_id = m.msg_id
            JOIN conversation c ON m.conv_id = c.conv_id
            WHERE c.conv_id IN (
                SELECT DISTINCT c2.conv_id
                FROM observed_ioc oi2
                JOIN message m2 ON oi2.msg_id = m2.msg_id
                JOIN conversation c2 ON m2.conv_id = c2.conv_id
                WHERE oi2.indicator_id = :indicatorId
            )
            AND i.indicator_id != :indicatorId
            AND i.type NOT IN ({$headerTypes})
            GROUP BY i.indicator_id, i.type, i.value_norm, i.score::text
            ORDER BY weight DESC, i.type
            LIMIT :maxNodes",
            ['indicatorId' => $indicatorId, 'maxNodes' => $maxNodes],
            ['indicatorId' => \Doctrine\DBAL\ParameterType::STRING, 'maxNodes' => \Doctrine\DBAL\ParameterType::INTEGER]
        )->fetchAllAssociative();

        $centerScore = is_string($center['score']) ? json_decode($center['score'], true) : [];
        $nodes = [
            [
                'id' => $indicatorId,
                'type' => is_string($center['type']) ? $center['type'] : 'unknown',
                'value' => is_string($center['value_norm']) ? $center['value_norm'] : '',
                'score' => is_array($centerScore) ? ($centerScore['agg'] ?? 0) : 0,
                'center' => true,
            ],
        ];

        $edges = [];

        foreach ($rows as $row) {
            $relScore = is_string($row['score']) ? json_decode($row['score'], true) : [];
            $convIdsRaw = is_string($row['conv_ids']) ? $row['conv_ids'] : '{}';
            $convIds = array_filter(explode(',', trim($convIdsRaw, '{}')));

            $nodes[] = [
                'id' => $row['indicator_id'],
                'type' => is_string($row['type']) ? $row['type'] : 'unknown',
                'value' => is_string($row['value_norm']) ? $row['value_norm'] : '',
                'score' => is_array($relScore) ? ($relScore['agg'] ?? 0) : 0,
                'center' => false,
            ];

            $edges[] = [
                'source' => $indicatorId,
                'target' => $row['indicator_id'],
                'weight' => is_numeric($row['weight']) ? (int) $row['weight'] : 1,
                'conversations' => $convIds,
            ];
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Compute confidence data for a single ObservedIoc.
     *
     * @return array{confidence: float, decay_factor: float, effective_score: float}
     */
    public function computeConfidenceData(string $indicatorId, ?float $confidenceScore, \DateTimeImmutable $tsObserved): array
    {
        $conn = $this->em->getConnection();
        $confidence = $confidenceScore ?? 0.80;

        $indicatorRow = $conn->executeQuery(
            'SELECT type, last_seen FROM indicator WHERE indicator_id = :id',
            ['id' => $indicatorId]
        )->fetchAssociative();

        if (is_array($indicatorRow) && is_string($indicatorRow['type'])) {
            $iocType = $indicatorRow['type'];
            $lastSeenStr = is_string($indicatorRow['last_seen']) ? $indicatorRow['last_seen'] : $tsObserved->format('Y-m-d H:i:s');
        } else {
            $iocType = 'unknown';
            $lastSeenStr = $tsObserved->format('Y-m-d H:i:s');
        }

        try {
            $lastSeen = new \DateTimeImmutable($lastSeenStr);
        } catch (\Exception) {
            $lastSeen = new \DateTimeImmutable();
        }

        $decayFactor = IocConfidenceCalculator::computeDecayFactor($iocType, $lastSeen);
        $effectiveScore = round($confidence * $decayFactor, 4);

        return [
            'confidence' => round($confidence, 4),
            'decay_factor' => round($decayFactor, 4),
            'effective_score' => $effectiveScore,
        ];
    }

    /**
     * Normalize a PG timestamp (Y-m-d H:i:s without TZ) to ISO 8601 with
     * UTC timezone indicator so the frontend converts to local time correctly.
     */
    private function normalizeTimestamp(mixed $raw): string
    {
        if (!is_string($raw) || $raw === '') {
            return '';
        }

        if (str_contains($raw, '+') || str_ends_with($raw, 'Z')) {
            return $raw; // already has timezone
        }

        try {
            return (new \DateTimeImmutable($raw, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return $raw;
        }
    }
}

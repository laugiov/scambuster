<?php

declare(strict_types=1);

namespace App\Application\Stix;

use Doctrine\ORM\EntityManagerInterface;

final class IocStixExportHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StixBundleBuilder $bundleBuilder,
    ) {
    }

    /**
     * @param array<int, string> $indicatorIds
     *
     * @return array<string, mixed>
     */
    public function export(array $indicatorIds, string $tlp = 'AMBER'): array
    {
        // Cap at 500 indicators per export
        $indicatorIds = \array_slice($indicatorIds, 0, 500);

        $conn = $this->em->getConnection();

        // Fetch indicator + observation data for each requested ID
        $placeholders = implode(',', array_fill(0, \count($indicatorIds), '?'));
        $rows = $conn->executeQuery(
            "SELECT
                i.indicator_id,
                i.type,
                i.value,
                i.value_norm,
                i.first_seen,
                i.last_seen,
                i.score::text AS score,
                i.tlp,
                oi.confidence_score,
                oi.context_observation,
                st.code AS scam_type_code,
                ic.enrichment_status AS ctx_enrichment_status,
                ic.scam_type_code AS ctx_scam_type_code,
                ic.scam_type_attck AS ctx_scam_type_attck,
                ic.persona_code AS ctx_persona_code,
                ic.extraction_method AS ctx_extraction_method,
                ic.revelation_turn AS ctx_revelation_turn,
                ic.revelation_turn_ratio AS ctx_revelation_turn_ratio,
                ic.total_turns AS ctx_total_turns,
                ic.engagement_hours AS ctx_engagement_hours,
                ic.co_revealed_types AS ctx_co_revealed_types,
                ic.semantic_role AS ctx_semantic_role,
                ic.stimulus_type AS ctx_stimulus_type,
                ic.urgency_score AS ctx_urgency_score,
                ic.context_excerpt AS ctx_context_excerpt,
                ic.enrichment_confidence AS ctx_enrichment_confidence
            FROM indicator i
            LEFT JOIN observed_ioc oi ON i.indicator_id = oi.indicator_id
            LEFT JOIN message m ON oi.msg_id = m.msg_id
            LEFT JOIN conversation c ON m.conv_id = c.conv_id
            LEFT JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
            LEFT JOIN ioc_context ic ON oi.obs_id = ic.obs_id
            WHERE i.indicator_id IN ({$placeholders})
            ORDER BY i.first_seen DESC",
            array_values($indicatorIds)
        )->fetchAllAssociative();

        // Deduplicate by indicator_id (multiple observations -> keep first)
        $seen = [];
        $iocs = [];

        foreach ($rows as $row) {
            $indId = is_string($row['indicator_id']) ? $row['indicator_id'] : '';

            if (isset($seen[$indId])) {
                continue;
            }

            $seen[$indId] = true;

            $context = is_string($row['context_observation']) ? json_decode($row['context_observation'], true) : [];

            if (!is_array($context)) {
                $context = [];
            }

            $scoreData = is_string($row['score']) ? json_decode($row['score'], true) : [];

            $iocs[] = [
                'indicator_id' => $indId,
                'type' => is_string($row['type']) ? $row['type'] : (is_string($context['type'] ?? null) ? $context['type'] : 'unknown'),
                'value' => is_string($row['value']) ? $row['value'] : '',
                'value_norm' => is_string($row['value_norm']) ? $row['value_norm'] : '',
                'first_seen' => is_string($row['first_seen']) ? $row['first_seen'] : '',
                'last_seen' => is_string($row['last_seen']) ? $row['last_seen'] : '',
                'confidence' => is_numeric($row['confidence_score']) ? (float) $row['confidence_score'] : null,
                'extraction_method' => is_string($context['extraction_method'] ?? null) ? $context['extraction_method'] : (is_string($context['source'] ?? null) ? $context['source'] : 'unknown'),
                'score' => is_array($scoreData) ? $scoreData : [],
                'scam_type' => is_string($row['scam_type_code']) ? $row['scam_type_code'] : null,
                'context' => $this->extractContextRow($row),
            ];
        }

        if (empty($iocs)) {
            return $this->bundleBuilder->buildBundle([], [], $tlp, 'ScamBuster IOC Export (empty)');
        }

        // Build relationships: find co-occurrence among selected IOCs
        $relationships = $this->buildRelationships($indicatorIds, $iocs);

        return $this->bundleBuilder->buildBundle(
            $iocs,
            $relationships,
            $tlp,
            sprintf('ScamBuster IOC Export - %d indicators', \count($iocs)),
            sprintf('Exported %d indicators from ScamBuster IOC Explorer', \count($iocs)),
        );
    }

    /**
     * Build co-occurrence relationships among selected indicator IDs.
     *
     * @param array<int, string>               $indicatorIds
     * @param array<int, array<string, mixed>> $iocs
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildRelationships(array $indicatorIds, array $iocs): array
    {
        if (\count($indicatorIds) < 2) {
            return [];
        }

        $conn = $this->em->getConnection();
        $placeholders = implode(',', array_fill(0, \count($indicatorIds), '?'));

        $pairs = $conn->executeQuery(
            "SELECT
                oi1.indicator_id AS source_id,
                oi2.indicator_id AS target_id,
                COUNT(DISTINCT c.conv_id) AS weight
            FROM observed_ioc oi1
            JOIN message m1 ON oi1.msg_id = m1.msg_id
            JOIN conversation c ON m1.conv_id = c.conv_id
            JOIN message m2 ON m2.conv_id = c.conv_id
            JOIN observed_ioc oi2 ON oi2.msg_id = m2.msg_id
            WHERE oi1.indicator_id IN ({$placeholders})
              AND oi2.indicator_id IN ({$placeholders})
              AND oi1.indicator_id < oi2.indicator_id
            GROUP BY oi1.indicator_id, oi2.indicator_id
            LIMIT 100",
            array_merge($indicatorIds, $indicatorIds)
        )->fetchAllAssociative();

        $iocMap = [];

        foreach ($iocs as $ioc) {
            $iocMap[is_string($ioc['indicator_id']) ? $ioc['indicator_id'] : ''] = $ioc;
        }

        $relationships = [];

        foreach ($pairs as $pair) {
            $sourceId = is_string($pair['source_id']) ? $pair['source_id'] : '';
            $targetId = is_string($pair['target_id']) ? $pair['target_id'] : '';
            $source = $iocMap[$sourceId] ?? null;
            $target = $iocMap[$targetId] ?? null;

            if ($source === null || $target === null) {
                continue;
            }

            $relationships[] = [
                'source_indicator_id' => $sourceId,
                'target_indicator_id' => $targetId,
                'source_type' => is_string($source['type']) ? $source['type'] : 'unknown',
                'source_value_norm' => is_string($source['value_norm']) ? $source['value_norm'] : '',
                'target_type' => is_string($target['type']) ? $target['type'] : 'unknown',
                'target_value_norm' => is_string($target['value_norm']) ? $target['value_norm'] : '',
                'weight' => is_numeric($pair['weight']) ? (int) $pair['weight'] : 1,
            ];
        }

        return $relationships;
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
        $status = is_string($row['ctx_enrichment_status'] ?? null) ? $row['ctx_enrichment_status'] : null;

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

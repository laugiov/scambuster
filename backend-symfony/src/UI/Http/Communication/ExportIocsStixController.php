<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Stix\StixBundleBuilder;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Export a selection of IOCs as a STIX 2.1 bundle (OpenCTI-compatible).
 *
 * Accepts indicator_ids from the frontend (filtered IOC Explorer list)
 * and builds a bundle with indicators + co-occurrence relationships.
 */
#[IsGranted('ioc:export')]
final class ExportIocsStixController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StixBundleBuilder $bundleBuilder,
    ) {
    }

    #[OA\Post(
        path: '/api/v1/iocs/export/stix',
        summary: 'Export selected IOCs as STIX 2.1 bundle',
        tags: ['Export'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(
                        property: 'indicator_ids',
                        type: 'array',
                        items: new OA\Items(type: 'string', format: 'uuid'),
                        description: 'List of indicator UUIDs to export (from IOC Explorer)'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'STIX 2.1 bundle JSON'),
            new OA\Response(response: 400, description: 'Missing or empty indicator_ids'),
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('/api/v1/iocs/export/stix', name: 'export_iocs_stix', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<int, string> $indicatorIds */
        $indicatorIds = [];

        if (isset($data['indicator_ids']) && is_array($data['indicator_ids'])) {
            $indicatorIds = array_filter($data['indicator_ids'], 'is_string');
        }

        if (empty($indicatorIds)) {
            return new JsonResponse(['error' => 'Missing or empty indicator_ids'], Response::HTTP_BAD_REQUEST);
        }

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
                st.code AS scam_type_code
            FROM indicator i
            LEFT JOIN observed_ioc oi ON i.indicator_id = oi.indicator_id
            LEFT JOIN message m ON oi.msg_id = m.msg_id
            LEFT JOIN conversation c ON m.conv_id = c.conv_id
            LEFT JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
            WHERE i.indicator_id IN ({$placeholders})
            ORDER BY i.first_seen DESC",
            $indicatorIds
        )->fetchAllAssociative();

        // Deduplicate by indicator_id (multiple observations → keep first)
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
            ];
        }

        if (empty($iocs)) {
            $bundle = $this->bundleBuilder->buildBundle([], [], 'AMBER', 'ScamBuster IOC Export (empty)');

            return new JsonResponse($bundle, Response::HTTP_OK);
        }

        // Build relationships: find co-occurrence among selected IOCs
        $relationships = $this->buildRelationships($indicatorIds, $iocs);

        $bundle = $this->bundleBuilder->buildBundle(
            $iocs,
            $relationships,
            'AMBER',
            sprintf('ScamBuster IOC Export - %d indicators', \count($iocs)),
            sprintf('Exported %d indicators from ScamBuster IOC Explorer', \count($iocs)),
        );

        return new JsonResponse($bundle, Response::HTTP_OK);
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

        // Find pairs of selected indicators that share conversations
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

        // Build lookup for type + value_norm
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
}

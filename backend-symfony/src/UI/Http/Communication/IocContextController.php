<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use Doctrine\DBAL\Connection;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/iocs/{indicatorId}/context',
    summary: 'Get contextual enrichment for an IOC indicator',
    description: 'Returns structural and semantic context for all observations of this indicator across conversations.',
    tags: ['IOCs'],
    parameters: [
        new OA\Parameter(name: 'indicatorId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'IOC context data'),
        new OA\Response(response: 404, description: 'Indicator not found'),
    ],
    security: [['Bearer' => []]]
)]
#[Route('/api/v1/iocs/{indicatorId}/context', name: 'api_ioc_context', methods: ['GET'])]
#[IsGranted('ioc:read')]
final class IocContextController
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(string $indicatorId): JsonResponse
    {
        // Check indicator exists
        $exists = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM indicator WHERE indicator_id = :id',
            ['id' => $indicatorId]
        );

        if (!is_numeric($exists) || (int) $exists === 0) {
            return new JsonResponse(
                ['error' => 'Indicator not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Get all contexts for this indicator
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM ioc_context WHERE indicator_id = :id ORDER BY created_at DESC',
            ['id' => $indicatorId]
        );

        $contexts = [];

        foreach ($rows as $row) {
            $structural = [
                'scam_type' => $this->str($row, 'scam_type_code'),
                'attck_technique' => $this->str($row, 'scam_type_attck'),
                'persona_code' => $this->str($row, 'persona_code'),
                'persona_label' => $this->str($row, 'persona_label'),
                'extraction_method' => $this->str($row, 'extraction_method'),
                'revelation_turn' => $this->intOrNull($row, 'revelation_turn'),
                'total_turns' => $this->intOrNull($row, 'total_turns'),
                'revelation_turn_ratio' => $this->floatOrNull($row, 'revelation_turn_ratio'),
                'engagement_hours' => $this->floatOrNull($row, 'engagement_hours'),
                'reward_value' => $this->floatOrNull($row, 'reward_value'),
                'co_revealed_types' => $this->parsePostgresArray($this->str($row, 'co_revealed_types')),
                'co_revealed_count' => $this->intOrNull($row, 'co_revealed_count') ?? 0,
                'campaign_id' => $this->str($row, 'campaign_id'),
            ];

            $semantic = null;
            $status = $this->str($row, 'enrichment_status') ?? 'pending';

            if ($status === 'enriched') {
                $semantic = [
                    'role' => $this->str($row, 'semantic_role'),
                    'stimulus_type' => $this->str($row, 'stimulus_type'),
                    'urgency_score' => $this->floatOrNull($row, 'urgency_score'),
                    'language_switch' => isset($row['language_switch']) ? (bool) $row['language_switch'] : null,
                    'hesitation_detected' => isset($row['hesitation_detected']) ? (bool) $row['hesitation_detected'] : null,
                    'context_excerpt' => $this->str($row, 'context_excerpt'),
                    'enrichment_confidence' => $this->floatOrNull($row, 'enrichment_confidence'),
                    'enrichment_model' => $this->str($row, 'enrichment_model'),
                ];
            }

            $contexts[] = [
                'obs_id' => $this->str($row, 'obs_id'),
                'enrichment_status' => $status,
                'structural' => $structural,
                'semantic' => $semantic,
                'computed_at' => $this->str($row, 'computed_at'),
            ];
        }

        return new JsonResponse([
            'indicator_id' => $indicatorId,
            'contexts' => $contexts,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function str(array $row, string $key): ?string
    {
        return \is_string($row[$key] ?? null) ? $row[$key] : null;
    }

    /** @param array<string, mixed> $row */
    private function intOrNull(array $row, string $key): ?int
    {
        return \is_numeric($row[$key] ?? null) ? (int) $row[$key] : null;
    }

    /** @param array<string, mixed> $row */
    private function floatOrNull(array $row, string $key): ?float
    {
        return \is_numeric($row[$key] ?? null) ? round((float) $row[$key], 4) : null;
    }

    /**
     * Parse PostgreSQL text[] format: {url,iban,phone} → ['url','iban','phone']
     *
     * @return list<string>
     */
    private function parsePostgresArray(?string $value): array
    {
        if ($value === null || $value === '' || $value === '{}') {
            return [];
        }

        return array_values(array_filter(explode(',', trim($value, '{}')), fn (string $s) => $s !== ''));
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Communication;

use Doctrine\DBAL\Connection;

class IocContextQueryService
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function indicatorExists(string $indicatorId): bool
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM indicator WHERE indicator_id = :id',
            ['id' => $indicatorId]
        );

        return is_numeric($count) && (int) $count > 0;
    }

    /**
     * @return list<array{obs_id: ?string, enrichment_status: string, structural: array<string, mixed>, semantic: ?array<string, mixed>, computed_at: ?string}>
     */
    public function findContextsByIndicatorId(string $indicatorId): array
    {
        // JOIN observed_ioc + message so that the API caller can pivot
        // from an IOC context row back to its source conversation
        // (Live Bait Theater) or source message. Without this, the IOC
        // detail page is a dead-end: it shows "Stimulus: Direct Request"
        // but no way to see which conversation / which outbound that
        // refers to.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ic.*, oi.msg_id AS observed_msg_id, m.conv_id AS observed_conv_id'
            . ' FROM ioc_context ic'
            . ' LEFT JOIN observed_ioc oi ON oi.obs_id = ic.obs_id'
            . ' LEFT JOIN message m ON m.msg_id = oi.msg_id AND m.deleted_at IS NULL'
            . ' WHERE ic.indicator_id = :id ORDER BY ic.created_at DESC',
            ['id' => $indicatorId]
        );

        $contexts = [];

        foreach ($rows as $row) {
            $structural = [
                'scam_type' => $this->str($row, 'scam_type_code'),
                'attck_technique' => $this->str($row, 'scam_type_attck'),
                'misp_taxonomy' => $this->str($row, 'scam_type_misp'),
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
                'conv_id' => $this->str($row, 'observed_conv_id'),
                'msg_id' => $this->str($row, 'observed_msg_id'),
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

        return $contexts;
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
     * Parse PostgreSQL text[] format: {url,iban,phone} -> ['url','iban','phone']
     *
     * @return list<string>
     */
    private function parsePostgresArray(?string $value): array
    {
        if ($value === null || $value === '' || $value === '{}') {
            return [];
        }

        return array_values(array_filter(explode(',', trim($value, '{}')), fn (string $s): bool => $s !== ''));
    }
}

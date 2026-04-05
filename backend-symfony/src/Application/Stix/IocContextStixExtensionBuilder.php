<?php

declare(strict_types=1);

namespace App\Application\Stix;

/**
 * Builds the x_scambuster_context STIX extension from an ioc_context row.
 *
 * Returns null for pending/skipped contexts.
 */
final class IocContextStixExtensionBuilder
{
    /**
     * @param array<string, mixed> $contextRow A row from the ioc_context table
     *
     * @return array<string, mixed>|null
     */
    public static function build(array $contextRow): ?array
    {
        $status = \is_string($contextRow['enrichment_status'] ?? null) ? $contextRow['enrichment_status'] : 'pending';

        if ($status === 'pending' || $status === 'skipped') {
            return null;
        }

        $ext = [
            'schema_version' => '1.0',
            'enrichment_status' => $status,
            'scam_type' => self::str($contextRow, 'scam_type_code'),
            'attck_technique' => self::str($contextRow, 'scam_type_attck'),
            'persona_code' => self::str($contextRow, 'persona_code'),
            'extraction_method' => self::str($contextRow, 'extraction_method'),
            'revelation_turn' => self::intVal($contextRow, 'revelation_turn'),
            'revelation_turn_ratio' => self::floatVal($contextRow, 'revelation_turn_ratio'),
            'total_turns' => self::intVal($contextRow, 'total_turns'),
            'engagement_hours' => self::floatVal($contextRow, 'engagement_hours'),
            'co_revealed_ioc_types' => self::parseCoRevealed($contextRow),
        ];

        // Add semantic fields only if enriched
        if ($status === 'enriched') {
            $ext['semantic_role'] = self::str($contextRow, 'semantic_role');
            $ext['stimulus_type'] = self::str($contextRow, 'stimulus_type');
            $ext['urgency_score'] = self::floatVal($contextRow, 'urgency_score');
            $ext['context_excerpt'] = self::str($contextRow, 'context_excerpt');
            $ext['enrichment_confidence'] = self::floatVal($contextRow, 'enrichment_confidence');
        }

        // Remove null values for clean output
        return array_filter($ext, fn (mixed $v) => $v !== null);
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): ?string
    {
        return \is_string($row[$key] ?? null) ? $row[$key] : null;
    }

    /** @param array<string, mixed> $row */
    private static function intVal(array $row, string $key): ?int
    {
        return \is_numeric($row[$key] ?? null) ? (int) $row[$key] : null;
    }

    /** @param array<string, mixed> $row */
    private static function floatVal(array $row, string $key): ?float
    {
        return \is_numeric($row[$key] ?? null) ? round((float) $row[$key], 4) : null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private static function parseCoRevealed(array $row): array
    {
        $val = \is_string($row['co_revealed_types'] ?? null) ? $row['co_revealed_types'] : '';

        if ($val === '' || $val === '{}') {
            return [];
        }

        return array_values(array_filter(explode(',', trim($val, '{}')), fn (string $s) => $s !== ''));
    }
}

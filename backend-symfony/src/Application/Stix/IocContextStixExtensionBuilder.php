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

        // Spec 105 P1+P2 — schema version bumped from 1.0 to 1.1 with
        // the 9 additive fields. Existing consumers reading 1.0 keep
        // their keys intact and ignore the new ones; nothing renamed.
        $ext = [
            'schema_version' => '1.1',
            'enrichment_status' => $status,
            'scam_type' => self::str($contextRow, 'scam_type_code'),
            'attck_technique' => self::str($contextRow, 'scam_type_attck'),
            'misp_taxonomy' => self::str($contextRow, 'scam_type_misp'),
            'persona_code' => self::str($contextRow, 'persona_code'),
            'persona_label' => self::str($contextRow, 'persona_label'),
            'extraction_method' => self::str($contextRow, 'extraction_method'),
            'revelation_turn' => self::intVal($contextRow, 'revelation_turn'),
            'revelation_turn_ratio' => self::floatVal($contextRow, 'revelation_turn_ratio'),
            'total_turns' => self::intVal($contextRow, 'total_turns'),
            'engagement_hours' => self::floatVal($contextRow, 'engagement_hours'),
            'co_revealed_ioc_types' => self::parseCoRevealed($contextRow),
            'co_revealed_count' => self::intVal($contextRow, 'co_revealed_count'),
            'stimulus_msg_id' => self::str($contextRow, 'stimulus_msg_id'),
            'reward_value' => self::floatVal($contextRow, 'reward_value'),
            'campaign_id' => self::str($contextRow, 'campaign_id'),
        ];

        // Semantic fields only if enriched. enrichment_model is the
        // provenance trail (which LLM produced these) — surfaced even
        // when the semantic fields below are unset, as long as the row
        // has been touched by the enricher.
        if ($status === 'enriched') {
            $ext['semantic_role'] = self::str($contextRow, 'semantic_role');
            $ext['stimulus_type'] = self::str($contextRow, 'stimulus_type');
            $ext['urgency_score'] = self::floatVal($contextRow, 'urgency_score');
            $ext['context_excerpt'] = self::str($contextRow, 'context_excerpt');
            $ext['enrichment_confidence'] = self::floatVal($contextRow, 'enrichment_confidence');
            $ext['enrichment_model'] = self::str($contextRow, 'enrichment_model');
            $ext['hesitation_detected'] = self::boolVal($contextRow, 'hesitation_detected');
            $ext['language_switch'] = self::boolVal($contextRow, 'language_switch');
        }

        // Remove null values for clean output
        return array_filter($ext, fn (mixed $v): bool => $v !== null);
    }

    /** @param array<string, mixed> $row */
    private static function boolVal(array $row, string $key): ?bool
    {
        if (!\array_key_exists($key, $row) || $row[$key] === null) {
            return null;
        }

        return (bool) $row[$key];
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

        return array_values(array_filter(explode(',', trim($val, '{}')), fn (string $s): bool => $s !== ''));
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Scambaiting;

use Doctrine\DBAL\Connection;

/**
 * Persona × scam type performance matrix.
 *
 * Returns one row per active (persona, scam type) pair with the
 * aggregated reward and session count. Backs the matrix UI that
 * makes the "no single best persona" claim legible at a glance:
 * a viewer sees which persona wins which scam type, and what the
 * cells where the bandit has too little data look like.
 *
 * Honesty:
 * - Only ACTIVE personas and scam types are returned (matches the
 *   nav-listed entities; demoted personas don't leak into the grid).
 * - Cells where persona_performance_stats has no row yield zero
 *   sessions / null reward, so the UI can dim them as "not yet
 *   sampled" without confusing them with "sampled but at 0".
 * - All-personas × all-scam-types is bounded (27 × 13 = 351 today),
 *   no pagination needed.
 */
final readonly class PersonaMatrixQueryService
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<array{
     *   persona_code: string,
     *   persona_label: string,
     *   scam_type_code: string,
     *   scam_type_label: string,
     *   sessions: int,
     *   reward_avg: float|null,
     * }>
     */
    public function getMatrix(): array
    {
        $sql = <<<'SQL'
            SELECT
              p.persona_code AS persona_code,
              COALESCE(p.persona_label, p.persona_code) AS persona_label,
              st.code AS scam_type_code,
              COALESCE(st.label, st.code) AS scam_type_label,
              COALESCE(pps.sessions_count, 0) AS sessions,
              pps.reward_avg AS reward_avg
            FROM persona p
            CROSS JOIN lkp_scam_type st
            LEFT JOIN persona_performance_stats pps
              ON pps.persona_id = p.persona_id
             AND pps.scam_type_id = st.scam_type_id
            WHERE p.is_active = TRUE
              AND st.active = TRUE
            ORDER BY p.persona_code, st.code
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql);

        $out = [];

        foreach ($rows as $row) {
            $personaCode = $this->str($row, 'persona_code');
            $scamTypeCode = $this->str($row, 'scam_type_code');

            if ($personaCode === '' || $scamTypeCode === '') {
                continue;
            }
            $out[] = [
                'persona_code' => $personaCode,
                'persona_label' => $this->str($row, 'persona_label'),
                'scam_type_code' => $scamTypeCode,
                'scam_type_label' => $this->str($row, 'scam_type_label'),
                'sessions' => $this->intVal($row, 'sessions'),
                'reward_avg' => isset($row['reward_avg']) && is_numeric($row['reward_avg']) ? round((float) $row['reward_avg'], 4) : null,
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function str(array $row, string $key): string
    {
        return \is_string($row[$key] ?? null) ? $row[$key] : '';
    }

    /** @param array<string, mixed> $row */
    private function intVal(array $row, string $key): int
    {
        return is_numeric($row[$key] ?? null) ? (int) $row[$key] : 0;
    }
}

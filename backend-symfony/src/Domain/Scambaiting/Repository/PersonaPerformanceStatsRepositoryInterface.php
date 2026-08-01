<?php

declare(strict_types=1);

namespace App\Domain\Scambaiting\Repository;

/**
 * Read port for aggregated persona-performance statistics.
 *
 * Only the entity-free read methods are exposed here so the port stays free of
 * any Infrastructure type. The richer, entity-returning methods used by the
 * bandit optimizer still live on the concrete repository (the backing entity
 * currently sits in the Infrastructure layer); widening this port is tracked as
 * a follow-up once that entity moves into the Domain.
 */
interface PersonaPerformanceStatsRepositoryInterface
{
    /**
     * @return array<array{scam_type_code: string, total_sessions: int, avg_reward: float}>
     */
    public function getAggregatedStatsByScamType(): array;

    /**
     * Delete all performance stats for a persona (across every scam type), so it
     * re-enters clean cold-start exploration after its prompt is edited. Idempotent.
     *
     * @return int number of stat rows removed
     */
    public function deleteAllForPersona(\App\Domain\Communication\Persona $persona): int;
}

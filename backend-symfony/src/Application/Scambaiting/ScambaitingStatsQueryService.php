<?php

declare(strict_types=1);

namespace App\Application\Scambaiting;

use App\Domain\Scambaiting\Repository\PersonaPerformanceStatsRepositoryInterface;

/**
 * Read-side service exposing aggregated scambaiting statistics to the UI,
 * so controllers depend on the Application layer instead of reaching into
 * the Doctrine repository directly.
 */
final readonly class ScambaitingStatsQueryService
{
    public function __construct(
        private PersonaPerformanceStatsRepositoryInterface $statsRepository,
    ) {
    }

    /**
     * @return array<array{scam_type_code: string, total_sessions: int, avg_reward: float}>
     */
    public function getAggregatedStatsByScamType(): array
    {
        return $this->statsRepository->getAggregatedStatsByScamType();
    }
}

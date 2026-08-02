<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Scambaiting;

use App\Application\Scambaiting\ScambaitingStatsQueryService;
use App\Domain\Scambaiting\Repository\PersonaPerformanceStatsRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class ScambaitingStatsQueryServiceTest extends TestCase
{
    public function test_returns_the_aggregated_stats_from_the_repository_unchanged(): void
    {
        $rows = [
            ['scam_type_code' => 'INVOICE_FRAUD', 'total_sessions' => 12, 'avg_reward' => 0.71],
            ['scam_type_code' => 'ROMANCE', 'total_sessions' => 5, 'avg_reward' => 0.42],
        ];

        $repo = new class ($rows) implements PersonaPerformanceStatsRepositoryInterface {
            /** @param array<array{scam_type_code: string, total_sessions: int, avg_reward: float}> $rows */
            public function __construct(private readonly array $rows)
            {
            }

            public function getAggregatedStatsByScamType(): array
            {
                return $this->rows;
            }

            public function deleteAllForPersona(\App\Domain\Communication\Persona $persona): int
            {
                return 0;
            }
        };

        $service = new ScambaitingStatsQueryService($repo);

        self::assertSame($rows, $service->getAggregatedStatsByScamType());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stats;

use App\Application\Stats\UrgencyCorpusStatsService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Spec 102 follow-up — integration test for the urgency corpus stats
 * service. Mirrors FinancialRevealTimingServiceTest. Asserts the
 * response shape on existing fixture data; exact values are not
 * pinned because the fixture corpus evolves across specs.
 */
final class UrgencyCorpusStatsServiceTest extends KernelTestCase
{
    public function testComputeReturnsExpectedShape(): void
    {
        self::bootKernel();
        /** @var UrgencyCorpusStatsService $service */
        $service = self::getContainer()->get(UrgencyCorpusStatsService::class);

        $result = $service->compute();

        $this->assertArrayHasKey('n', $result);
        $this->assertArrayHasKey('median', $result);
        $this->assertArrayHasKey('p75', $result);

        $this->assertIsInt($result['n']);
        $this->assertGreaterThanOrEqual(0, $result['n']);

        if ($result['n'] === 0) {
            $this->assertNull($result['median']);
            $this->assertNull($result['p75']);

            return;
        }

        $this->assertIsFloat($result['median']);
        $this->assertIsFloat($result['p75']);

        // urgency_score is bounded to [0, 1] by the LLM contract
        $this->assertGreaterThanOrEqual(0.0, $result['median']);
        $this->assertLessThanOrEqual(1.0, $result['median']);
        $this->assertGreaterThanOrEqual(0.0, $result['p75']);
        $this->assertLessThanOrEqual(1.0, $result['p75']);

        // P75 must be ≥ median (monotonic percentile)
        $this->assertGreaterThanOrEqual($result['median'], $result['p75']);
    }
}

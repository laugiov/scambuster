<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stats;

use App\Application\Stats\FinancialRevealTimingService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test for the corpus financial-reveal
 * timing service. Asserts the shape of the response on the existing
 * fixture data; the exact median/P75 values are not pinned because
 * the fixture data evolves across specs. We only verify:
 *   - response keys are present
 *   - integer types are correct
 *   - ratio percentages are in [0, 100]
 *   - n is non-negative
 */
final class FinancialRevealTimingServiceTest extends KernelTestCase
{
    public function testComputeReturnsExpectedShape(): void
    {
        self::bootKernel();
        /** @var FinancialRevealTimingService $service */
        $service = self::getContainer()->get(FinancialRevealTimingService::class);

        $result = $service->compute();

        $this->assertArrayHasKey('n', $result);
        $this->assertArrayHasKey('median_turn', $result);
        $this->assertArrayHasKey('p75_turn', $result);
        $this->assertArrayHasKey('median_ratio_pct', $result);
        $this->assertArrayHasKey('p75_ratio_pct', $result);

        $this->assertIsInt($result['n']);
        $this->assertGreaterThanOrEqual(0, $result['n']);

        if ($result['n'] === 0) {
            $this->assertNull($result['median_turn']);
            $this->assertNull($result['p75_turn']);
            $this->assertNull($result['median_ratio_pct']);
            $this->assertNull($result['p75_ratio_pct']);

            return;
        }

        $this->assertIsInt($result['median_turn']);
        $this->assertIsInt($result['p75_turn']);
        $this->assertIsInt($result['median_ratio_pct']);
        $this->assertIsInt($result['p75_ratio_pct']);

        $this->assertGreaterThanOrEqual(1, $result['median_turn']);
        $this->assertGreaterThanOrEqual($result['median_turn'], $result['p75_turn']);
        $this->assertGreaterThanOrEqual(0, $result['median_ratio_pct']);
        $this->assertLessThanOrEqual(100, $result['median_ratio_pct']);
        $this->assertGreaterThanOrEqual(0, $result['p75_ratio_pct']);
        $this->assertLessThanOrEqual(100, $result['p75_ratio_pct']);
    }

    public function testComputeReturnsZeroWhenNoClosedConversationWithFinancial(): void
    {
        self::bootKernel();
        /** @var Connection $conn */
        $conn = self::getContainer()->get(Connection::class);

        // Snapshot the current 'closed' status conversation list so we can
        // restore them at the end. Setting them all to 'open' temporarily
        // produces a known empty result without touching the fixture data.
        $closedIds = $conn->fetchFirstColumn(
            "SELECT conv_id FROM conversation WHERE status = 'closed'",
        );

        if ($closedIds !== []) {
            $conn->executeStatement(
                "UPDATE conversation SET status = 'open' WHERE conv_id IN (?)",
                [$closedIds],
                [\Doctrine\DBAL\ArrayParameterType::STRING],
            );
        }

        try {
            /** @var FinancialRevealTimingService $service */
            $service = self::getContainer()->get(FinancialRevealTimingService::class);
            $result = $service->compute();

            $this->assertSame(0, $result['n']);
            $this->assertNull($result['median_turn']);
            $this->assertNull($result['p75_turn']);
            $this->assertNull($result['median_ratio_pct']);
            $this->assertNull($result['p75_ratio_pct']);
        } finally {
            if ($closedIds !== []) {
                $conn->executeStatement(
                    "UPDATE conversation SET status = 'closed' WHERE conv_id IN (?)",
                    [$closedIds],
                    [\Doctrine\DBAL\ArrayParameterType::STRING],
                );
            }
        }
    }
}

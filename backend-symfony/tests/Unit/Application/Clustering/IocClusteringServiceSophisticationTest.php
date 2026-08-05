<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Clustering;

use App\Application\Clustering\IocClusteringService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IocClusteringServiceSophisticationTest extends TestCase
{
    /**
     * @return iterable<string, array{0:int, 1:int, 2:int, 3:string}>
     */
    public static function sophisticationProvider(): iterable
    {
        // Edge cases — defensive floor
        yield 'empty cluster' => [0, 0, 0, 'none'];
        yield 'single conversation with one anchor' => [1, 1, 1, 'none'];
        yield 'cluster with no anchor IOC' => [5, 1, 0, 'none'];

        // Minimal tier — has at least one signal but nothing strong
        yield 'small 2-conv cluster on one anchor' => [2, 1, 1, 'minimal'];
        yield '3-conv BEC cluster on one anchor' => [3, 1, 1, 'minimal'];

        // Minimal-but-larger tier — one signal only, not enough to lift to
        // intermediate. These deliberately scored 1 (multi-scam OR conv >= 4
        // OR multi-anchor — pick ONE), still minimal per heuristic.
        yield '3-conv with multiple scam types' => [3, 2, 1, 'minimal'];
        yield '4-conv single-scam single-anchor' => [4, 1, 1, 'minimal'];

        // Intermediate tier — two amplifying signals
        yield '5-conv cluster with 2 anchors' => [5, 1, 2, 'intermediate'];
        yield '10-conv single-scam single-anchor' => [10, 1, 1, 'intermediate'];
        yield '4-conv multi-scam multi-anchor' => [4, 2, 2, 'intermediate'];

        // Advanced tier — strong cluster with multiple amplifying signals
        yield 'large 20-conv multi-scam multi-anchor' => [20, 3, 4, 'advanced'];
        yield 'prod-style cluster #3A9D shape' => [39, 5, 4, 'advanced'];
        yield 'massive 100-conv operation' => [100, 5, 8, 'advanced'];
    }

    #[DataProvider('sophisticationProvider')]
    public function testComputeSophisticationReturnsExpectedTier(
        int $convCount,
        int $distinctScamTypeCount,
        int $anchorIocCount,
        string $expected
    ): void {
        $actual = IocClusteringService::computeSophistication(
            $convCount,
            $distinctScamTypeCount,
            $anchorIocCount
        );

        $this->assertSame($expected, $actual);
    }

    public function testTierMonotonicityOverConversationGrowth(): void
    {
        // Within the same (scam_types, anchors) shape, scaling conv_count up
        // can only move the tier up, never down. Guards against accidental
        // regression on the scoring thresholds.
        $tiers = [];

        foreach ([2, 4, 10, 20, 50] as $convCount) {
            $tiers[] = IocClusteringService::computeSophistication($convCount, 2, 2);
        }

        $rank = ['none' => 0, 'minimal' => 1, 'intermediate' => 2, 'advanced' => 3];
        for ($i = 1; $i < count($tiers); $i++) {
            $this->assertGreaterThanOrEqual(
                $rank[$tiers[$i - 1]],
                $rank[$tiers[$i]],
                sprintf('Tier regressed from %s to %s at index %d', $tiers[$i - 1], $tiers[$i], $i),
            );
        }
    }
}

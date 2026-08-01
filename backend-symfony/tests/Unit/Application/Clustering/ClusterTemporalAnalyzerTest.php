<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Clustering;

use App\Application\Clustering\ClusterTemporalAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure temporal-metric core.
 *
 * A crafted inbound series — a 5-message burst day, then two sparse days with a
 * long gap — pins every metric: cadence (peak hour / weekday), busiest day,
 * median-baseline burst detection, median gap and longest dormancy.
 */
class ClusterTemporalAnalyzerTest extends TestCase
{
    private ClusterTemporalAnalyzer $analyzer;

    protected function setUp(): void
    {
        // computeMetrics() is pure; the EM is never touched on this path.
        $this->analyzer = new ClusterTemporalAnalyzer($this->createMock(EntityManagerInterface::class));
    }

    private function utc(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s, new \DateTimeZone('UTC'));
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private function craftedSeries(): array
    {
        // 2026-01-01 is a Thursday. Five messages (three at 09:xx, two at 10:xx),
        // then one on Fri 01-02 14:00, then one on Sat 01-10 09:00 after a long gap.
        return array_map(
            fn (string $s): \DateTimeImmutable => $this->utc($s),
            [
                '2026-01-01 09:00:00',
                '2026-01-01 09:20:00',
                '2026-01-01 09:40:00',
                '2026-01-01 10:00:00',
                '2026-01-01 10:20:00',
                '2026-01-02 14:00:00',
                '2026-01-10 09:00:00',
            ],
        );
    }

    public function testVolumeAndWindow(): void
    {
        $m = $this->analyzer->computeMetrics($this->craftedSeries());

        self::assertSame(7, $m['message_count']);
        self::assertSame(3, $m['active_days']);
        self::assertSame('2026-01-01T09:00:00+00:00', $m['first_activity']);
        self::assertSame('2026-01-10T09:00:00+00:00', $m['last_activity']);
        self::assertSame(10, $m['active_span_days']); // 01-01 .. 01-10 inclusive
    }

    public function testCadence(): void
    {
        $m = $this->analyzer->computeMetrics($this->craftedSeries());

        // Hour 9 occurs 4× (3 on day A + 1 on day C), hour 10 twice, hour 14 once.
        self::assertSame(4, $m['hour_of_day_histogram'][9]);
        self::assertSame(2, $m['hour_of_day_histogram'][10]);
        self::assertSame(1, $m['hour_of_day_histogram'][14]);
        self::assertSame(9, $m['peak_hour']);

        // Thursday (ISO-8601 N = 4) carries the 5 burst-day messages.
        self::assertSame(5, $m['day_of_week_histogram'][4]);
        self::assertSame(4, $m['peak_day_of_week']);
    }

    public function testBurstDetection(): void
    {
        $m = $this->analyzer->computeMetrics($this->craftedSeries());

        // Per-day counts [5,1,1] → median 1 → threshold max(3, 2) = 3. Only day A qualifies.
        self::assertSame('2026-01-01', $m['busiest_day']);
        self::assertSame(5, $m['max_messages_per_day']);
        self::assertSame(['2026-01-01'], $m['burst_days']);
        self::assertSame(1, $m['burst_count']);
    }

    public function testGapAndDormancy(): void
    {
        $m = $this->analyzer->computeMetrics($this->craftedSeries());

        // Four 20-min intra-day gaps dominate the 6-gap median → 0.333 h.
        self::assertEqualsWithDelta(0.333, $m['median_gap_hours'], 0.01);
        // 01-02 14:00 → 01-10 09:00 = 187 h.
        self::assertEqualsWithDelta(187.0, $m['longest_dormancy_hours'], 0.01);
    }

    public function testSteadyVolumeIsNotFlaggedAsBurst(): void
    {
        // Three days at 4 messages each: steady, not bursty (threshold max(3, 8) = 8).
        $series = [];

        foreach (['2026-02-01', '2026-02-02', '2026-02-03'] as $day) {
            foreach (['08:00:00', '10:00:00', '12:00:00', '14:00:00'] as $t) {
                $series[] = $this->utc("{$day} {$t}");
            }
        }

        $m = $this->analyzer->computeMetrics($series);

        self::assertSame(4, $m['max_messages_per_day']);
        self::assertSame([], $m['burst_days']);
        self::assertSame(0, $m['burst_count']);
    }

    public function testEmptySeriesIsSafe(): void
    {
        $m = $this->analyzer->computeMetrics([]);

        self::assertSame(0, $m['message_count']);
        self::assertSame(0, $m['active_days']);
        self::assertNull($m['first_activity']);
        self::assertNull($m['last_activity']);
        self::assertNull($m['peak_hour']);
        self::assertNull($m['median_gap_hours']);
        self::assertNull($m['longest_dormancy_hours']);
        self::assertNull($m['busiest_day']);
        self::assertSame(0, $m['burst_count']);
    }

    public function testSingleMessageHasNoGaps(): void
    {
        $m = $this->analyzer->computeMetrics([$this->utc('2026-03-15 16:30:00')]);

        self::assertSame(1, $m['message_count']);
        self::assertSame(1, $m['active_days']);
        self::assertSame(1, $m['active_span_days']);
        self::assertSame(16, $m['peak_hour']);
        self::assertNull($m['median_gap_hours']);
        self::assertNull($m['longest_dormancy_hours']);
        self::assertSame('2026-03-15', $m['busiest_day']);
        self::assertSame([], $m['burst_days']);
    }
}

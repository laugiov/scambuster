<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation;

use App\Application\Evaluation\BanditAnalyzer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class BanditAnalyzerTest extends TestCase
{
    public function test_analyze_with_empty_data(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $analyzer = new BanditAnalyzer($em);
        $result = $analyzer->analyze();

        $this->assertSame(0, $result['total_conversations']);
        $this->assertSame(0, $result['active_scam_types']);
        $this->assertFalse($result['overall_convergence']);
        $this->assertEmpty($result['scam_type_analyses']);
    }

    public function test_analyze_detects_convergence(): void
    {
        $rows = [];

        for ($i = 0; $i < 15; ++$i) {
            $rows[] = [
                'conv_id' => 'c-' . $i,
                'scam_type' => 'PHISHING',
                'persona_code' => $i < 12 ? 'elderly_person' : 'tech_newbie',
                'reward_value' => 0.8,
                'status' => 'closed',
                'engagement_duration_sec' => 3600,
                'ts_created' => '2026-03-01 00:00:00',
            ];
        }

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $analyzer = new BanditAnalyzer($em);
        $result = $analyzer->analyze();

        $this->assertSame(15, $result['total_conversations']);
        $this->assertSame(1, $result['active_scam_types']);
        $this->assertTrue($result['overall_convergence']);
        /** @var array<int, array<string, mixed>> $analyses */
        $analyses = $result['scam_type_analyses'];
        $this->assertCount(1, $analyses);
        $this->assertTrue($analyses[0]['converged']);
        $this->assertSame('elderly_person', $analyses[0]['dominant_persona']);
    }

    public function test_analyze_reports_confidence_intervals_and_reliability(): void
    {
        $rows = [];

        // A well-sampled arm (n=12, slight spread) and a single-observation arm.
        for ($i = 0; $i < 12; ++$i) {
            $rows[] = [
                'conv_id' => 'r-' . $i, 'scam_type' => 'PHISHING', 'persona_code' => 'elderly_person',
                'reward_value' => $i === 0 ? 0.7 : 0.8, 'status' => 'closed',
                'engagement_duration_sec' => 3600, 'ts_created' => '2026-03-01 00:00:00',
            ];
        }
        $rows[] = [
            'conv_id' => 'single', 'scam_type' => 'PHISHING', 'persona_code' => 'tech_newbie',
            'reward_value' => 0.9, 'status' => 'closed',
            'engagement_duration_sec' => 3600, 'ts_created' => '2026-03-01 00:00:00',
        ];

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $stats = (new BanditAnalyzer($em))->analyze()['scam_type_analyses'][0]['reward_stats'];

        // Well-sampled arm: a real interval and flagged reliable.
        self::assertSame(12, $stats['elderly_person']['count']);
        self::assertNotNull($stats['elderly_person']['ci_margin']);
        self::assertGreaterThan($stats['elderly_person']['ci_lower'], $stats['elderly_person']['ci_upper'], 'upper bound is above the lower bound');
        self::assertTrue($stats['elderly_person']['reliable']);

        // Single observation: no interval, never reliable — the average must not be
        // presented as an effect.
        self::assertSame(1, $stats['tech_newbie']['count']);
        self::assertNull($stats['tech_newbie']['ci_margin']);
        self::assertFalse($stats['tech_newbie']['reliable']);
    }

    public function test_analyze_no_convergence_when_spread(): void
    {
        $rows = [];
        $personas = ['p1', 'p2', 'p3', 'p4', 'p5'];

        for ($i = 0; $i < 10; ++$i) {
            $rows[] = [
                'conv_id' => 'c-' . $i,
                'scam_type' => 'ROMANCE',
                'persona_code' => $personas[$i % 5],
                'reward_value' => 0.5,
                'status' => 'closed',
                'engagement_duration_sec' => 1800,
                'ts_created' => '2026-03-01 00:00:00',
            ];
        }

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $analyzer = new BanditAnalyzer($em);
        $result = $analyzer->analyze();

        /** @var array<int, array<string, mixed>> $analyses */
        $analyses = $result['scam_type_analyses'];
        $this->assertFalse($analyses[0]['converged']);
    }

    public function test_cold_start_analysis(): void
    {
        $rows = [];

        for ($i = 0; $i < 5; ++$i) {
            $rows[] = [
                'conv_id' => 'c-' . $i,
                'scam_type' => 'PHISHING',
                'persona_code' => 'persona_' . ($i % 3),
                'reward_value' => null,
                'status' => 'open',
                'engagement_duration_sec' => 0,
                'ts_created' => '2026-03-0' . ($i + 1) . ' 00:00:00',
            ];
        }

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $analyzer = new BanditAnalyzer($em);
        $result = $analyzer->analyze();

        /** @var array<string, array<string, mixed>> $coldStartAnalysis */
        $coldStartAnalysis = $result['cold_start_analysis'];
        $this->assertArrayHasKey('PHISHING', $coldStartAnalysis);
        /** @var array<string, mixed> $coldStart */
        $coldStart = $coldStartAnalysis['PHISHING'];
        /** @var array<int, string> $first3 */
        $first3 = $coldStart['first_3_personas'];
        $this->assertCount(3, $first3);
        $this->assertGreaterThan(0, $coldStart['exploration_ratio']);
    }

    public function test_min_sessions_filter(): void
    {
        $rows = [
            ['conv_id' => 'c1', 'scam_type' => 'PHISHING', 'persona_code' => 'p1', 'reward_value' => 0.5, 'status' => 'closed', 'engagement_duration_sec' => 0, 'ts_created' => '2026-03-01'],
            ['conv_id' => 'c2', 'scam_type' => 'PHISHING', 'persona_code' => 'p1', 'reward_value' => 0.5, 'status' => 'closed', 'engagement_duration_sec' => 0, 'ts_created' => '2026-03-01'],
        ];

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $analyzer = new BanditAnalyzer($em);

        $result5 = $analyzer->analyze(minSessions: 5);
        $this->assertSame(0, $result5['active_scam_types']);

        $result2 = $analyzer->analyze(minSessions: 2);
        $this->assertSame(1, $result2['active_scam_types']);
    }
}

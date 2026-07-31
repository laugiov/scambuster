<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Stix;

use App\Application\Communication\IocExportMapper;
use App\Application\Stix\IocStixExportHandler;
use App\Application\Stix\StixBundleBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IocStixExportHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private StixBundleBuilder $bundleBuilder;
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->bundleBuilder = new StixBundleBuilder(new IocExportMapper());
        $this->connection = $this->createMock(Connection::class);
        $this->em->method('getConnection')->willReturn($this->connection);
    }

    public function test_export_returns_empty_bundle_when_no_rows(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);
        $this->connection->method('executeQuery')->willReturn($result);

        $handler = new IocStixExportHandler($this->em, $this->bundleBuilder);
        $actual = $handler->export(['ind-1']);

        $this->assertSame('bundle', $actual['type']);
        $this->assertArrayHasKey('objects', $actual);
    }

    public function test_export_deduplicates_by_indicator_id(): void
    {
        $rows = [
            $this->makeRow('ind-1', 'email', 'test@evil.com'),
            $this->makeRow('ind-1', 'email', 'test@evil.com'), // duplicate
            $this->makeRow('ind-2', 'url', 'https://evil.com'),
        ];

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);
        $this->connection->method('executeQuery')->willReturn($result);

        $handler = new IocStixExportHandler($this->em, $this->bundleBuilder);
        $actual = $handler->export(['ind-1', 'ind-2']);

        // Should have bundle with indicators
        $this->assertSame('bundle', $actual['type']);
        // Count indicator objects (type=indicator)
        $indicators = array_filter($actual['objects'], fn ($o) => ($o['type'] ?? '') === 'indicator');
        $this->assertCount(2, $indicators); // 2 unique indicators
    }

    public function test_export_extracts_context_row_when_present(): void
    {
        $row = $this->makeRow('ind-1', 'url', 'https://evil.com');
        $row['ctx_enrichment_status'] = 'enriched';
        $row['ctx_semantic_role'] = 'LURE';
        $row['ctx_scam_type_code'] = 'PHISHING';

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([$row]);
        $this->connection->method('executeQuery')->willReturn($result);

        $handler = new IocStixExportHandler($this->em, $this->bundleBuilder);
        $actual = $handler->export(['ind-1'], 'GREEN');

        $this->assertSame('bundle', $actual['type']);
        // Should contain indicator objects
        $this->assertNotEmpty($actual['objects']);
    }

    public function test_export_handles_null_context_observation(): void
    {
        $row = $this->makeRow('ind-1', 'domain', 'evil.com');
        $row['context_observation'] = null;

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([$row]);
        $this->connection->method('executeQuery')->willReturn($result);

        $handler = new IocStixExportHandler($this->em, $this->bundleBuilder);
        $actual = $handler->export(['ind-1']);

        $this->assertSame('bundle', $actual['type']);
    }

    public function test_export_handles_invalid_json_context(): void
    {
        $row = $this->makeRow('ind-1', 'phone', '+1234567890');
        $row['context_observation'] = 'not-json';

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([$row]);
        $this->connection->method('executeQuery')->willReturn($result);

        $handler = new IocStixExportHandler($this->em, $this->bundleBuilder);
        $actual = $handler->export(['ind-1']);

        $this->assertSame('bundle', $actual['type']);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeRow(string $indicatorId, string $type, string $value): array
    {
        return [
            'indicator_id' => $indicatorId,
            'type' => $type,
            'value' => $value,
            'value_norm' => $value,
            'first_seen' => '2026-01-01 00:00:00',
            'last_seen' => '2026-01-02 00:00:00',
            'score' => null,
            'tlp' => 'AMBER',
            'confidence_score' => 0.85,
            'context_observation' => json_encode(['type' => $type, 'value' => $value, 'source' => 'regex']),
            'scam_type_code' => 'PHISHING',
            'ctx_enrichment_status' => null,
            'ctx_scam_type_code' => null,
            'ctx_scam_type_attck' => null,
            'ctx_persona_code' => null,
            'ctx_extraction_method' => null,
            'ctx_revelation_turn' => null,
            'ctx_revelation_turn_ratio' => null,
            'ctx_total_turns' => null,
            'ctx_engagement_hours' => null,
            'ctx_co_revealed_types' => null,
            'ctx_semantic_role' => null,
            'ctx_stimulus_type' => null,
            'ctx_urgency_score' => null,
            'ctx_context_excerpt' => null,
            'ctx_enrichment_confidence' => null,
        ];
    }
}

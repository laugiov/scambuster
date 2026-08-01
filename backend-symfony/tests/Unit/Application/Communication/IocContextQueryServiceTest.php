<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocContextQueryService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IocContextQueryServiceTest extends TestCase
{
    private Connection&MockObject $connection;
    private IocContextQueryService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->service = new IocContextQueryService($this->connection);
    }

    public function testIndicatorExistsReturnsTrue(): void
    {
        $this->connection->method('fetchOne')
            ->willReturn('1');

        $this->assertTrue($this->service->indicatorExists('ind-123'));
    }

    public function testIndicatorExistsReturnsFalse(): void
    {
        $this->connection->method('fetchOne')
            ->willReturn('0');

        $this->assertFalse($this->service->indicatorExists('ind-999'));
    }

    public function testIndicatorExistsHandlesNonNumericResult(): void
    {
        $this->connection->method('fetchOne')
            ->willReturn(false);

        $this->assertFalse($this->service->indicatorExists('ind-bad'));
    }

    public function testFindContextsByIndicatorIdReturnsEmptyForNoRows(): void
    {
        $this->connection->method('fetchAllAssociative')
            ->willReturn([]);

        $this->assertSame([], $this->service->findContextsByIndicatorId('ind-empty'));
    }

    public function testFindContextsByIndicatorIdReturnsPendingContext(): void
    {
        $this->connection->method('fetchAllAssociative')
            ->willReturn([
                [
                    'obs_id' => 'obs-1',
                    'enrichment_status' => 'pending',
                    'scam_type_code' => 'PHISHING',
                    'scam_type_attck' => 'T1566',
                    'persona_code' => 'elderly_person',
                    'persona_label' => 'Elderly Person',
                    'extraction_method' => 'regex',
                    'revelation_turn' => '3',
                    'total_turns' => '10',
                    'revelation_turn_ratio' => '0.3',
                    'engagement_hours' => '2.5',
                    'reward_value' => '0.75',
                    'co_revealed_types' => '{url,iban}',
                    'co_revealed_count' => '2',
                    'campaign_id' => null,
                    'computed_at' => null,
                ],
            ]);

        $result = $this->service->findContextsByIndicatorId('ind-1');

        $this->assertCount(1, $result);
        $this->assertSame('obs-1', $result[0]['obs_id']);
        $this->assertSame('pending', $result[0]['enrichment_status']);
        $this->assertNull($result[0]['semantic']);
        $this->assertSame('PHISHING', $result[0]['structural']['scam_type']);
        $this->assertSame(3, $result[0]['structural']['revelation_turn']);
        $this->assertSame(10, $result[0]['structural']['total_turns']);
        $this->assertEquals(0.3, $result[0]['structural']['revelation_turn_ratio']);
        $this->assertEquals(2.5, $result[0]['structural']['engagement_hours']);
        $this->assertEquals(['url', 'iban'], $result[0]['structural']['co_revealed_types']);
        $this->assertSame(2, $result[0]['structural']['co_revealed_count']);
    }

    public function testFindContextsByIndicatorIdReturnsEnrichedContext(): void
    {
        $this->connection->method('fetchAllAssociative')
            ->willReturn([
                [
                    'obs_id' => 'obs-2',
                    'enrichment_status' => 'enriched',
                    'scam_type_code' => 'ROMANCE',
                    'scam_type_attck' => 'T1656',
                    'persona_code' => 'lonely_person',
                    'persona_label' => 'Lonely Person',
                    'extraction_method' => 'llm',
                    'revelation_turn' => '5',
                    'total_turns' => '20',
                    'revelation_turn_ratio' => '0.25',
                    'engagement_hours' => '48.0',
                    'reward_value' => '0.9',
                    'co_revealed_types' => '{}',
                    'co_revealed_count' => null,
                    'campaign_id' => 'camp-1',
                    'semantic_role' => 'LURE',
                    'stimulus_type' => 'urgency',
                    'urgency_score' => '0.85',
                    'language_switch' => true,
                    'hesitation_detected' => false,
                    'context_excerpt' => 'Please send money urgently',
                    'enrichment_confidence' => '0.92',
                    'enrichment_model' => 'gpt-4o',
                    'computed_at' => '2026-04-10 12:00:00',
                ],
            ]);

        $result = $this->service->findContextsByIndicatorId('ind-2');

        $this->assertCount(1, $result);
        $this->assertSame('enriched', $result[0]['enrichment_status']);
        $this->assertNotNull($result[0]['semantic']);
        $this->assertSame('LURE', $result[0]['semantic']['role']);
        $this->assertSame('urgency', $result[0]['semantic']['stimulus_type']);
        $this->assertEquals(0.85, $result[0]['semantic']['urgency_score']);
        $this->assertTrue($result[0]['semantic']['language_switch']);
        $this->assertFalse($result[0]['semantic']['hesitation_detected']);
        $this->assertSame('gpt-4o', $result[0]['semantic']['enrichment_model']);
        $this->assertSame('2026-04-10 12:00:00', $result[0]['computed_at']);
    }

    public function testFindContextsHandlesEmptyPostgresArray(): void
    {
        $this->connection->method('fetchAllAssociative')
            ->willReturn([
                [
                    'obs_id' => 'obs-3',
                    'enrichment_status' => 'pending',
                    'scam_type_code' => null,
                    'scam_type_attck' => null,
                    'persona_code' => null,
                    'persona_label' => null,
                    'extraction_method' => null,
                    'revelation_turn' => null,
                    'total_turns' => null,
                    'revelation_turn_ratio' => null,
                    'engagement_hours' => null,
                    'reward_value' => null,
                    'co_revealed_types' => null,
                    'co_revealed_count' => null,
                    'campaign_id' => null,
                    'computed_at' => null,
                ],
            ]);

        $result = $this->service->findContextsByIndicatorId('ind-3');

        $this->assertCount(1, $result);
        $this->assertSame([], $result[0]['structural']['co_revealed_types']);
        $this->assertSame(0, $result[0]['structural']['co_revealed_count']);
        $this->assertNull($result[0]['structural']['revelation_turn']);
    }
}

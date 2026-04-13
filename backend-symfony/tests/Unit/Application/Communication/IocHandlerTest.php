<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocEnrichmentService;
use App\Application\Communication\IocExtractorOrchestrator;
use App\Application\Communication\IocHandler;
use App\Application\Communication\IocQueryService;
use App\Application\Communication\IocUpsertService;
use App\Application\LLM\ContextualEnricher;
use App\Domain\Communication\ObservedIoc;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class IocHandlerTest extends TestCase
{
    private IocUpsertService&MockObject $upsertService;
    private IocExtractorOrchestrator&MockObject $extractorOrchestrator;
    private IocEnrichmentService&MockObject $enrichmentService;
    private IocQueryService&MockObject $queryService;

    protected function setUp(): void
    {
        $this->upsertService = $this->createMock(IocUpsertService::class);
        $this->extractorOrchestrator = $this->createMock(IocExtractorOrchestrator::class);
        $this->enrichmentService = $this->createMock(IocEnrichmentService::class);
        $this->queryService = $this->createMock(IocQueryService::class);
    }

    private function createHandler(
        ?ContextualEnricher $enricher = null,
        ?Connection $connection = null,
    ): IocHandler {
        return new IocHandler(
            upsertService: $this->upsertService,
            extractorOrchestrator: $this->extractorOrchestrator,
            enrichmentService: $this->enrichmentService,
            queryService: $this->queryService,
            contextualEnricher: $enricher,
            connection: $connection,
            logger: new NullLogger(),
        );
    }

    public function test_upsertEnrichedIoc_delegates_to_service(): void
    {
        $observedIoc = $this->createMock(ObservedIoc::class);
        $this->upsertService->expects($this->once())
            ->method('upsertEnrichedIoc')
            ->willReturn($observedIoc);

        $handler = $this->createHandler();
        $result = $handler->upsertEnrichedIoc(['ioc' => ['type' => 'email', 'value' => 'test@test.com', 'value_norm' => 'test@test.com', 'source' => 'regex', 'first_seen' => '2026-01-01']]);

        $this->assertSame($observedIoc, $result);
    }

    public function test_calculateMessageRisk_delegates_to_service(): void
    {
        $expected = ['score_agg' => 50, 'level' => 'medium', 'reason' => 'test', 'should_reply' => true];
        $this->enrichmentService->method('calculateMessageRisk')->willReturn($expected);

        $handler = $this->createHandler();
        $this->assertSame($expected, $handler->calculateMessageRisk('msg-1'));
    }

    public function test_getConversationIocs_delegates_to_service(): void
    {
        $iocs = [$this->createMock(ObservedIoc::class)];
        $this->queryService->method('getConversationIocs')->willReturn($iocs);

        $handler = $this->createHandler();
        $this->assertSame($iocs, $handler->getConversationIocs('conv-1'));
    }

    public function test_extractIocsFromMessage_without_persist(): void
    {
        $extracted = [
            ['type' => 'email', 'value' => 'test@test.com', 'value_norm' => 'test@test.com', 'context' => []],
        ];
        $this->extractorOrchestrator->method('extractFromMessage')->willReturn($extracted);

        $handler = $this->createHandler();
        $result = $handler->extractIocsFromMessage('msg-1', 'regex', [], false);

        $this->assertSame($extracted, $result);
        $this->upsertService->expects($this->never())->method('upsertEnrichedIoc');
    }

    public function test_extractIocsFromMessage_with_persist(): void
    {
        $extracted = [
            ['type' => 'email', 'value' => 'test@test.com', 'value_norm' => 'test@test.com', 'context' => ['source' => 'regex']],
        ];
        $this->extractorOrchestrator->method('extractFromMessage')->willReturn($extracted);

        $observedIoc = $this->createMock(ObservedIoc::class);
        $observedIoc->method('getObsId')->willReturn('obs-1');
        $this->upsertService->method('upsertEnrichedIoc')->willReturn($observedIoc);

        $handler = $this->createHandler();
        $result = $handler->extractIocsFromMessage('msg-1', 'regex', [], true);

        $this->assertCount(1, $result);
        $this->assertSame('obs-1', $result[0]['context']['obs_id']);
    }

    public function test_extractIocsFromMessage_persist_handles_upsert_exception(): void
    {
        $extracted = [
            ['type' => 'email', 'value' => 'test@test.com', 'value_norm' => 'test@test.com', 'context' => []],
        ];
        $this->extractorOrchestrator->method('extractFromMessage')->willReturn($extracted);
        $this->upsertService->method('upsertEnrichedIoc')
            ->willThrowException(new \RuntimeException('Upsert failed'));

        $handler = $this->createHandler();
        $result = $handler->extractIocsFromMessage('msg-1', 'regex', [], true);

        // Exception is caught, IOC is skipped
        $this->assertEmpty($result);
    }

    public function test_getIocDetail_delegates_to_service(): void
    {
        $detail = ['indicator_id' => 'ind-1', 'type' => 'email'];
        $this->queryService->method('getIocDetail')->willReturn($detail);

        $handler = $this->createHandler();
        $this->assertSame($detail, $handler->getIocDetail('ind-1'));
    }

    public function test_getCoOccurrenceGraph_delegates_to_service(): void
    {
        $graph = ['nodes' => [], 'edges' => []];
        $this->queryService->method('getCoOccurrenceGraph')->willReturn($graph);

        $handler = $this->createHandler();
        $this->assertSame($graph, $handler->getCoOccurrenceGraph('ind-1'));
    }

    public function test_computeConfidenceData_delegates_to_service(): void
    {
        $data = ['confidence' => 0.95, 'decay_factor' => 0.98, 'effective_score' => 0.93];
        $this->queryService->method('computeConfidenceData')->willReturn($data);

        $handler = $this->createHandler();
        $this->assertSame($data, $handler->computeConfidenceData('ind-1', 0.95, new \DateTimeImmutable()));
    }

    public function test_getAllIocsWithConfidence_delegates_to_service(): void
    {
        $iocs = [['indicator_id' => 'ind-1']];
        $this->queryService->method('getAllIocsWithConfidence')->willReturn($iocs);

        $handler = $this->createHandler();
        $this->assertSame($iocs, $handler->getAllIocsWithConfidence(0.5));
    }

    public function test_updateIocEnrichment_delegates_to_service(): void
    {
        $observedIoc = $this->createMock(ObservedIoc::class);
        $this->enrichmentService->method('updateIocEnrichment')->willReturn($observedIoc);

        $handler = $this->createHandler();
        $this->assertSame($observedIoc, $handler->updateIocEnrichment('obs-1', ['key' => 'value']));
    }
}

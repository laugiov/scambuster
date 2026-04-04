<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;

/**
 * Facade for IOC operations. Delegates to specialized services.
 *
 * This class preserves backward compatibility for existing callers
 * (controllers, IngestHandler, n8n webhooks) while the actual logic
 * lives in 4 dedicated services:
 *
 * - IocUpsertService:          upsert, header extraction, dedup
 * - IocExtractorOrchestrator:  regex/LLM/hybrid extraction
 * - IocEnrichmentService:      risk scoring, enrichment updates
 * - IocQueryService:           list, detail, co-occurrence, conversation IOCs
 *
 * @see specs/039-short-term-hardening (CT-0 decomposition)
 */
class IocHandler
{
    public function __construct(
        private readonly IocUpsertService $upsertService,
        private readonly IocExtractorOrchestrator $extractorOrchestrator,
        private readonly IocEnrichmentService $enrichmentService,
        private readonly IocQueryService $queryService,
    ) {
    }

    /**
     * @param array{
     *     message_id?: string,
     *     msg_id?: string,
     *     ioc: array{type: string, value: string, value_norm: string, source: string, first_seen: string},
     *     enrichment?: array<string, mixed>,
     *     score?: array<string, mixed>,
     *     category?: string,
     *     tags?: array<string>,
     *     tlp?: string
     * } $data Enriched IOC payload from n8n
     *
     * @throws \RuntimeException If message not found
     */
    public function upsertEnrichedIoc(array $data): ObservedIoc
    {
        return $this->upsertService->upsertEnrichedIoc($data);
    }

    /**
     * @throws \RuntimeException If message not found
     *
     * @return array{score_agg: int, level: 'high'|'medium'|'low', reason: string, should_reply: bool}
     */
    public function calculateMessageRisk(string $msgId): array
    {
        return $this->enrichmentService->calculateMessageRisk($msgId);
    }

    /**
     * @return array<ObservedIoc>
     */
    public function getConversationIocs(string $convId): array
    {
        return $this->queryService->getConversationIocs($convId);
    }

    public function extractAndUpsertHeaderIocs(Message $message): int
    {
        return $this->upsertService->extractAndUpsertHeaderIocs($message);
    }

    /**
     * @param array<string, mixed> $enrichment Enrichment data
     *
     * @throws \RuntimeException If IOC not found
     */
    public function updateIocEnrichment(string $obsId, array $enrichment): ObservedIoc
    {
        return $this->enrichmentService->updateIocEnrichment($obsId, $enrichment);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllIocsWithConfidence(?float $minScore = null): array
    {
        return $this->queryService->getAllIocsWithConfidence($minScore);
    }

    /**
     * @throws \RuntimeException If indicator not found
     *
     * @return array<string, mixed>
     */
    public function getIocDetail(string $indicatorId): array
    {
        return $this->queryService->getIocDetail($indicatorId);
    }

    /**
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    public function getCoOccurrenceGraph(string $indicatorId, int $maxNodes = 30): array
    {
        return $this->queryService->getCoOccurrenceGraph($indicatorId, $maxNodes);
    }

    /**
     * @return array{confidence: float, decay_factor: float, effective_score: float}
     */
    public function computeConfidenceData(string $indicatorId, ?float $confidenceScore, \DateTimeImmutable $tsObserved): array
    {
        return $this->queryService->computeConfidenceData($indicatorId, $confidenceScore, $tsObserved);
    }

    /**
     * @param array<int, string> $types
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractIocsFromMessage(string $msgId, string $method = 'hybrid', array $types = [], bool $persist = false): array
    {
        $uniqueIocs = $this->extractorOrchestrator->extractFromMessage($msgId, $method, $types);

        if ($persist) {
            $persistedIocs = [];

            foreach ($uniqueIocs as $ioc) {
                $payload = [
                    'msg_id' => $msgId,
                    'ioc' => [
                        'type' => $ioc['type'],
                        'value' => $ioc['value'],
                        'value_norm' => $ioc['value_norm'],
                        'source' => 'extraction',
                        'first_seen' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
                    ],
                    'enrichment' => [],
                    'category' => 'Unknown',
                    'tags' => [],
                    'tlp' => 'AMBER',
                ];

                try {
                    $observedIoc = $this->upsertService->upsertEnrichedIoc($payload);
                    $persistedIocs[] = [
                        'type' => $ioc['type'],
                        'value' => $ioc['value'],
                        'value_norm' => $ioc['value_norm'],
                        'context' => array_merge($ioc['context'], [
                            'obs_id' => $observedIoc->getObsId(),
                        ]),
                    ];
                } catch (\Exception $e) {
                    continue;
                }
            }

            return $persistedIocs;
        }

        return $uniqueIocs;
    }
}

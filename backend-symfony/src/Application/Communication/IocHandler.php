<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\LLM\ContextualEnricher;
use App\Application\LLM\ContextualEnrichmentRequest;
use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

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
 */
class IocHandler
{
    public function __construct(
        private readonly IocUpsertService $upsertService,
        private readonly IocExtractorOrchestrator $extractorOrchestrator,
        private readonly IocEnrichmentService $enrichmentService,
        private readonly IocQueryService $queryService,
        private readonly ?ContextualEnricher $contextualEnricher = null,
        private readonly ?Connection $connection = null,
        private readonly ?LoggerInterface $logger = null,
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
    public function getConversationIocs(string $convId, bool $actionableOnly = false): array
    {
        return $this->queryService->getConversationIocs($convId, $actionableOnly);
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
                /** @var string $iocType */
                $iocType = $ioc['type'] ?? '';
                /** @var string $iocValue */
                $iocValue = $ioc['value'] ?? '';
                /** @var string $iocValueNorm */
                $iocValueNorm = $ioc['value_norm'] ?? '';
                /** @var array<string, mixed> $iocContext */
                $iocContext = $ioc['context'] ?? [];
                $payload = [
                    'msg_id' => $msgId,
                    'ioc' => [
                        'type' => $iocType,
                        'value' => $iocValue,
                        'value_norm' => $iocValueNorm,
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
                        'type' => $iocType,
                        'value' => $iocValue,
                        'value_norm' => $iocValueNorm,
                        'context' => array_merge($iocContext, [
                            'obs_id' => $observedIoc->getObsId(),
                        ]),
                    ];
                } catch (\Exception) {
                    continue;
                }
            }

            // LLM semantic enrichment: 1 call per message for all IOCs
            $this->enrichMessageIocsWithLlm($msgId, $persistedIocs);

            return $persistedIocs;
        }

        return $uniqueIocs;
    }

    /**
     * Run LLM semantic enrichment for all IOCs from a single message (1 LLM call).
     *
     * @param list<array{type: string, value: string, value_norm: string, context: array<string, mixed>}> $persistedIocs
     */
    private function enrichMessageIocsWithLlm(string $msgId, array $persistedIocs): void
    {
        if (!$this->contextualEnricher instanceof \App\Application\LLM\ContextualEnricher || !$this->connection instanceof \Doctrine\DBAL\Connection || $persistedIocs === []) {
            return;
        }

        // Collect non-header IOC types
        $iocTypes = [];

        foreach ($persistedIocs as $ioc) {
            if (!IocContextService::isHeaderIocType($ioc['type'])) {
                $iocTypes[] = $ioc['type'];
            }
        }

        $iocTypes = array_values(array_unique($iocTypes));

        if ($iocTypes === []) {
            return;
        }

        try {
            // Load message text + conversation context
            $msgRow = $this->connection->fetchAssociative(
                'SELECT m.body_text, c.conv_id, st.code AS scam_type, p.persona_code,'
                . ' ic2.revelation_turn, ic2.total_turns'
                . ' FROM message m'
                . ' JOIN conversation c ON m.conv_id = c.conv_id'
                . ' JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id'
                . ' LEFT JOIN persona p ON c.persona_id = p.persona_id'
                . ' LEFT JOIN observed_ioc oi ON oi.msg_id = m.msg_id'
                . ' LEFT JOIN ioc_context ic2 ON oi.obs_id = ic2.obs_id'
                . ' WHERE m.msg_id = :msgId'
                . ' LIMIT 1',
                ['msgId' => $msgId],
            );

            if (!$msgRow) {
                return;
            }

            $request = new ContextualEnrichmentRequest(
                iocTypes: $iocTypes,
                scamType: \is_string($msgRow['scam_type'] ?? null) ? $msgRow['scam_type'] : 'UNKNOWN',
                personaCode: \is_string($msgRow['persona_code'] ?? null) ? $msgRow['persona_code'] : 'generic_user',
                revelationTurn: \is_numeric($msgRow['revelation_turn'] ?? null) ? (int) $msgRow['revelation_turn'] : 1,
                totalTurns: \is_numeric($msgRow['total_turns'] ?? null) ? (int) $msgRow['total_turns'] : 1,
                revelationMessageText: \is_string($msgRow['body_text'] ?? null) ? $msgRow['body_text'] : '',
                stimulusMessageText: null,
                previousInboundText: null,
            );

            $result = $this->contextualEnricher->enrich($request);

            if (!$result instanceof \App\Application\LLM\ContextualEnrichmentResult) {
                return;
            }

            // Update all IOC contexts from this message in one go
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            foreach ($persistedIocs as $ioc) {
                $obsId = $ioc['context']['obs_id'] ?? null;

                if (!\is_string($obsId)) {
                    continue;
                }

                if (IocContextService::isHeaderIocType($ioc['type'])) {
                    continue;
                }

                $semanticRole = $result->iocRoles[$ioc['type']] ?? 'UNKNOWN';

                $this->connection->executeStatement(
                    'UPDATE ioc_context SET'
                    . ' semantic_role = :semanticRole,'
                    . ' stimulus_type = :stimulusType,'
                    . ' urgency_score = :urgencyScore,'
                    . ' language_switch = :languageSwitch,'
                    . ' hesitation_detected = :hesitationDetected,'
                    . ' context_excerpt = :contextExcerpt,'
                    . ' enrichment_confidence = :enrichmentConfidence,'
                    . ' enrichment_status = \'enriched\','
                    . ' computed_at = :computedAt'
                    . ' WHERE obs_id = :obsId',
                    [
                        'semanticRole' => $semanticRole,
                        'stimulusType' => $result->stimulusType,
                        'urgencyScore' => $result->urgencyScore,
                        'languageSwitch' => $result->languageSwitch ? 'true' : 'false',
                        'hesitationDetected' => $result->hesitationDetected ? 'true' : 'false',
                        'contextExcerpt' => mb_substr($result->contextExcerpt, 0, 295),
                        'enrichmentConfidence' => $result->enrichmentConfidence,
                        'computedAt' => $now,
                        'obsId' => $obsId,
                    ],
                );
            }

            $this->logger?->info('[IocHandler] LLM enrichment completed for message', [
                'msg_id' => $msgId,
                'ioc_count' => \count($iocTypes),
                'stimulus_type' => $result->stimulusType,
            ]);
        } catch (\Throwable $e) {
            $this->logger?->warning('[IocHandler] LLM enrichment failed, structural context preserved', [
                'msg_id' => $msgId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

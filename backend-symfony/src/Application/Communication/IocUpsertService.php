<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Audit\AuditLogger;
use App\Application\LLM\ContextualEnricher;
use App\Application\LLM\ContextualEnrichmentRequest;
use App\Domain\Audit\AuditEventType;
use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles IOC upsert, deduplication, and header IOC extraction.
 *
 * Extracted from IocHandler (CT-0 decomposition).
 */
class IocUpsertService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RiskScorer $riskScorer,
        private readonly IocCategorizer $categorizer,
        private readonly IocExportMapper $exportMapper,
        private readonly HeaderIocExtractor $headerExtractor,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?IocContextService $contextService = null,
        private readonly ?ContextualEnricher $contextualEnricher = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Upsert enriched IOC from n8n workflow.
     *
     * Idempotent: Uses unique constraint on (msg_id, type, value_norm).
     *
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
        $message = $this->resolveMessage($data);

        if (!$message) {
            throw new \RuntimeException(sprintf(
                'Message not found for external_message_id=%s or msg_id=%s',
                $data['message_id'] ?? 'null',
                $data['msg_id'] ?? 'null'
            ));
        }

        $iocData = $data['ioc'];
        $type = $iocData['type'];
        $valueNorm = $iocData['value_norm'];

        $existingIoc = $this->findExistingIoc($message->getMsgId(), $type, $valueNorm);

        if ($existingIoc) {
            $this->updateIocContext($existingIoc, $data);
            $this->em->flush();

            return $existingIoc;
        }

        $iocId = uuid_create(UUID_TYPE_RANDOM);
        $obsId = uuid_create(UUID_TYPE_RANDOM);

        $enrichment = $data['enrichment'] ?? [];
        /** @phpstan-ignore-next-line */
        $score = $data['score'] ?? $this->riskScorer->calculateIocScore($enrichment);

        $providedCategory = $data['category'] ?? null;
        $category = ($providedCategory !== null && $providedCategory !== 'Unknown')
            ? $providedCategory
            : $this->categorizer->guessCategory(
                $iocData['value'],
                $message->getBodyText()
            );

        $context = [
            'type' => $type,
            'value' => $iocData['value'],
            'value_norm' => $valueNorm,
            'source' => $iocData['source'],
            'first_seen' => $iocData['first_seen'],
            'enrichment' => $enrichment,
            'score' => $score,
            'category' => $category,
            'tags' => $data['tags'] ?? ['phishing'],
            'tlp' => $data['tlp'] ?? 'AMBER',
        ];

        $context = $this->exportMapper->enrichWithExportMetadata($context);

        $conn = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $existingIndicator = $conn->executeQuery(
            'SELECT indicator_id FROM indicator WHERE type = :type AND value_norm = :valueNorm',
            ['type' => $type, 'valueNorm' => $valueNorm]
        )->fetchAssociative();

        if ($existingIndicator) {
            $indicatorId = $existingIndicator['indicator_id'];
            $conn->executeStatement(
                'UPDATE indicator SET
                    last_seen = :lastSeen,
                    last_enriched = :lastEnriched,
                    occurrences = occurrences + 1,
                    enrichment = :enrichment,
                    score = :score,
                    updated_at = :updatedAt
                WHERE indicator_id = :indicatorId',
                [
                    'lastSeen' => $now,
                    'lastEnriched' => $now,
                    'enrichment' => json_encode($enrichment),
                    'score' => json_encode($score),
                    'updatedAt' => $now,
                    'indicatorId' => $indicatorId,
                ]
            );
        } else {
            $indicatorId = $iocId;
            $conn->executeStatement(
                'INSERT INTO indicator (
                    indicator_id, type, value, value_norm, first_seen, last_seen,
                    last_enriched, occurrences, enrichment, score, tlp, created_at, updated_at
                ) VALUES (
                    :indicatorId, :type, :value, :valueNorm, :firstSeen, :lastSeen,
                    :lastEnriched, 1, :enrichment, :score, :tlp, :createdAt, :updatedAt
                )',
                [
                    'indicatorId' => $indicatorId,
                    'type' => $type,
                    'value' => $iocData['value'],
                    'valueNorm' => $valueNorm,
                    'firstSeen' => $now,
                    'lastSeen' => $now,
                    'lastEnriched' => $now,
                    'enrichment' => json_encode($enrichment),
                    'score' => json_encode($score),
                    'tlp' => $data['tlp'] ?? 'AMBER',
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]
            );
        }

        /** @var string $extractionMethod */
        $extractionMethod = $context['extraction_method'] ?? 'unknown';
        $confidence = IocConfidenceCalculator::getBaseConfidence($extractionMethod);

        $occurrencesRow = $conn->fetchOne(
            'SELECT occurrences FROM indicator WHERE indicator_id = :id',
            ['id' => $indicatorId],
        );
        $occurrences = \is_numeric($occurrencesRow) ? (int) $occurrencesRow : 1;
        $confidence = IocConfidenceCalculator::boostConfidence($confidence, $occurrences);

        $observedIoc = new ObservedIoc(
            $obsId,
            $message,
            $indicatorId,
            $context,
            new \DateTimeImmutable(),
            $confidence,
        );

        $this->em->persist($observedIoc);
        $this->em->flush();

        $this->auditLogger?->log(
            AuditEventType::IOC_EXTRACTED,
            $message->getConversation()->getConvId(),
            'ioc_extracted',
            'success',
            'observed_ioc',
            $obsId,
            [
                'type' => $type,
                'value_norm' => $valueNorm,
                'indicator_id' => $indicatorId,
            ],
        );

        // Compute structural context for the newly upserted IOC
        $this->contextService?->computeAndPersistForMessage(
            $message->getMsgId(),
            [['obs_id' => $obsId, 'indicator_id' => $indicatorId, 'ioc_type' => $type]],
        );

        // LLM semantic enrichment (fail-safe: never blocks upsert)
        if ($this->contextualEnricher !== null && !IocContextService::isHeaderIocType($type)) {
            try {
                $this->enrichWithLlm($message, $obsId, $type);
            } catch (\Throwable $e) {
                $this->logger?->warning('[IocUpsert] LLM enrichment failed, structural context preserved', [
                    'obs_id' => $obsId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $observedIoc;
    }

    /**
     * Extract and upsert header-based IOCs from a message.
     */
    public function extractAndUpsertHeaderIocs(Message $message): int
    {
        $headers = $message->getHeaders();
        $subject = $message->getSubject() ?? '';

        $headerIocs = $this->headerExtractor->extractHeaderIocs($headers, $subject);

        $count = 0;

        foreach ($headerIocs as $iocData) {
            $payload = [
                'msg_id' => $message->getMsgId(),
                'ioc' => [
                    'type' => $iocData['type'],
                    'value' => $iocData['value'],
                    'value_norm' => $iocData['value_norm'],
                    'source' => $iocData['source'],
                    'first_seen' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
                ],
                'enrichment' => [],
                'category' => 'Unknown',
                'tags' => [],
                'tlp' => 'AMBER',
            ];

            try {
                $this->upsertEnrichedIoc($payload);
                ++$count;
            } catch (\Exception $e) {
                continue;
            }
        }

        return $count;
    }

    /**
     * Resolve message by external_message_id or msg_id.
     *
     * @param array{message_id?: string, msg_id?: string} $data
     */
    private function resolveMessage(array $data): ?Message
    {
        $repo = $this->em->getRepository(Message::class);

        if (!empty($data['message_id'])) {
            $message = $repo->findOneBy(['externalMessageId' => $data['message_id']]);

            if ($message) {
                return $message;
            }
        }

        if (!empty($data['msg_id'])) {
            return $repo->find($data['msg_id']);
        }

        return null;
    }

    /**
     * Find existing IOC by (msg_id, type, value_norm).
     */
    private function findExistingIoc(string $msgId, string $type, string $valueNorm): ?ObservedIoc
    {
        $conn = $this->em->getConnection();
        $sql = "
            SELECT obs_id
            FROM observed_ioc
            WHERE msg_id = :msgId
              AND context_observation->>'type' = :type
              AND context_observation->>'value_norm' = :valueNorm
            LIMIT 1
        ";

        $result = $conn->executeQuery($sql, [
            'msgId' => $msgId,
            'type' => $type,
            'valueNorm' => $valueNorm,
        ])->fetchAssociative();

        if (!$result) {
            return null;
        }

        return $this->em->getRepository(ObservedIoc::class)->find($result['obs_id']);
    }

    /**
     * Update existing IOC context with new enrichment data.
     *
     * @param ObservedIoc          $ioc     Existing IOC entity
     * @param array<string, mixed> $newData New data
     */
    private function updateIocContext(ObservedIoc $ioc, array $newData): void
    {
        $context = $ioc->getContext();

        if (isset($newData['enrichment']) && is_array($newData['enrichment'])) {
            $existingEnrichment = $context['enrichment'] ?? [];
            $context['enrichment'] = is_array($existingEnrichment) ? array_merge($existingEnrichment, $newData['enrichment']) : $newData['enrichment'];
        }

        /** @phpstan-ignore-next-line */
        $context['score'] = $this->riskScorer->calculateIocScore($context['enrichment'] ?? []);

        if (isset($newData['category']) && is_string($newData['category'])) {
            $context['category'] = $newData['category'];
        }

        if (isset($newData['tags']) && is_array($newData['tags'])) {
            $existingTags = $context['tags'] ?? [];
            $context['tags'] = array_unique(is_array($existingTags) ? array_merge($existingTags, $newData['tags']) : $newData['tags']);
        }

        $context = $this->exportMapper->enrichWithExportMetadata($context);

        $ioc->updateContext($context);
    }

    /**
     * Run LLM semantic enrichment for a single IOC, updating ioc_context in place.
     */
    private function enrichWithLlm(Message $message, string $obsId, string $iocType): void
    {
        $conn = $this->em->getConnection();

        $ctxRow = $conn->fetchAssociative(
            'SELECT scam_type_code, persona_code, revelation_turn, total_turns FROM ioc_context WHERE obs_id = :obsId',
            ['obsId' => $obsId],
        );

        if (!$ctxRow) {
            return;
        }

        $request = new ContextualEnrichmentRequest(
            iocTypes: [$iocType],
            scamType: \is_string($ctxRow['scam_type_code'] ?? null) ? $ctxRow['scam_type_code'] : 'UNKNOWN',
            personaCode: \is_string($ctxRow['persona_code'] ?? null) ? $ctxRow['persona_code'] : 'generic_user',
            revelationTurn: \is_numeric($ctxRow['revelation_turn'] ?? null) ? (int) $ctxRow['revelation_turn'] : 1,
            totalTurns: \is_numeric($ctxRow['total_turns'] ?? null) ? (int) $ctxRow['total_turns'] : 1,
            revelationMessageText: $message->getBodyText(),
            stimulusMessageText: null,
            previousInboundText: null,
        );

        $result = $this->contextualEnricher?->enrich($request);

        if ($result === null) {
            return;
        }

        $conn->executeStatement(
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
                'semanticRole' => $result->iocRoles[$iocType] ?? 'UNKNOWN',
                'stimulusType' => $result->stimulusType,
                'urgencyScore' => $result->urgencyScore,
                'languageSwitch' => $result->languageSwitch ? 'true' : 'false',
                'hesitationDetected' => $result->hesitationDetected ? 'true' : 'false',
                'contextExcerpt' => mb_substr($result->contextExcerpt, 0, 295),
                'enrichmentConfidence' => $result->enrichmentConfidence,
                'computedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'obsId' => $obsId,
            ],
        );
    }
}

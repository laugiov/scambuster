<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handles IOC enrichment, storage, and risk calculation
 *
 * Responsibilities:
 * - Upsert enriched IOCs with idempotence (specs/05-normaliser-decider.md §3.1)
 * - Calculate message-level risk scores (§4)
 * - Get conversation-level IOCs (deduplicated)
 * - Migrate legacy url_analysis data to observed_ioc
 *
 * IMPORTANT: This is an Application Service.
 * - EntityManager is private readonly (DDD compliance)
 * - No business logic (delegated to RiskScorer, IocCategorizer)
 * - Only orchestration and persistence
 */
class IocHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RiskScorer $riskScorer,
        private readonly IocCategorizer $categorizer,
        private readonly IocExportMapper $exportMapper,
        private readonly HeaderIocExtractor $headerExtractor,
        private readonly IocValidator $validator,
        private readonly IocNormalizer $normalizer,
        private readonly IocExtractor $iocExtractor
    ) {
    }

    /**
     * Upsert enriched IOC from n8n workflow
     *
     * Idempotent: Uses unique constraint on (msg_id, type, value_norm)
     * to prevent duplicates.
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
     *
     * @return ObservedIoc The persisted IOC entity
     */
    public function upsertEnrichedIoc(array $data): ObservedIoc
    {
        // Resolve message: try external_message_id first, then msg_id
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

        // Check if IOC already exists (idempotence)
        $existingIoc = $this->findExistingIoc($message->getMsgId(), $type, $valueNorm);

        if ($existingIoc) {
            // Update existing IOC context (enrichment might have new data)
            $this->updateIocContext($existingIoc, $data);
            $this->em->flush();

            return $existingIoc;
        }

        // Create new IOC
        $iocId = uuid_create(UUID_TYPE_RANDOM);
        $obsId = uuid_create(UUID_TYPE_RANDOM);

        // Calculate score if not provided
        $enrichment = $data['enrichment'] ?? [];
        /** @phpstan-ignore-next-line */
        $score = $data['score'] ?? $this->riskScorer->calculateIocScore($enrichment);

        // Guess category if not provided
        $category = $data['category'] ?? $this->categorizer->guessCategory(
            $iocData['value'],
            $message->getBodyText()
        );

        // Build context JSON
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
            'tlp' => $data['tlp'] ?? 'AMBER'
        ];

        // Enrich with MISP/STIX export metadata
        $context = $this->exportMapper->enrichWithExportMetadata($context);

        // First, create or update the indicator entry in the indicator table
        $conn = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Check if indicator exists
        $existingIndicator = $conn->executeQuery(
            'SELECT indicator_id FROM indicator WHERE type = :type AND value_norm = :valueNorm',
            ['type' => $type, 'valueNorm' => $valueNorm]
        )->fetchAssociative();

        if ($existingIndicator) {
            // Update existing indicator
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
                    'indicatorId' => $indicatorId
                ]
            );
        } else {
            // Create new indicator
            $indicatorId = $iocId; // Use the same ID
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
                    'updatedAt' => $now
                ]
            );
        }

        // Now create the ObservedIoc with the indicator_id
        $observedIoc = new ObservedIoc(
            $obsId,
            $message,
            $indicatorId,
            $context,
            new \DateTimeImmutable()
        );

        $this->em->persist($observedIoc);
        $this->em->flush();

        return $observedIoc;
    }

    /**
     * Calculate aggregate risk for a message based on its IOCs
     *
     * Returns the highest risk score among all IOCs.
     * If no IOCs found, returns low risk (score=0).
     *
     * @param string $msgId Message UUID
     *
     * @throws \RuntimeException If message not found
     *
     * @return array{score_agg: int, level: 'high'|'medium'|'low', reason: string, should_reply: bool}
     */
    public function calculateMessageRisk(string $msgId): array
    {
        $message = $this->em->getRepository(Message::class)->find($msgId);

        if (!$message) {
            throw new \RuntimeException('Message not found: ' . $msgId);
        }

        $iocs = $this->em->getRepository(ObservedIoc::class)->findBy(['message' => $message]);

        if (empty($iocs)) {
            return [
                'score_agg' => 0,
                'level' => 'low',
                'reason' => 'No IOCs detected',
                'should_reply' => false
            ];
        }

        // Find max score across all IOCs
        $maxScore = 0;
        $reasons = [];

        foreach ($iocs as $ioc) {
            $context = $ioc->getContext();
            $scoreData = $context['score'] ?? [];

            $iocScore = 0;

            if (is_array($scoreData) && isset($scoreData['agg']) && is_int($scoreData['agg'])) {
                $iocScore = $scoreData['agg'];
            }

            if ($iocScore > $maxScore) {
                $maxScore = $iocScore;
            }

            if (is_array($scoreData) && isset($scoreData['explain']) && is_string($scoreData['explain'])) {
                $explainText = $scoreData['explain'];
                $typeValue = (isset($context['type']) && is_string($context['type'])) ? $context['type'] : 'unknown';
                $reasons[] = sprintf(
                    '%s: %s',
                    $typeValue,
                    $explainText
                );
            }
        }

        $level = $this->riskScorer->determineLevel($maxScore);

        // Prepare IOCs for shouldReply decision
        $iocTypes = array_map(function ($ioc) {
            $context = $ioc->getContext();
            $typeValue = isset($context['type']) && is_string($context['type']) ? $context['type'] : '';

            return ['type' => $typeValue];
        }, $iocs);
        $shouldReply = $this->riskScorer->shouldReply($maxScore, $level, $iocTypes);

        return [
            'score_agg' => $maxScore,
            'level' => $level,
            'reason' => implode(' ; ', $reasons),
            'should_reply' => $shouldReply
        ];
    }

    /**
     * Get all IOCs for a conversation (deduplicated by ioc_id)
     *
     * @param string $convId Conversation UUID
     *
     * @return array<ObservedIoc>
     */
    public function getConversationIocs(string $convId): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('ioc')
            ->from(ObservedIoc::class, 'ioc')
            ->join('ioc.message', 'm')
            ->where('m.conversation = :convId')
            ->setParameter('convId', $convId)
            ->orderBy('ioc.tsObserved', 'DESC');

        /** @var array<ObservedIoc> $allIocs */
        $allIocs = $qb->getQuery()->getResult();

        // Deduplicate by ioc_id
        /** @var array<ObservedIoc> $unique */
        $unique = [];
        /** @var array<string, true> $seenIds */
        $seenIds = [];

        foreach ($allIocs as $ioc) {
            $iocId = $ioc->getIndicatorId();

            if (!isset($seenIds[$iocId])) {
                $seenIds[$iocId] = true;
                $unique[] = $ioc;
            }
        }

        return $unique;
    }

    /**
     * Resolve message by external_message_id (Gmail/Outlook ID) or msg_id (UUID)
     *
     * @param array{message_id?: string, msg_id?: string} $data
     */
    private function resolveMessage(array $data): ?Message
    {
        $repo = $this->em->getRepository(Message::class);

        // Try external_message_id first (Gmail Message-ID)
        if (!empty($data['message_id'])) {
            $message = $repo->findOneBy(['externalMessageId' => $data['message_id']]);

            if ($message) {
                return $message;
            }
        }

        // Fallback to msg_id (internal UUID)
        if (!empty($data['msg_id'])) {
            return $repo->find($data['msg_id']);
        }

        return null;
    }

    /**
     * Find existing IOC by (msg_id, type, value_norm)
     *
     * @param string $msgId     Message UUID
     * @param string $type      IOC type
     * @param string $valueNorm Normalized value
     */
    private function findExistingIoc(string $msgId, string $type, string $valueNorm): ?ObservedIoc
    {
        // Note: Using raw SQL for JSON field comparison since Doctrine doesn't support it well
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
            'valueNorm' => $valueNorm
        ])->fetchAssociative();

        if (!$result) {
            return null;
        }

        return $this->em->getRepository(ObservedIoc::class)->find($result['obs_id']);
    }

    /**
     * Update existing IOC context with new enrichment data
     *
     * @param ObservedIoc          $ioc     Existing IOC entity
     * @param array<string, mixed> $newData New data from n8n
     */
    private function updateIocContext(ObservedIoc $ioc, array $newData): void
    {
        $context = $ioc->getContext();

        // Merge enrichment data (keep existing, add new)
        if (isset($newData['enrichment']) && is_array($newData['enrichment'])) {
            $existingEnrichment = $context['enrichment'] ?? [];
            $context['enrichment'] = is_array($existingEnrichment) ? array_merge($existingEnrichment, $newData['enrichment']) : $newData['enrichment'];
        }

        // Recalculate score with updated enrichment
        /** @phpstan-ignore-next-line */
        $context['score'] = $this->riskScorer->calculateIocScore($context['enrichment'] ?? []);

        // Update category if provided
        if (isset($newData['category']) && is_string($newData['category'])) {
            $context['category'] = $newData['category'];
        }

        // Update tags (merge)
        if (isset($newData['tags']) && is_array($newData['tags'])) {
            $existingTags = $context['tags'] ?? [];
            $context['tags'] = array_unique(is_array($existingTags) ? array_merge($existingTags, $newData['tags']) : $newData['tags']);
        }

        // Ensure MISP/STIX export metadata is present (for legacy IOCs or updates)
        $context = $this->exportMapper->enrichWithExportMetadata($context);

        // Persist updated context using domain method (DDD compliant)
        $ioc->updateContext($context);
    }

    /**
     * Extract and upsert header-based IOCs from a message.
     *
     * Extracts 5 header IOC types:
     * - message_id (RFC 5322)
     * - subject
     * - spf_result (SPF validation ENUM)
     * - dkim_result (DKIM validation ENUM)
     * - dmarc_result (DMARC validation ENUM)
     *
     * This method is typically called after message ingestion to extract
     * authentication-related IOCs that don't require external enrichment.
     *
     * @param Message $message The message to extract headers from
     *
     * @return int Number of header IOCs created
     */
    public function extractAndUpsertHeaderIocs(Message $message): int
    {
        $headers = $message->getHeaders();
        $subject = $message->getSubject() ?? '';

        // Extract header IOCs using HeaderIocExtractor service
        $headerIocs = $this->headerExtractor->extractHeaderIocs($headers, $subject);

        $count = 0;

        foreach ($headerIocs as $iocData) {
            // Build payload compatible with upsertEnrichedIoc()
            $payload = [
                'msg_id' => $message->getMsgId(),
                'ioc' => [
                    'type' => $iocData['type'],
                    'value' => $iocData['value'],
                    'value_norm' => $iocData['value_norm'],
                    'source' => $iocData['source'],
                    'first_seen' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
                ],
                'enrichment' => [], // No external enrichment for headers
                'category' => 'Unknown', // Will be classified by IocCategorizer
                'tags' => [],
                'tlp' => 'AMBER',
            ];

            try {
                $this->upsertEnrichedIoc($payload);
                ++$count;
            } catch (\Exception $e) {
                // Log but don't fail - some headers might be duplicate or invalid
                // In production, this should use proper logging
                continue;
            }
        }

        return $count;
    }

    /**
     * Update enrichment data for an existing IOC
     *
     * This method is used by n8n workflows to update IOC enrichment data
     * (URLScan, VirusTotal) after IOCs have been persisted by extractIocsFromMessage().
     *
     * @param string               $obsId      Observation ID (UUID)
     * @param array<string, mixed> $enrichment Enrichment data (urlscan, virustotal)
     *
     * @throws \RuntimeException If IOC not found
     *
     * @return ObservedIoc The updated IOC entity
     */
    public function updateIocEnrichment(string $obsId, array $enrichment): ObservedIoc
    {
        $observedIoc = $this->em->getRepository(ObservedIoc::class)->find($obsId);

        if (!$observedIoc) {
            throw new \RuntimeException("IOC not found: {$obsId}");
        }

        // Use existing updateIocContext method with enrichment-only payload
        $this->updateIocContext($observedIoc, ['enrichment' => $enrichment]);

        // Also update the indicator table enrichment field
        $indicatorId = $observedIoc->getIndicatorId();
        $context = $observedIoc->getContext();
        $fullEnrichment = $context['enrichment'] ?? [];
        $score = $context['score'] ?? [];

        $conn = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $conn->executeStatement(
            'UPDATE indicator SET
                enrichment = :enrichment,
                score = :score,
                last_enriched = :lastEnriched,
                updated_at = :updatedAt
            WHERE indicator_id = :indicatorId',
            [
                'enrichment' => json_encode($fullEnrichment),
                'score' => json_encode($score),
                'lastEnriched' => $now,
                'updatedAt' => $now,
                'indicatorId' => $indicatorId
            ]
        );

        $this->em->flush();

        return $observedIoc;
    }

    /**
     * Extract IOCs from a message body using regex, LLM, or hybrid approach
     *
     * @param string             $msgId   Message ID
     * @param string             $method  Extraction method: 'regex', 'llm', or 'hybrid'
     * @param array              $types   IOC types to extract (empty = all types)
     * @param bool               $persist Whether to persist IOCs to database and return obs_id
     * @param array<int, string> $types
     *
     * @throws \RuntimeException if message not found
     *
     * @return array<int, array<string, mixed>> Array of IOCs found (with obs_id if $persist=true)
     */
    public function extractIocsFromMessage(string $msgId, string $method = 'hybrid', array $types = [], bool $persist = false): array
    {
        // Get message
        $message = $this->em->getRepository(Message::class)->find($msgId);

        if (!$message || $message->getDeletedAt() !== null) {
            throw new \RuntimeException("Message not found: {$msgId}");
        }

        // Get text content (body_text + subject)
        $text = $message->getSubject() . "\n\n" . $message->getBodyText();

        // Extract IOCs based on method
        $iocs = [];

        if ($method === 'regex' || $method === 'hybrid') {
            $iocs = array_merge($iocs, $this->extractIocsWithRegex($text, $types));
        }

        if ($method === 'llm' || $method === 'hybrid') {
            // Extract IOCs using LLM
            $llmIocs = $this->iocExtractor->extractIocsWithLLM($text, $types);

            // Validate and normalize each LLM-extracted IOC
            foreach ($llmIocs as $llmIoc) {
                $type = $llmIoc['type'];
                $value = $llmIoc['value'];

                // Validate
                if (!$this->validator->validate($type, $value)) {
                    continue; // Skip invalid IOCs
                }

                // Normalize
                $valueNorm = $this->normalizer->normalize($type, $value);

                $iocs[] = [
                    'type' => $type,
                    'value' => $value,
                    'value_norm' => $valueNorm,
                    'context' => [
                        'extraction_method' => 'llm',
                    ],
                ];
            }
        }

        // Deduplicate IOCs by type+value_norm
        $uniqueIocs = [];
        $seen = [];

        foreach ($iocs as $ioc) {
            /** @var string $iocType */
            $iocType = $ioc['type'] ?? '';
            /** @var string $iocValueNorm */
            $iocValueNorm = $ioc['value_norm'] ?? '';
            $key = $iocType . ':' . $iocValueNorm;

            if (!isset($seen[$key])) {
                $uniqueIocs[] = $ioc;
                $seen[$key] = true;
            }
        }

        // Persist IOCs if requested
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
                    'enrichment' => [], // No enrichment yet
                    'category' => 'Unknown',
                    'tags' => [],
                    'tlp' => 'AMBER',
                ];

                try {
                    $observedIoc = $this->upsertEnrichedIoc($payload);
                    $persistedIocs[] = [
                        'type' => $ioc['type'],
                        'value' => $ioc['value'],
                        'value_norm' => $ioc['value_norm'],
                        'context' => array_merge($ioc['context'], [
                            'obs_id' => $observedIoc->getObsId(),
                        ]),
                    ];
                } catch (\Exception $e) {
                    // Skip IOCs that fail to persist (e.g., validation errors)
                    continue;
                }
            }

            return $persistedIocs;
        }

        return $uniqueIocs;
    }

    /**
     * Extract IOCs using regex patterns
     *
     * @param string             $text  Text to extract IOCs from
     * @param array<int, string> $types IOC types to extract (empty = all)
     *
     * @return array<int, array<string, mixed>> Array of IOCs
     */
    private function extractIocsWithRegex(string $text, array $types = []): array
    {
        $iocs = [];

        // Define regex patterns for all IOC types
        $patterns = [
            // Infrastructure
            'ipv4' => '/\b(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\b/',
            'ipv6' => '/\b(([0-9a-fA-F]{1,4}:){7,7}[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,7}:|([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,5}(:[0-9a-fA-F]{1,4}){1,2}|([0-9a-fA-F]{1,4}:){1,4}(:[0-9a-fA-F]{1,4}){1,3}|([0-9a-fA-F]{1,4}:){1,3}(:[0-9a-fA-F]{1,4}){1,4}|([0-9a-fA-F]{1,4}:){1,2}(:[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:((:[0-9a-fA-F]{1,4}){1,6})|:((:[0-9a-fA-F]{1,4}){1,7}|:)|fe80:(:[0-9a-fA-F]{0,4}){0,4}%[0-9a-zA-Z]{1,}|::(ffff(:0{1,4}){0,1}:){0,1}((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])|([0-9a-fA-F]{1,4}:){1,4}:((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9]))\b/',
            'email' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
            'url' => '#\b(?:https?://|www\.)[^\s<>"{}|\\^\[\]`]+#i',
            'domain' => '/\b(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}\b/i',

            // Hashes
            'md5' => '/\b[a-f0-9]{32}\b/i',
            'sha1' => '/\b[a-f0-9]{40}\b/i',
            'sha256' => '/\b[a-f0-9]{64}\b/i',

            // Finance
            'iban' => '/\b[A-Z]{2}[0-9]{2}[A-Z0-9]{1,30}\b/',
            'bic' => '/\b[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?\b/',
            'wallet_btc' => '/\b(bc1|[13])[a-zA-HJ-NP-Z0-9]{25,62}\b/',
            'wallet_eth' => '/\b0x[a-fA-F0-9]{40}\b/',
            'wallet_xmr' => '/\b[48][0-9AB][1-9A-HJ-NP-Za-km-z]{93}\b/',
            'credit_card' => '/\b\d{4}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b/',

            // Contact channels
            'phone' => '/\b(?:\+?\d{1,3}[-.\s]?)?\(?\d{2,4}\)?[-.\s]?\d{2,4}[-.\s]?\d{2,4}(?:[-.\s]?\d{2,4})?\b/',
            'telegram_username' => '/\B@[a-zA-Z0-9_]{5,32}\b/',
            'discord_username' => '/\b.{2,32}#[0-9]{4}\b/',

            // Security identifiers
            'cve' => '/\bCVE-\d{4}-\d{4,}\b/i',
            'mitre_attack_id' => '/\bT\d{4}(?:\.\d{3})?\b/',
        ];

        // Filter patterns if specific types requested
        if (!empty($types)) {
            $patterns = array_intersect_key($patterns, array_flip($types));
        }

        foreach ($patterns as $type => $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[0] as $value) {
                    // Validate IOC
                    if (!$this->validator->validate($type, $value)) {
                        continue;
                    }

                    // Normalize value using IocNormalizer
                    $valueNorm = $this->normalizer->normalize($type, $value);

                    // Skip private/reserved IPs for ipv4
                    if ($type === 'ipv4' && $this->isPrivateIp($value)) {
                        continue;
                    }

                    $iocs[] = [
                        'type' => $type,
                        'value' => $value,
                        'value_norm' => $valueNorm,
                        'context' => [
                            'extraction_method' => 'regex',
                            'pattern' => $type,
                        ],
                    ];
                }
            }
        }

        return $iocs;
    }

    /**
     * Check if an IP is private/reserved
     */
    private function isPrivateIp(string $ip): bool
    {
        $long = ip2long($ip);

        if ($long === false) {
            return true;
        }

        // Private ranges: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 127.0.0.0/8
        return (
            ($long >= ip2long('10.0.0.0') && $long <= ip2long('10.255.255.255')) ||
            ($long >= ip2long('172.16.0.0') && $long <= ip2long('172.31.255.255')) ||
            ($long >= ip2long('192.168.0.0') && $long <= ip2long('192.168.255.255')) ||
            ($long >= ip2long('127.0.0.0') && $long <= ip2long('127.255.255.255'))
        );
    }
}

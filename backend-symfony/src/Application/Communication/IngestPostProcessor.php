<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Audit\AuditLogger;
use App\Application\Clustering\IocClusteringService;
use App\Application\LLM\ContextualEnricher;
use App\Application\LLM\ContextualEnrichmentRequest;
use App\Domain\Audit\AuditEventType;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Handles post-ingestion processing: IOC extraction, scam classification,
 * risk scoring, prompt injection detection, and rate limiting.
 *
 * Extracted from IngestHandler (decomposition).
 */
class IngestPostProcessor
{
    /**
     * Domains of known legitimate senders (platform notifications, etc.).
     * Messages from these domains still get ingested but skip classification
     * and get risk forced to 0 to avoid polluting scam metrics.
     *
     * @var list<string>
     */
    private const KNOWN_LEGITIMATE_DOMAINS = [
        'instagram.com', 'facebook.com', 'facebookmail.com', 'google.com',
        'linkedin.com', 'twitter.com', 'x.com', 'github.com', 'apple.com',
        'microsoft.com', 'amazon.com', 'paypal.com', 'netflix.com',
        'spotify.com', 'dropbox.com',
    ];

    /**
     * Spec 083 — technical mailbox local-parts that mean "automated mail,
     * not a human sender" regardless of domain. Matched case-insensitively
     * against the local-part extracted by Symfony Mime's RFC-2822 parser.
     *
     * @var list<string>
     */
    private const LOCAL_PART_PATTERNS = [
        'noreply', 'no-reply', 'postmaster', 'abuse',
        'mailer-daemon', 'dmarcreport', 'dmarc-noreply',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly IocHandler $iocHandler,
        private readonly ?IocContextService $iocContextService = null,
        private readonly ?PromptInjectionDetector $promptInjectionDetector = null,
        private readonly ?RateLimiterFactory $emailsPerSenderPerDayLimiter = null,
        private readonly ?SenderFloodDetector $senderFloodDetector = null,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?ScamClassificationHandler $scamClassifier = null,
        private readonly ?ContextualEnricher $contextualEnricher = null,
        private readonly ?IocClusteringService $iocClusteringService = null,
        private readonly ?RiskScoreCalculator $riskScoreCalculator = null,
        private readonly string $operatorTestSenders = '',
    ) {
    }

    /**
     * Spec 083 §US1+US2 — detect automated mail patterns BEFORE the
     * conversation enters the LLM classification + reply pipeline.
     *
     * Returns a structured {@see \App\Domain\Communication\PreFilterMatch}
     * describing which kind of pattern fired, or null if no pattern
     * matched (commercial B2B included — explicitly left to the
     * classifier per spec out-of-scope).
     *
     * The matching strategy, in order of specificity:
     *   1. operator-test sender (env CSV) — exact match
     *   2. local-part pattern   (noreply, postmaster, abuse, etc.)
     *   3. known legitimate domain (github.com, microsoft.com, ...)
     *   4. DMARC report subject pattern (Report Domain: ...)
     */
    public function matchPreFilter(string $fromHeader, string $subject): ?\App\Domain\Communication\PreFilterMatch
    {
        $email = $this->extractEmailFromHeader($fromHeader);

        if ($email !== null) {
            $emailLower = strtolower($email);
            $atPos = strrpos($email, '@');

            if ($atPos !== false) {
                $localPart = strtolower(substr($email, 0, $atPos));
                $domain = strtolower(substr($email, $atPos + 1));

                // 1. operator-test sender (highest specificity — exact email)
                if ($this->operatorTestSenders !== '') {
                    $opTests = array_map(
                        static fn (string $e): string => strtolower(trim($e)),
                        explode(',', $this->operatorTestSenders),
                    );

                    if (in_array($emailLower, $opTests, true)) {
                        return new \App\Domain\Communication\PreFilterMatch(
                            kind: \App\Domain\Communication\PreFilterMatch::KIND_OPERATOR_TEST,
                            pattern: $emailLower,
                        );
                    }
                }

                // 2. known legitimate domain (exact OR strict subdomain).
                //    Dominant signal — when a sender is "GitHub" we report
                //    "GitHub" rather than "noreply" even though both apply.
                foreach (self::KNOWN_LEGITIMATE_DOMAINS as $legit) {
                    if ($domain === $legit || str_ends_with($domain, '.' . $legit)) {
                        return new \App\Domain\Communication\PreFilterMatch(
                            kind: \App\Domain\Communication\PreFilterMatch::KIND_DOMAIN,
                            pattern: $legit,
                        );
                    }
                }

                // 3. local-part pattern (fallback when domain is unknown)
                if (in_array($localPart, self::LOCAL_PART_PATTERNS, true)) {
                    return new \App\Domain\Communication\PreFilterMatch(
                        kind: \App\Domain\Communication\PreFilterMatch::KIND_LOCAL_PART,
                        pattern: $localPart,
                    );
                }
            }
        }

        // 4. subject pattern (DMARC aggregate report)
        if (preg_match('/^\s*Report\s+Domain:/i', $subject) === 1) {
            return new \App\Domain\Communication\PreFilterMatch(
                kind: \App\Domain\Communication\PreFilterMatch::KIND_SUBJECT,
                pattern: 'dmarc_report',
            );
        }

        return null;
    }

    /**
     * Extract the first email address from an RFC 2822 From header.
     *
     * Replaces the legacy regex `/<([^>]+)>/` which missed several valid
     * shapes (bare address with no chevrons, unquoted display name,
     * address-lists). Uses Symfony Mime's Address::create which
     * implements RFC 2822 properly.
     */
    private function extractEmailFromHeader(string $fromHeader): ?string
    {
        if (trim($fromHeader) === '') {
            return null;
        }

        // Address-list: take the first element.
        $first = explode(',', $fromHeader)[0];

        try {
            return \Symfony\Component\Mime\Address::create($first)->getAddress();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Run all post-ingestion processing on a newly created message.
     *
     * Non-blocking: each step catches its own exceptions so ingestion is not affected.
     *
     * @param string $detectedLang the language detected during parsing (trigram-based)
     */
    public function processAfterIngest(Message $message, Conversation $conversation, string $detectedLang): void
    {
        $msgId = $message->getMsgId();

        $this->extractHeaderIocs($message, $msgId);
        $this->computeIocContext($msgId);
        $this->enrichIocContext($message, $msgId);

        // Spec 083 — pre-filter automated mail (DMARC, noreply, postmaster,
        // operator-test, known legitimate domains) BEFORE the LLM classifier
        // and reply pipeline run. Forces score_risk=0 (the chain downstream
        // — spec 084 /risk endpoint + n8n Decision Gate — refuses to reply
        // when score is low). The conversation keeps scam_type=UNKNOWN by
        // design: UNKNOWN remains the catch-all for "we didn't classify",
        // whether the cause is pre-filter or low LLM confidence.
        $fromHeader = $message->getHeaders()['from'] ?? null;
        $subject = $message->getSubject() ?? '';
        $fromHeaderStr = \is_string($fromHeader) ? $fromHeader : '';
        $preFilterMatch = $this->matchPreFilter($fromHeaderStr, $subject);

        if ($preFilterMatch !== null) {
            $this->logger->info('[IngestPostProcessor] Pre-filter hit, skipping classification', [
                'msg_id' => $msgId,
                'conv_id' => $conversation->getConvId(),
                'from' => $fromHeaderStr,
                'subject' => $subject,
                'kind' => $preFilterMatch->kind,
                'pattern' => $preFilterMatch->pattern,
            ]);

            $this->auditLogger?->log(
                AuditEventType::INGEST_PRE_FILTER_HIT,
                $conversation->getConvId(),
                'pre_filter_hit',
                'skipped',
                'message',
                $msgId,
                [
                    'from' => $fromHeaderStr,
                    'kind' => $preFilterMatch->kind,
                    'pattern' => $preFilterMatch->pattern,
                ],
            );

            // Spec 086 §US1 — persist the pre-filter decision as a structured
            // marker on the message itself. The /risk endpoint (spec 086 §US2)
            // consults this marker to short-circuit IOC-based scoring; without
            // it, body IOCs would still push score_agg medium and trigger
            // reply (the 2026-05-19 DMARC incident).
            $headers = $message->getHeaders();
            $headers['pre_filter'] = [
                'kind' => $preFilterMatch->kind,
                'pattern' => $preFilterMatch->pattern,
                'matched_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ];
            $message->setHeaders($headers);

            $conversation->updateRiskScore(0);
            $this->em->flush();

            return;
        }

        $this->autoClassifyScamType($message, $conversation, $detectedLang);
        $this->updateRiskScore($conversation, $message);
        $this->analyzePromptInjection($message, $conversation, $msgId);
        $this->clusterConversation($conversation);
    }

    /**
     * Extract header-based IOCs (from, reply-to, return-path, message-id, subject, spf/dkim/dmarc).
     */
    private function extractHeaderIocs(Message $message, string $msgId): void
    {
        try {
            $headerIocsCount = $this->iocHandler->extractAndUpsertHeaderIocs($message);
            $this->logger->info('[IngestPostProcessor] Header IOCs extracted', [
                'msg_id' => $msgId,
                'header_iocs_count' => $headerIocsCount,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[IngestPostProcessor] Failed to extract header IOCs', [
                'msg_id' => $msgId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Compute structural context for all IOCs of this message.
     * Non-blocking: failure does not affect ingestion. Batch command will retry.
     */
    private function computeIocContext(string $msgId): void
    {
        if (!$this->iocContextService instanceof \App\Application\Communication\IocContextService) {
            return;
        }

        try {
            // Collect all IOCs from this message
            $conn = $this->em->getConnection();
            $rows = $conn->fetchAllAssociative(
                'SELECT oi.obs_id, oi.indicator_id, i.type AS ioc_type'
                . ' FROM observed_ioc oi'
                . ' JOIN indicator i ON oi.indicator_id = i.indicator_id'
                . ' WHERE oi.msg_id = :msgId',
                ['msgId' => $msgId]
            );

            if ($rows === []) {
                return;
            }

            $obsIocData = [];

            foreach ($rows as $row) {
                $obsIocData[] = [
                    'obs_id' => \is_string($row['obs_id'] ?? null) ? $row['obs_id'] : '',
                    'indicator_id' => \is_string($row['indicator_id'] ?? null) ? $row['indicator_id'] : '',
                    'ioc_type' => \is_string($row['ioc_type'] ?? null) ? $row['ioc_type'] : '',
                ];
            }

            $this->iocContextService->computeAndPersistForMessage($msgId, $obsIocData);
        } catch (\Throwable $e) {
            $this->logger->warning('[IngestPostProcessor] IOC context computation failed', [
                'msg_id' => $msgId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * LLM-based contextual enrichment for IOCs (fail-safe).
     *
     * Loads ioc_context rows with status='structural', builds a ContextualEnrichmentRequest,
     * calls the ContextualEnricher, and updates rows with semantic fields.
     */
    private function enrichIocContext(Message $message, string $msgId): void
    {
        if (!$this->contextualEnricher instanceof \App\Application\LLM\ContextualEnricher) {
            return;
        }

        try {
            $conn = $this->em->getConnection();

            // Load ioc_context rows with status='structural' for this message
            $contextRows = $conn->fetchAllAssociative(
                'SELECT ic.id, ic.obs_id, ic.stimulus_msg_id, ic.revelation_turn, ic.total_turns,'
                . ' ic.scam_type_code, ic.persona_code,'
                . ' i.type AS ioc_type'
                . ' FROM ioc_context ic'
                . ' JOIN observed_ioc oi ON ic.obs_id = oi.obs_id'
                . ' JOIN indicator i ON ic.indicator_id = i.indicator_id'
                . ' WHERE oi.msg_id = :msgId'
                . ' AND ic.enrichment_status = \'structural\'',
                ['msgId' => $msgId]
            );

            if ($contextRows === []) {
                return;
            }

            // Collect IOC types
            $iocTypes = array_unique(array_map(
                fn (array $row): string => \is_string($row['ioc_type'] ?? null) ? $row['ioc_type'] : '',
                $contextRows
            ));
            $iocTypes = array_values(array_filter($iocTypes, fn (string $t): bool => $t !== ''));

            // Use first row for conversation-level data
            $firstRow = $contextRows[0];
            $scamType = \is_string($firstRow['scam_type_code'] ?? null) ? $firstRow['scam_type_code'] : 'UNKNOWN';
            $personaCode = \is_string($firstRow['persona_code'] ?? null) ? $firstRow['persona_code'] : 'generic_user';
            $revelationTurn = \is_numeric($firstRow['revelation_turn'] ?? null) ? (int) $firstRow['revelation_turn'] : 1;
            $totalTurns = \is_numeric($firstRow['total_turns'] ?? null) ? (int) $firstRow['total_turns'] : 1;

            // Load message texts
            $revelationText = $message->getBodyText();

            // Load stimulus message text
            $stimulusMsgId = \is_string($firstRow['stimulus_msg_id'] ?? null) ? $firstRow['stimulus_msg_id'] : null;
            $stimulusText = null;

            if ($stimulusMsgId !== null) {
                $stimulusText = $conn->fetchOne(
                    'SELECT body_text FROM message WHERE msg_id = :msgId AND deleted_at IS NULL',
                    ['msgId' => $stimulusMsgId]
                );
                $stimulusText = \is_string($stimulusText) ? $stimulusText : null;
            }

            // Load previous inbound text (before our stimulus)
            $previousInboundText = null;
            $convId = $message->getConversation()->getConvId();

            if ($stimulusMsgId !== null) {
                $prevInbound = $conn->fetchOne(
                    'SELECT m.body_text FROM message m'
                    . ' WHERE m.conv_id = :convId'
                    . ' AND m.direction = (SELECT dir_id FROM lkp_direction WHERE code = \'in\')'
                    . ' AND m.ts_msg < (SELECT ts_msg FROM message WHERE msg_id = :stimId AND deleted_at IS NULL)'
                    . ' AND m.deleted_at IS NULL'
                    . ' ORDER BY m.ts_msg DESC LIMIT 1',
                    ['convId' => $convId, 'stimId' => $stimulusMsgId]
                );
                $previousInboundText = \is_string($prevInbound) ? $prevInbound : null;
            }

            $request = new ContextualEnrichmentRequest(
                iocTypes: $iocTypes,
                scamType: $scamType,
                personaCode: $personaCode,
                revelationTurn: $revelationTurn,
                totalTurns: $totalTurns,
                revelationMessageText: $revelationText,
                stimulusMessageText: $stimulusText,
                previousInboundText: $previousInboundText,
            );

            $result = $this->contextualEnricher->enrich($request);

            if (!$result instanceof \App\Application\LLM\ContextualEnrichmentResult) {
                $this->logger->debug('[IngestPostProcessor] LLM enrichment returned null, keeping structural', [
                    'msg_id' => $msgId,
                ]);

                return;
            }

            // Update all ioc_context rows for this message
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            foreach ($contextRows as $row) {
                $iocType = \is_string($row['ioc_type'] ?? null) ? $row['ioc_type'] : '';
                $semanticRole = $result->iocRoles[$iocType] ?? 'UNKNOWN';

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
                    . ' WHERE id = :id',
                    [
                        'semanticRole' => $semanticRole,
                        'stimulusType' => $result->stimulusType,
                        'urgencyScore' => $result->urgencyScore,
                        'languageSwitch' => $result->languageSwitch ? 'true' : 'false',
                        'hesitationDetected' => $result->hesitationDetected ? 'true' : 'false',
                        'contextExcerpt' => $result->contextExcerpt,
                        'enrichmentConfidence' => $result->enrichmentConfidence,
                        'computedAt' => $now,
                        'id' => $row['id'],
                    ]
                );
            }

            $this->logger->info('[IngestPostProcessor] IOC context enriched via LLM', [
                'msg_id' => $msgId,
                'ioc_count' => \count($contextRows),
                'stimulus_type' => $result->stimulusType,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[IngestPostProcessor] LLM IOC context enrichment failed', [
                'msg_id' => $msgId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Auto-classify scam type (non-blocking -- keeps UNKNOWN on failure).
     */
    private function autoClassifyScamType(Message $message, Conversation $conversation, string $detectedLang): void
    {
        if (!$this->scamClassifier instanceof \App\Application\Communication\ScamClassificationHandler || strtoupper($conversation->getScamType()->getCode()) !== 'UNKNOWN') {
            return;
        }

        try {
            $classificationResult = $this->scamClassifier->classifyConversation($conversation->getConvId());
            $this->logger->info('[IngestPostProcessor] Auto-classification complete', [
                'conv_id' => $conversation->getConvId(),
                'scam_type' => $classificationResult->scamTypeCode,
                'confidence' => $classificationResult->confidence,
                'detected_language' => $classificationResult->detectedLanguage,
            ]);

            // Update message language with LLM-detected language (more accurate than trigrams)
            $llmLang = $classificationResult->detectedLanguage;

            if ($llmLang !== $detectedLang) {
                $message->setLangDetect($llmLang);
                $this->em->flush();
                $this->logger->info('[IngestPostProcessor] Language updated from LLM', [
                    'trigram' => $detectedLang,
                    'llm' => $llmLang,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[IngestPostProcessor] Auto-classification failed, keeping UNKNOWN', [
                'conv_id' => $conversation->getConvId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Compute and update risk score from scam type and extracted IOC types.
     */
    private function updateRiskScore(Conversation $conversation, Message $message): void
    {
        $initialRisk = $this->computeInitialRisk($conversation, $message);

        if ($initialRisk > $conversation->getScoreRisk()) {
            $conversation->updateRiskScore($initialRisk);
            $this->em->flush();
            $this->logger->info('[IngestPostProcessor] Risk score updated', [
                'conv_id' => $conversation->getConvId(),
                'risk' => $initialRisk,
            ]);
        }
    }

    /**
     * Prompt injection forensic analysis (non-blocking).
     */
    private function analyzePromptInjection(Message $message, Conversation $conversation, string $msgId): void
    {
        if (!$this->promptInjectionDetector instanceof \App\Application\Communication\PromptInjectionDetector) {
            return;
        }

        try {
            $analysis = $this->promptInjectionDetector->analyze($message);

            if ($analysis instanceof \App\Domain\Communication\PromptInjectionAnalysis) {
                $message->setInjectionAnalysis($analysis->toArray());
                $this->em->flush();
                $this->logger->info('[IngestPostProcessor] Prompt injection analysis complete', [
                    'msg_id' => $msgId,
                    'risk_score' => $analysis->getRiskScore(),
                    'high_risk' => $analysis->isHighRisk(),
                ]);

                if ($analysis->isHighRisk()) {
                    $this->auditLogger?->log(
                        AuditEventType::INJECTION_DETECTED,
                        $conversation->getConvId(),
                        'injection_detected',
                        'blocked',
                        'message',
                        $msgId,
                        [
                            'risk_score' => $analysis->getRiskScore(),
                            'conv_id' => $conversation->getConvId(),
                        ],
                    );
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('[IngestPostProcessor] Prompt injection analysis failed', [
                'msg_id' => $msgId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cluster the conversation based on shared HIGH-severity anchor IOCs.
     * Non-blocking: failure does not affect ingestion.
     */
    private function clusterConversation(Conversation $conversation): void
    {
        if (!$this->iocClusteringService instanceof \App\Application\Clustering\IocClusteringService) {
            return;
        }

        try {
            $this->iocClusteringService->clusterConversation($conversation->getConvId());
        } catch (\Throwable $e) {
            $this->logger->warning('[IngestPostProcessor] Clustering failed', [
                'conv_id' => $conversation->getConvId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check per-sender rate limits: daily cap + flood burst detection.
     * Returns true if rate limited (reply should be skipped).
     */
    public function checkSenderRateLimits(?string $fromHeader, string $convId): bool
    {
        if ($fromHeader === null || $fromHeader === '') {
            return false;
        }

        $senderEmail = preg_match('/<([^>]+)>/', $fromHeader, $m) ? $m[1] : $fromHeader;
        $senderHash = hash('sha256', strtolower($senderEmail));

        // 1. Flood detection (burst: 5 emails in 5 minutes)
        if ($this->senderFloodDetector instanceof \App\Application\Communication\SenderFloodDetector) {
            $flooded = $this->senderFloodDetector->recordAndCheck($senderHash);

            if ($flooded) {
                $this->logger->warning('[IngestPostProcessor] Sender flood detected', [
                    'sender_hash' => substr($senderHash, 0, 12),
                    'conv_id' => $convId,
                ]);

                $this->dispatchRateLimitAudit($senderHash, 'sender_flood', $convId);

                return true;
            }
        }

        // 2. Daily sender limit (10 emails/24h via Symfony rate limiter)
        if ($this->emailsPerSenderPerDayLimiter instanceof \Symfony\Component\RateLimiter\RateLimiterFactory) {
            $limiter = $this->emailsPerSenderPerDayLimiter->create($senderHash);
            $limit = $limiter->consume();

            if (!$limit->isAccepted()) {
                $this->logger->warning('[IngestPostProcessor] Sender daily rate limit exceeded', [
                    'sender_hash' => substr($senderHash, 0, 12),
                    'conv_id' => $convId,
                    'retry_after' => $limit->getRetryAfter()->format(\DATE_ATOM),
                ]);

                $this->dispatchRateLimitAudit($senderHash, 'sender_daily', $convId);

                return true;
            }
        }

        return false;
    }

    private function dispatchRateLimitAudit(string $senderHash, string $limitType, string $convId): void
    {
        if (!$this->auditLogger instanceof \App\Application\Audit\AuditLogger) {
            return;
        }

        $this->auditLogger->log(
            eventType: AuditEventType::RATE_LIMIT_EXCEEDED,
            actorId: substr($senderHash, 0, 12),
            action: 'rate_limit',
            outcome: 'blocked',
            resourceType: 'conversation',
            resourceId: $convId,
            details: ['limit_type' => $limitType],
            actorType: 'sender'
        );
    }

    /**
     * Compute initial risk score from scam type and extracted IOC types.
     *
     * Delegates to RiskScoreCalculator for the actual formula (DRY).
     */
    private function computeInitialRisk(Conversation $conversation, Message $message): int
    {
        $calculator = $this->riskScoreCalculator ?? new RiskScoreCalculator();

        $scamCode = $conversation->getScamType()->getCode();

        // Get IOCs for this message
        $iocs = $this->em->getRepository(ObservedIoc::class)
            ->findBy(['message' => $message]);

        $iocTypes = [];
        $urlCount = 0;

        foreach ($iocs as $ioc) {
            $context = $ioc->getContext();
            $type = $context['type'] ?? '';
            $iocTypes[$type] = true;

            if ($type === 'url') {
                ++$urlCount;
            }
        }

        return $calculator->compute($scamCode, $iocTypes, $urlCount);
    }
}

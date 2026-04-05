<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Audit\AuditLogger;
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
    ) {
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
        $this->autoClassifyScamType($message, $conversation, $detectedLang);
        $this->updateRiskScore($conversation, $message);
        $this->analyzePromptInjection($message, $conversation, $msgId);
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
        if ($this->iocContextService === null) {
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

            if (empty($rows)) {
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
     * Auto-classify scam type (non-blocking -- keeps UNKNOWN on failure).
     */
    private function autoClassifyScamType(Message $message, Conversation $conversation, string $detectedLang): void
    {
        if ($this->scamClassifier === null || strtoupper($conversation->getScamType()->getCode()) !== 'UNKNOWN') {
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
        if ($this->promptInjectionDetector === null) {
            return;
        }

        try {
            $analysis = $this->promptInjectionDetector->analyze($message);

            if ($analysis !== null) {
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
        if ($this->senderFloodDetector !== null) {
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
        if ($this->emailsPerSenderPerDayLimiter !== null) {
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
        if ($this->auditLogger === null) {
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
     */
    private function computeInitialRisk(Conversation $conversation, Message $message): int
    {
        $baseScores = [
            'PHISHING' => 40, 'PHISH_CREDENTIALS' => 45, 'PHISH_MALWARE' => 65,
            'INVOICE_FRAUD' => 60, 'CEO_FRAUD' => 70, 'ROMANCE' => 30,
            'TECH_SUPPORT' => 35, 'INVESTMENT' => 50, 'LOTTERY' => 30,
            'ADVANCE_FEE_419' => 40, 'JOB_OFFER' => 35, 'CHARITY' => 25,
            'UNKNOWN' => 30,
        ];

        $scamCode = $conversation->getScamType()->getCode();
        $score = $baseScores[$scamCode] ?? 30;

        // Get IOCs for this message
        $iocs = $this->em->getRepository(ObservedIoc::class)
            ->findBy(['message' => $message]);

        $iocTypes = [];

        foreach ($iocs as $ioc) {
            $context = $ioc->getContext();
            $type = $context['type'] ?? '';
            $iocTypes[$type] = true;
        }

        // Bonus for high-value IOC types
        if (isset($iocTypes['iban']) || isset($iocTypes['wallet_btc']) || isset($iocTypes['wallet_eth'])) {
            $score += 20;
        }

        if (isset($iocTypes['phone'])) {
            $score += 10;
        }

        if (isset($iocTypes['url'])) {
            $urlCount = \count(array_filter($iocs, fn ($i) => ($i->getContext()['type'] ?? '') === 'url'));
            $score += min($urlCount * 5, 15);
        }

        // Bonus for IOC diversity
        $score += min(\count($iocTypes) * 3, 15);

        return min($score, 100);
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Audit\AuditLogger;
use App\Application\LLM\ReplyOrchestrator;
use App\Application\Monitoring\LlmCostHandler;
use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Direction;
use App\Domain\Communication\Message;
use App\Domain\LLM\Exception\LlmBudgetExceededException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates reply generation, composition, and delivery.
 *
 * Delegates to:
 * - ReplyContextService: conversation context building, persona assignment, language detection
 * - ReplyCadenceService: kill switch, cadence, rate limits, safelist
 * - ReplyCompositionService: header composition, mark-as-sent, SMTP delivery
 */
class ReplyHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageHandler $messageHandler,
        private readonly ReplyOrchestrator $replyOrchestrator,
        private readonly ReplyContextService $contextService,
        private readonly ReplyCadenceService $cadenceService,
        private readonly ReplyCompositionService $compositionService,
        private readonly LoggerInterface $logger,
        private readonly ?AuditLogger $auditLogger = null,
        // Spec 065b — LLM monthly budget enforcement.
        private readonly ?LlmCostHandler $costHandler = null,
        private readonly string $budgetEnforcementMode = 'warning',
    ) {
    }

    /**
     * Get a message by ID (delegated to MessageHandler).
     */
    public function getMessage(string $msgId): ?Message
    {
        return $this->messageHandler->getMessage($msgId);
    }

    /**
     * Get conversation context for LLM generation.
     *
     * @return array<string, mixed>|null
     */
    public function getConversationContext(string $convId, int $messageLimit = 5): ?array
    {
        return $this->contextService->getConversationContext($convId, $messageLimit);
    }

    /**
     * Generate reply draft using LLM orchestrator.
     *
     * @return array<string, mixed>|null
     */
    public function generateReply(string $convId, string $lastMsgId, bool $force = false, string $reason = 'manual'): ?array
    {
        // getConversationContext() handles automatic classification if needed
        $context = $this->contextService->getConversationContext($convId);

        if (!$context) {
            return null;
        }

        $conversation = $this->em->getRepository(Conversation::class)->find($convId);

        if ($this->cadenceService->isKillSwitchActive()) {
            throw new \RuntimeException('Kill switch is active - all automated replies are halted');
        }

        // Spec 065b — Budget cap enforcement.
        // - mode 'enforce': throw LlmBudgetExceededException → HTTP 503
        // - mode 'warning': log a warning and proceed (used during the
        //   one-week telemetry validation window before flipping to enforce)
        // - cost handler unset: skip entirely (legacy DI compatibility)
        if ($this->costHandler instanceof \App\Application\Monitoring\LlmCostHandler && $this->costHandler->isLimitExceeded()) {
            if ($this->budgetEnforcementMode === 'enforce') {
                throw new LlmBudgetExceededException(
                    $this->costHandler->getCurrentMonthUsdSpent(),
                    $this->costHandler->getMonthlyLimitUsd(),
                );
            }
            $this->logger->warning('[ReplyHandler] LLM budget exceeded but enforcement mode is warning, allowing reply', [
                'current_usd' => $this->costHandler->getCurrentMonthUsdSpent(),
                'limit_usd' => $this->costHandler->getMonthlyLimitUsd(),
                'mode' => $this->budgetEnforcementMode,
            ]);
        }

        if ($conversation === null) {
            return null;
        }

        if ($conversation->getStatus()->value !== 'open') {
            throw new \RuntimeException('Cannot generate reply for closed conversation');
        }

        // Check cadence
        if (!$force && !$this->cadenceService->checkCadence($convId)) {
            throw new \RuntimeException('Cadence limit not met');
        }

        // Check Redis-backed rate limits
        if (!$force) {
            $rateLimitResult = $this->cadenceService->checkRateLimits($convId);

            if ($rateLimitResult !== null) {
                throw new \RuntimeException('Rate limit exceeded: ' . $rateLimitResult);
            }
        }

        $parentMessage = $this->messageHandler->getMessage($lastMsgId);

        if (!$parentMessage instanceof \App\Domain\Communication\Message) {
            return null;
        }

        // Detect language from last inbound message
        $detectedLanguage = $this->contextService->detectLanguageFromContext($context);
        $context['detected_language'] = $detectedLanguage;

        // Generate reply using LLM Orchestrator
        /** @var string $personaCode */
        $personaCode = $context['persona'];
        $llmResult = $this->replyOrchestrator->generate($context, $personaCode);

        // Check if reply was approved
        if (!$llmResult['approved']) {
            $this->logger->warning('LLM reply rejected', [
                'conversation_id' => $convId,
                'policy_flags' => $llmResult['policy_flags'],
                'validation_reasons' => $llmResult['validation_reasons'],
            ]);

            throw new \RuntimeException(
                'Reply rejected by LLM validation: ' . implode(', ', array_merge(
                    (array) $llmResult['policy_flags'],
                    (array) $llmResult['validation_reasons']
                ))
            );
        }

        // Log if fallback was used
        if (!empty($llmResult['fallback_used'])) {
            $this->logger->warning('LLM generation failed - using fallback placeholder', [
                'conversation_id' => $convId,
                'attempts' => $llmResult['attempts'],
                'policy_flags' => $llmResult['policy_flags'],
                'validation_reasons' => $llmResult['validation_reasons'],
            ]);
        }

        /** @var string $newReplyContent */
        $newReplyContent = $llmResult['text'];

        // Simple text and HTML versions (no conversation history needed - Gmail handles threading)
        $replyText = $newReplyContent;
        $replyHtml = '<div>' . nl2br(htmlspecialchars($newReplyContent, ENT_QUOTES, 'UTF-8')) . '</div>';

        // Determine recipient
        $toRaw = $parentMessage->getHeaders()['reply_to'] ?? $parentMessage->getHeaders()['from'] ?? null;

        if (!$toRaw) {
            throw new \RuntimeException('Cannot determine reply recipient');
        }
        /** @var string $to */
        $to = $toRaw;

        // Build subject
        $subject = $parentMessage->getSubject() ?? '';

        if (!preg_match('/^re:/i', $subject)) {
            $subject = 'Re: ' . $subject;
        }

        // Get parent Gmail message ID (provider_msg_id) for n8n Reply operation
        $parentGmailMsgId = $parentMessage->getProviderMsgId()
            ?? $parentMessage->getHeaders()['provider_msg_id']
            ?? null;

        // Create outgoing message
        $channelEmail = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $directionOut = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'out']);

        if (!$channelEmail || !$directionOut) {
            throw new \RuntimeException('Email channel or out direction not found');
        }

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $now = new \DateTimeImmutable();

        // Determine "from" = honeypot address (the "to" of the inbound message)
        $honeypotAddress = $parentMessage->getHeaders()['to']
            ?? $parentMessage->getHeaders()['delivered-to']
            ?? $conversation->getAccount()->getEndpoint();

        $headers = [
            'from' => $honeypotAddress,
            'to' => $to,
            'send_status' => 'draft',
            // LLM metadata for traceability and metrics
            'llm_model' => $llmResult['model'],
            'llm_persona' => $llmResult['persona'],
            'llm_approved' => $llmResult['approved'],
            'llm_fallback_used' => $llmResult['fallback_used'] ?? false,
            'llm_attempts' => $llmResult['attempts'],
            'llm_policy_flags' => $llmResult['policy_flags'],
            'llm_validation_reasons' => $llmResult['validation_reasons'],
            'llm_cost_estimate' => $llmResult['cost_estimate'],
        ];

        // Store pipeline trace if available
        if (isset($llmResult['pipeline_trace'])) {
            $headers['pipeline_trace'] = $llmResult['pipeline_trace'];
        }

        $message = new Message(
            $msgId,
            $conversation,
            $channelEmail,
            $directionOut,
            $context['detected_language'],
            $subject,
            $replyText,
            $replyHtml,
            $headers,
            bin2hex(random_bytes(32)), // composite_hash
            null, // vector_id
            $parentMessage, // reply_to
            $now,
            $now,
            null // deleted_at
        );

        $this->em->persist($message);
        $this->em->flush();

        // Audit trail: log REPLY_GENERATED event
        $this->auditLogger?->log(
            \App\Domain\Audit\AuditEventType::REPLY_GENERATED,
            $convId,
            'generate_reply',
            'success',
            'conversation',
            $convId,
            [
                'persona' => $personaCode,
                'model' => $llmResult['model'] ?? 'unknown',
                'cost' => $llmResult['cost_estimate'] ?? 0,
                'attempts' => $llmResult['attempts'] ?? 1,
                'detected_language' => $context['detected_language'],
                'fallback_used' => $llmResult['fallback_used'] ?? false,
            ],
        );

        return [
            'msg_id' => $msgId,
            'conv_id' => $convId,
            'to' => $to,
            'subject' => $subject,
            'draft' => [
                'text' => $replyText,
                'html' => $replyHtml,
            ],
            'meta' => [
                'persona' => $context['persona'],
                'safelist_eligible' => $this->cadenceService->checkSafelist($to),
                'generated_at' => $now->format(DATE_ATOM),
                'parent_gmail_msg_id' => $parentGmailMsgId,
            ],
        ];
    }

    /**
     * Compose headers for threaded email sending.
     *
     * @return array<string, mixed>|null
     */
    public function composeHeaders(string $msgId): ?array
    {
        return $this->compositionService->composeHeaders($msgId);
    }

    /**
     * Mark message as sent and store threading headers.
     *
     * @param array<string, mixed>|null $sentHeaders
     */
    public function markAsSent(
        string $msgId,
        string $provider,
        string $providerMsgId,
        \DateTimeImmutable $tsSent,
        ?array $sentHeaders = null,
        ?string $convId = null
    ): bool {
        return $this->compositionService->markAsSent($msgId, $provider, $providerMsgId, $tsSent, $sentHeaders, $convId);
    }

    /**
     * Send a reply email via Symfony Mailer (SMTP).
     *
     * @return array{success: bool, message_id: string, ts_sent: string}
     */
    public function sendEmail(string $msgId): array
    {
        return $this->compositionService->sendEmail($msgId);
    }
}

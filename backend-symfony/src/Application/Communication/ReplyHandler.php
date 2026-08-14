<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Audit\AuditLogger;
use App\Application\Communication\Exception\ReplyRefusedException;
use App\Application\LLM\ConversationAnalyzer;
use App\Application\LLM\ReplyOrchestrator;
use App\Application\Monitoring\LlmCostHandler;
use App\Application\Scambaiting\ConversationClosureService;
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
        // LLM monthly budget enforcement.
        private readonly ?LlmCostHandler $costHandler = null,
        private readonly string $budgetEnforcementMode = 'warning',
        // Conversation director stop-gate (nullable for DI/back-compat): when the
        // director judges the conversation burned, we close it instead of replying.
        private readonly ?ConversationAnalyzer $conversationAnalyzer = null,
        private readonly ?ConversationClosureService $closureService = null,
        // Redis ceiling enforcement, governed like the budget cap above.
        private readonly string $rateLimitEnforcementMode = 'warning',
        // Recipient and loop guards. Stateless and dependency-free, so it is
        // defaulted rather than made a required argument: existing manual
        // instantiations keep working and still get the guards.
        private readonly ReplyRecipientPolicy $recipientPolicy = new ReplyRecipientPolicy(),
        // Addresses we know are ours, from configuration. Combined with the mail
        // account's own address to check we are not replying to ourselves.
        /** @var list<string>|null */
        private readonly ?array $honeypotEmailAddresses = null,
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
     * Ask the conversation director whether the exchange should stop. Returns the
     * stop reason when the director judged the conversation burned, or null to
     * continue. Null-safe: a missing analyzer, too few messages, or any failure
     * returns null so replies are never blocked by this gate.
     *
     * @param array<string, mixed> $context
     */
    private function directorStopReason(array $context, string $personaCode): ?string
    {
        if (!$this->conversationAnalyzer instanceof ConversationAnalyzer) {
            return null;
        }

        /** @var array<array{direction: string, body_text: string, ts_msg: string, subject?: string}> $allMessages */
        $allMessages = is_array($context['last_messages'] ?? null) ? $context['last_messages'] : [];

        if (count($allMessages) < 2) {
            return null;
        }

        $scamType = $context['scam_type'] ?? 'unknown';
        $scamCode = is_string($scamType)
            ? $scamType
            : (is_array($scamType) && is_string($scamType['code'] ?? null) ? $scamType['code'] : 'unknown');

        /** @var array<array{type: string, value: string, category?: string}> $iocs */
        $iocs = is_array($context['extracted_iocs'] ?? null) ? $context['extracted_iocs'] : [];

        try {
            $analysis = $this->conversationAnalyzer->analyzeAndGenerateInstructions([
                'conversation_id' => is_string($context['conv_id'] ?? null) ? $context['conv_id'] : 'unknown',
                'scam_type' => $scamCode,
                'persona_code' => $personaCode,
                'all_messages' => $allMessages,
                'extracted_iocs' => $iocs,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[ReplyHandler] Director stop-gate analyzer failed, continuing', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $brief = $analysis['director'];

        if (!$brief->shouldContinue) {
            return $brief->stopReason !== '' ? $brief->stopReason : 'conversation burned';
        }

        return null;
    }

    /**
     * Generate reply draft using LLM orchestrator.
     *
     * @return array<string, mixed>|null
     */
    public function generateReply(string $convId, string $lastMsgId, bool $force = false, string $reason = 'manual', bool $bypassRateLimits = false): ?array
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

        // Budget cap enforcement.
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

        // Verrou A: alternation invariant.
        // Refuse to persist a new outbound when the conversation's latest non-deleted
        // message is already outbound. This is a domain invariant (not a policy), so
        // it is enforced unconditionally — force=true does NOT bypass it.
        // Returns a normal success response carrying the EXISTING outbound's data so
        // downstream callers (n8n) continue without crashing.
        $existingOutbound = $this->findLatestOutboundIfBlocking($convId);

        if ($existingOutbound instanceof \App\Domain\Communication\Message) {
            $this->logger->info('[ReplyHandler] Duplicate outbound suppressed', [
                'conv_id' => $convId,
                'existing_outbound_msg_id' => $existingOutbound->getMsgId(),
                'attempted_parent_msg_id' => $lastMsgId,
                'reason' => 'duplicate_outbound_suppressed',
                'force' => $force,
            ]);

            return $this->buildDuplicateSkippedResponse($existingOutbound, $convId);
        }

        // Check cadence
        if (!$force && !$this->cadenceService->checkCadence($convId)) {
            throw new \RuntimeException('Cadence limit not met');
        }

        // Redis-backed ceiling enforcement.
        // $force waives the reply spacing only. The ceilings are a separate,
        // cost-and-abuse concern and are waived only by $bypassRateLimits, which is
        // the operator's explicit full-override — the automatic flow never sets it.
        // - mode 'enforce': a breach refuses the reply
        // - mode 'warning': the breach is recorded by checkRateLimits() (log + audit
        //   event) and the reply proceeds — the measurement window before flipping
        //   to enforce, exactly as the budget cap above works
        if (!$bypassRateLimits) {
            $rateLimitResult = $this->cadenceService->checkRateLimits($convId);

            if ($rateLimitResult !== null) {
                if ($this->rateLimitEnforcementMode === 'enforce') {
                    throw new \RuntimeException('Rate limit exceeded: ' . $rateLimitResult);
                }

                $this->logger->warning('[ReplyHandler] Rate limit exceeded but enforcement mode is warning, allowing reply', [
                    'conv_id' => $convId,
                    'limit' => $rateLimitResult,
                    'mode' => $this->rateLimitEnforcementMode,
                ]);
            }
        }

        $parentMessage = $this->messageHandler->getMessage($lastMsgId);

        if (!$parentMessage instanceof \App\Domain\Communication\Message) {
            return null;
        }

        // RFC 3834 loop guard. Checked before the LLM call, not after: an
        // auto-responder ping-pong would otherwise burn a generation per round.
        $autoSubmitted = $this->recipientPolicy->autoSubmittedReason($parentMessage->getHeaders());

        if ($autoSubmitted !== null) {
            $this->logger->warning('[ReplyHandler] Refusing to reply to automated mail', [
                'conversation_id' => $convId,
                'parent_msg_id' => $lastMsgId,
                'reason' => $autoSubmitted,
            ]);

            throw new ReplyRefusedException('auto_submitted', 'Refusing to reply to automated mail: ' . $autoSubmitted);
        }

        // Detect language from last inbound message
        $detectedLanguage = $this->contextService->detectLanguageFromContext($context);
        $context['detected_language'] = $detectedLanguage;

        // Generate reply using LLM Orchestrator
        /** @var string $personaCode */
        $personaCode = $context['persona'];

        // Director stop-gate: if the conversation is burned (the correspondent
        // unmasked us as a bot or fully disengaged with no path to more intel),
        // close it instead of replying — replying would only reinforce the bot
        // tell and waste generations. Best-effort and null-safe: any analyzer
        // failure or a missing dependency falls through to normal generation.
        $stopReason = $this->directorStopReason($context, $personaCode);

        if ($stopReason !== null && $this->closureService instanceof ConversationClosureService) {
            $this->logger->info('[ReplyHandler] Director stop-gate: closing burned conversation instead of replying', [
                'conv_id' => $convId,
                'stop_reason' => $stopReason,
            ]);

            $this->closureService->closeConversation($convId, 'burned: ' . $stopReason, 'director', 'system');

            return null;
        }

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

        // Determine "from" = honeypot address (the "to" of the inbound message).
        // Prefer the parent's To/Delivered-To headers — that is what the scammer
        // saw. If both are missing (mass-mailing with empty To:, alias delivery,
        // parser miss), fall back to the MailAccount's own emailAddress.
        // Endpoint is the IMAP/SMTP hostname and is only a last-resort
        // fallback for legacy accounts; it is never a
        // valid RFC 2822 address and will be caught downstream by composeHeaders.
        $account = $conversation->getAccount();
        $honeypotAddress = $parentMessage->getHeaders()['to']
            ?? $parentMessage->getHeaders()['delivered-to']
            ?? $account->getEmailAddress()
            ?? $account->getEndpoint();

        // The self-reply guard compares against addresses we know are ours:
        // the mail account's own address and the configured honeypot list.
        // Deliberately NOT $honeypotAddress above — that one is derived from
        // the inbound headers, so checking it against the inbound `From:` would
        // compare two values the same attacker wrote. A `To:` naming a decoy
        // first (the parser keeps only the first address) defeated it.
        $to = $this->recipientPolicy->resolveRecipient(
            $parentMessage->getHeaders(),
            array_values(array_filter([
                $account->getEmailAddress(),
                ...$this->honeypotEmailAddresses ?? [],
            ], static fn (?string $a): bool => \is_string($a) && trim($a) !== '')),
        );

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

        // Audit trail: log REPLY_GENERATED event.
        // Include validator scores (naturalness/persona_fit/
        // ti_value/security_pass) and IOC likelihood for observability.
        /** @var array<string, int|bool|null>|null $validationScores */
        $validationScores = $llmResult['validation_scores'] ?? null;
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
                // Validator scores + IOC likelihood
                'naturalness' => $validationScores['naturalness'] ?? null,
                'persona_fit' => $validationScores['persona_fit'] ?? null,
                'ti_value' => $validationScores['ti_value'] ?? null,
                'security_pass' => $validationScores['security_pass'] ?? null,
                'ioc_likelihood' => $llmResult['ioc_likelihood'] ?? null,
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

    /**
     * Find the latest non-deleted message of the conversation and return it
     * if (and only if) it is outbound. Used by Verrou A to enforce the alternation
     * invariant before any LLM call. Returns null when generation may proceed.
     */
    private function findLatestOutboundIfBlocking(string $convId): ?Message
    {
        /** @var Message|null $latest */
        $latest = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Message::class, 'm')
            ->where('m.conversation = :convId')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('convId', $convId)
            ->orderBy('m.tsMsg', 'DESC')
            ->addOrderBy('m.msgId', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$latest instanceof Message) {
            return null;
        }

        return $latest->getDirection()->getCode() === 'out' ? $latest : null;
    }

    /**
     * Build a response payload that mirrors the shape of a successful
     * generation but carries the existing outbound's data and a duplicate_skipped flag.
     * Callers (n8n, frontend) keep working without branching on a special HTTP code.
     *
     * @return array<string, mixed>
     */
    private function buildDuplicateSkippedResponse(Message $existing, string $convId): array
    {
        $headers = $existing->getHeaders();
        $to = $headers['to'] ?? '';
        $bodyHtml = $existing->getBodyHtml() ?? '';

        return [
            'msg_id' => $existing->getMsgId(),
            'conv_id' => $convId,
            'to' => \is_string($to) ? $to : '',
            'subject' => $existing->getSubject() ?? '',
            'draft' => [
                'text' => $existing->getBodyText(),
                'html' => $bodyHtml,
            ],
            'meta' => [
                'duplicate_skipped' => true,
                'reason' => 'duplicate_outbound_suppressed',
                'generated_at' => $existing->getTsMsg()->format(DATE_ATOM),
            ],
        ];
    }
}

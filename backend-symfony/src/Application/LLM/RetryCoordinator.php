<?php

declare(strict_types=1);

namespace App\Application\LLM;

use App\Domain\LLM\ComponentTrace;
use App\Domain\LLM\PipelineTrace;
use Psr\Log\LoggerInterface;

/**
 * Owns the 3-attempt retry loop for reply generation.
 *
 * Extracted from ReplyOrchestrator::generate() to separate the
 * orchestration contract (what callers see) from the retry
 * implementation (this class).
 *
 * The coordinator calls each stage in order:
 *   1. Generate text via LLM
 *   2. PolicyGuard syntactic validation
 *   3. Operational leakage detection (optional)
 *   4. ReplyValidator semantic validation
 *   5. IOC likelihood scoring
 *
 * On failure at any stage, the coordinator retries up to MAX_ATTEMPTS
 * times, then falls back to a canned safe response.
 *
 * All private helpers (enrichContextWithDialogue, generateText,
 * buildPolicyFeedback, buildFallbackResponse, estimateTotalCost,
 * getModelName) live here because they are only used inside the
 * retry loop.
 */
final readonly class RetryCoordinator
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private Port\LLMClientInterface $llmClient,
        private PromptBuilder $promptBuilder,
        private PolicyGuard $policyGuard,
        private ReplyValidator $replyValidator,
        private IOCLikelihoodScorer $iocScorer,
        private LoggerInterface $logger,
        // Required: nullable silent-noop'd in prod for ~3 days (2026-06-29 fix).
        private PaymentInstigationGuard $paymentInstigationGuard,
        private int $iocThreshold = 60,
        private ?FallbackProvider $fallbackProvider = null,
        private ?CostEstimator $costEstimator = null,
        private ?OperationalLeakageDetector $leakDetector = null,
        private ?\App\Application\Audit\AuditLogger $auditLogger = null,
        private string $model = 'gpt-4o',
        // Deterministic signature stripper applied immediately
        // after each generateText() call, before PolicyGuard. Optional for
        // backward compat with legacy callers / unit tests; auto-wired in
        // production by Symfony's DI.
        private ?SignatureStripper $signatureStripper = null,
    ) {
    }

    /**
     * Execute the retry loop. Returns the same shape as the old
     * ReplyOrchestrator::generate().
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function execute(array $context, string $personaCode): array
    {
        $dialogue = [];
        /** @var string|null $detectedLanguage */
        $detectedLanguage = $context['detected_language'] ?? null;

        /** @var string $convId */
        $convId = $context['conv_id'] ?? '';
        /** @var array<string, string> $scamTypeData */
        $scamTypeData = $context['scam_type'] ?? [];
        $scamTypeLabel = $scamTypeData['code'] ?? 'unknown';
        $trace = new PipelineTrace($convId, $personaCode, $scamTypeLabel, $detectedLanguage ?? 'en');

        /** @var array<int, mixed> $lastMsgs */
        $lastMsgs = $context['last_messages'] ?? [];
        $messageCount = count($lastMsgs);
        // Loop-invariant identity, bundled once so the shared fallback/audit
        // helper takes one value instead of the same four scalars at every site.
        $ctx = new ReplyContext($convId, $personaCode, $detectedLanguage, $messageCount);
        // The only draft eligible for a last-resort "best-of-3" is one the
        // validator confirmed security-clean (security_pass=true) but rejected on
        // quality. A draft rejected for a security reason — or never security-
        // checked at all — is never sent (fail closed at the last layer).
        $bestSecurityPassedText = null;
        $fallbackProvider = $this->getFallbackProvider();

        // Evaluate payment anchoring ONCE per generation and share it
        // between prompt guidance (PromptBuilder stage objectives) and
        // enforcement (stage 2b below). One evaluation per generation —
        // not per attempt — both for cost and so guidance cannot flip
        // between attempts. A caller-provided flag is reused as-is.
        if (!\array_key_exists('payment_topic_anchored', $context)) {
            $context['payment_topic_anchored'] = $convId !== ''
                && $this->paymentInstigationGuard->isPaymentTopicAnchored($convId);
        }
        $paymentAnchored = (bool) $context['payment_topic_anchored'];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $this->logger->info("[RetryCoordinator] Attempt {$attempt}/" . self::MAX_ATTEMPTS, [
                'conversation_id' => $convId,
            ]);

            // --- Stage 1: Generate ---
            $enrichedContext = $this->enrichContextWithDialogue($context, $dialogue);
            $genStartTime = microtime(true);
            $generatedText = $this->generateText($enrichedContext, $personaCode);

            // Strip trailing signature blocks BEFORE downstream
            // validators see the text. The stripped text is what gets
            // validated, persisted, and sent — preventing DB/SMTP divergence.
            if ($this->signatureStripper !== null) {
                $stripResult = $this->signatureStripper->strip($generatedText, $convId);

                if ($stripResult->bytesRemoved > 0) {
                    $this->logger->info('[RetryCoordinator] signature stripped before validators', [
                        'conv_id' => $convId,
                        'attempt' => $attempt,
                        'bytes_removed' => $stripResult->bytesRemoved,
                        'patterns' => $stripResult->matchedPatterns,
                    ]);
                }
                $generatedText = $stripResult->textAfter;
            }

            $genDuration = microtime(true) - $genStartTime;

            if ($attempt === 1) {
                $trace->addComponent(ComponentTrace::ran('prompt_builder', round($genDuration * 1000, 2), [
                    'text_length' => strlen($generatedText),
                    'word_count' => WordCounter::count($generatedText),
                ], $this->costEstimator?->estimate('openai', $this->getModelName(), 500, $this->costEstimator->approximateTokens($generatedText))));
            }

            // Levenshtein guardrail: when the previous attempt
            // produced a structured correction (patch-mode), warn if the new
            // attempt diverges too far from the previous text. The LLM should
            // have applied a surgical fix; large diffs mean it ignored the
            // instruction. Does NOT block — monitoring only.
            $this->levenshteinGuardrail($dialogue, $generatedText, $convId, $attempt);

            $dialogue[] = ['role' => 'generator', 'attempt' => $attempt, 'text' => $generatedText];

            // --- Stage 2: PolicyGuard ---
            $policyConfig = PolicyGuardConfig::fromContext($context);
            $policyResult = $this->policyGuard->validate($generatedText, $policyConfig);
            $pgDuration = round((microtime(true) - $genStartTime) * 1000, 2);

            $trace->addComponent(ComponentTrace::ran('policy_guard', $pgDuration, [
                'approved' => $policyResult['approved'],
                'flags' => $policyResult['flags'],
                'attempt' => $attempt,
            ]));

            if (!$policyResult['approved']) {
                $feedback = $this->buildPolicyFeedback($policyResult['flags'], $policyConfig);
                $dialogue[] = ['role' => 'policy_guard', 'attempt' => $attempt, 'approved' => false, 'feedback' => $feedback];

                if ($attempt === self::MAX_ATTEMPTS) {
                    $msg = 'PolicyGuard hard rules failed after ' . self::MAX_ATTEMPTS . ' attempts';

                    return $this->finalizeWithFallback($trace, $attempt, $ctx, $dialogue, 'policy_guard', [$msg], $policyResult['flags'], [$msg]);
                }

                $this->emitReplyRetry($convId, 'policy_guard', $attempt, $personaCode, ['flags' => $policyResult['flags']]);

                continue;
            }

            // --- Stage 2b: Payment-instigation guard ---
            // Conv-anchored check: if the persona introduces SWIFT/IBAN/wire
            // transfer/bank account vocabulary BEFORE the operator has, reject
            // and retry. Preserves full extraction power once the operator has
            // mentioned payment-infrastructure (gate opens permanently per conv).
            // When the anchoring evaluation above says the operator already
            // raised the topic, the per-attempt check is redundant by
            // definition (verdict would be NO_OPERATOR_ALREADY_MENTIONED) —
            // skip it and save the judge call.
            $instigationResult = $paymentAnchored
                ? ['approved' => true, 'reason' => null]
                : $this->paymentInstigationGuard->check($generatedText, $convId);

            if (!$instigationResult['approved']) {
                $reason = $instigationResult['reason'] ?? 'payment_instigation_blocked';
                $dialogue[] = [
                    'role' => 'payment_instigation_guard',
                    'attempt' => $attempt,
                    'approved' => false,
                    'reason' => $reason,
                    // Corrective feedback rendered into the next attempt's
                    // prompt — without it the LLM regenerates the same
                    // violating ask and the 3-strike fallback is guaranteed.
                    'feedback' => 'Your previous reply introduced money/payment topics (bank transfer, account details, sending funds) that the correspondent has never raised. Do NOT mention money, payment, transfers, or where funds go. Ask about non-financial practicalities instead (timeline, process, identity, verification).',
                ];

                if ($attempt === self::MAX_ATTEMPTS) {
                    return $this->finalizeWithFallback(
                        $trace,
                        $attempt,
                        $ctx,
                        $dialogue,
                        'payment_instigation_guard',
                        [$reason],
                        [$reason],
                        ['Payment instigation guard failed after ' . self::MAX_ATTEMPTS . ' attempts'],
                    );
                }

                $this->emitReplyRetry($convId, 'payment_instigation_guard', $attempt, $personaCode, ['reason' => $reason]);

                continue;
            }

            // --- Stage 3: Operational leakage detection ---
            if ($this->leakDetector instanceof \App\Application\LLM\OperationalLeakageDetector) {
                $leakResult = $this->leakDetector->check($generatedText, $personaCode);

                if ($leakResult->leakDetected) {
                    $dialogue[] = ['role' => 'leak_detector', 'attempt' => $attempt, 'leak_detected' => true, 'reason' => $leakResult->reason];

                    $this->auditLogger?->log(
                        eventType: \App\Domain\Audit\AuditEventType::LLM_LEAK_BLOCKED,
                        actorId: $convId,
                        action: 'leak_detection',
                        outcome: 'blocked',
                        resourceType: 'reply',
                        resourceId: $convId,
                        details: ['layer' => 'second_llm', 'attempt' => $attempt, 'reason' => $leakResult->reason, 'persona_code' => $personaCode],
                        actorType: 'system',
                    );

                    if ($attempt === self::MAX_ATTEMPTS) {
                        $msg = 'LLM leak detector rejected all ' . self::MAX_ATTEMPTS . ' attempts';

                        return $this->finalizeWithFallback($trace, $attempt, $ctx, $dialogue, 'leak_detector', [$msg], ['operational_leak_detected'], [$msg]);
                    }

                    $this->emitReplyRetry($convId, 'leak_detector', $attempt, $personaCode, ['reason_detail' => $leakResult->reason]);

                    continue;
                }
            }

            // --- Stage 4: ReplyValidator (semantic LLM) ---
            $valStartTime = microtime(true);

            // Build the conversational context the validator
            // needs to perform identity-coherence checks. Extracted from the
            // existing LLM context (no new upstream coupling).
            $validatorContext = $this->buildValidatorContext($context);

            try {
                $validatorResult = $this->replyValidator->validate($generatedText, $personaCode, $validatorContext);
            } catch (\Throwable $e) {
                $this->logger->warning("[RetryCoordinator] Validator error attempt {$attempt}", [
                    'error' => $e->getMessage(),
                ]);
                $dialogue[] = ['role' => 'validator', 'attempt' => $attempt, 'approved' => false, 'reasons' => [$e->getMessage()]];

                // A validator exception leaves security unverified for this
                // attempt; fall back to a best-of-3 only if an EARLIER attempt was
                // confirmed security-clean, else the canned reply (fail closed).
                if ($attempt === self::MAX_ATTEMPTS && $bestSecurityPassedText !== null) { // @phpstan-ignore-line
                    break; // Use best-of-3
                }

                $this->emitReplyRetry($convId, 'validator_error', $attempt, $personaCode, ['error' => $e->getMessage()]);

                continue;
            }

            $valDuration = round((microtime(true) - $valStartTime) * 1000, 2);
            $trace->addComponent(ComponentTrace::ran('reply_validator', $valDuration, [
                'approved' => $validatorResult['approved'],
                'reasons' => $validatorResult['reasons'],
                'attempt' => $attempt,
            ], $this->costEstimator?->estimate('openai', 'gpt-4o-mini', 300, 50)));

            // Preserve the structured correction (problem_span /
            // replacement / rationale) in the dialogue entry so the next
            // generator attempt can render the patch-mode block.
            $dialogue[] = [
                'role' => 'validator',
                'attempt' => $attempt,
                'approved' => $validatorResult['approved'],
                'reasons' => $validatorResult['reasons'],
                'fix_suggestion' => $validatorResult['fix_suggestion'] ?? null,
                'correction' => $validatorResult['correction'] ?? null,
            ];

            if ($validatorResult['approved']) {
                // --- Stage 5: IOC likelihood scoring ---
                $iocScore = $this->iocScorer->score($generatedText, $context);

                $trace->attempts = $attempt;
                $trace->addComponent(ComponentTrace::ran('ioc_scorer', 0, [
                    'score' => $iocScore,
                    'threshold' => $this->iocThreshold,
                ]));

                // Gate on iocThreshold. Retry if score is too
                // low AND attempts remain. On the final attempt, accept the
                // reply anyway (a validator-approved-but-passive reply is
                // better than a canned fallback). The audit log
                // (ioc_likelihood field) makes the low score visible for
                // post-hoc monitoring even when we accept the passive reply.
                if ($iocScore < $this->iocThreshold && $attempt < self::MAX_ATTEMPTS) {
                    $dialogue[] = [
                        'role' => 'ioc_scorer',
                        'attempt' => $attempt,
                        'approved' => false,
                        'score' => $iocScore,
                        'threshold' => $this->iocThreshold,
                        'reason' => "IOC likelihood {$iocScore} below threshold {$this->iocThreshold} — reply too passive on threat intelligence collection",
                    ];
                    $this->logger->info('[RetryCoordinator] IOC threshold not met, retrying', [
                        'conversation_id' => $convId,
                        'attempt' => $attempt,
                        'ioc_score' => $iocScore,
                        'threshold' => $this->iocThreshold,
                    ]);
                    $this->emitReplyRetry($convId, 'ioc_threshold', $attempt, $personaCode, [
                        'ioc_score' => $iocScore,
                        'threshold' => $this->iocThreshold,
                    ]);

                    continue;
                }

                return [
                    'text' => $generatedText,
                    'approved' => true,
                    'policy_flags' => [],
                    'validation_reasons' => $validatorResult['reasons'],
                    'model' => $this->getModelName(),
                    'persona' => $personaCode,
                    'cost_estimate' => $this->estimateTotalCost($dialogue, $messageCount),
                    'attempts' => $attempt,
                    'ioc_likelihood' => $iocScore,
                    'fallback_used' => false,
                    'pipeline_trace' => $trace->toArray(),
                    // Surface validator scores for audit_log
                    // persistence (ReplyHandler picks them up). Keys are
                    // guaranteed present by ReplyValidator::validate() contract
                    // (see ValidationResult::toLegacyArray).
                    'validation_scores' => [
                        'naturalness' => $validatorResult['naturalness'],
                        'persona_fit' => $validatorResult['persona_fit'],
                        'ti_value' => $validatorResult['ti_value'],
                        'security_pass' => $validatorResult['security_pass'],
                    ],
                ];
            }

            // Validator rejected. Only a security-clean draft (rejected on
            // quality) may serve as a best-of-3; a security_pass=false rejection
            // is never eligible.
            if ($validatorResult['security_pass'] === true) {
                $bestSecurityPassedText = $generatedText;
            }

            if ($attempt === self::MAX_ATTEMPTS && $bestSecurityPassedText !== null) { // @phpstan-ignore-line
                break; // Use best-of-3
            }

            $this->emitReplyRetry($convId, 'validator', $attempt, $personaCode, ['reasons' => $validatorResult['reasons']]);
        }

        // --- Fallback: best-of-3 (security-clean only) or canned ---
        if ($bestSecurityPassedText !== null) {
            $trace->attempts = self::MAX_ATTEMPTS;

            return [
                'text' => $bestSecurityPassedText,
                'approved' => true,
                'fallback_used' => false,
                'policy_flags' => [],
                'validation_reasons' => ['Best-of-3: security-clean, validator rejected on quality'],
                'model' => $this->getModelName(),
                'persona' => $personaCode,
                'cost_estimate' => $this->estimateTotalCost($dialogue, $messageCount),
                'attempts' => self::MAX_ATTEMPTS,
                'ioc_likelihood' => 0,
                'pipeline_trace' => $trace->toArray(),
                // No validator approved → scores null
                'validation_scores' => null,
            ];
        }

        return $this->finalizeWithFallback(
            $trace,
            self::MAX_ATTEMPTS,
            $ctx,
            $dialogue,
            'validator',
            ['All ' . self::MAX_ATTEMPTS . ' attempts failed validation without a PolicyGuard-approved fallback'],
            [],
            ['All attempts failed'],
        );
    }

    /**
     * Emit REPLY_RETRY audit row each time a generation
     * attempt is rejected and the loop continues to the next attempt.
     * Provides DB-queryable per-attempt observability so operators can
     * answer "why did attempt 2 fail?" without parsing logs.
     *
     * @param array<string, mixed> $extras Reason-specific payload (flags,
     *                                     ioc_score, error message, …)
     */
    private function emitReplyRetry(string $convId, string $reason, int $attempt, string $personaCode, array $extras = []): void
    {
        $this->auditLogger?->log(
            eventType: \App\Domain\Audit\AuditEventType::REPLY_RETRY,
            actorId: 'orchestrator',
            action: 'retry_attempt',
            outcome: 'retry',
            resourceType: 'conversation',
            resourceId: $convId,
            details: array_merge([
                'attempt' => $attempt,
                'reason' => $reason,
                'persona_code' => $personaCode,
            ], $extras),
            actorType: 'system',
        );
    }

    /**
     * Emit REPLY_REJECTED audit row when all attempts
     * are exhausted at a gate and the canned fallback response is used.
     *
     * @param list<string> $reasons Human-readable rejection reasons
     */
    private function emitReplyRejected(string $convId, string $gate, int $attempts, string $personaCode, array $reasons): void
    {
        $this->auditLogger?->log(
            eventType: \App\Domain\Audit\AuditEventType::REPLY_REJECTED,
            actorId: 'orchestrator',
            action: 'reject_final',
            outcome: 'fallback',
            resourceType: 'conversation',
            resourceId: $convId,
            details: [
                'gate' => $gate,
                'attempts' => $attempts,
                'persona_code' => $personaCode,
                'reasons' => $reasons,
            ],
            actorType: 'system',
        );
    }

    // ─── Private helpers (moved from ReplyOrchestrator) ────────────

    private function getFallbackProvider(): FallbackProvider
    {
        return $this->fallbackProvider ?? new FallbackProvider();
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Levenshtein guardrail. When the most recent validator
     * entry in the dialogue carries a structured correction (= patch-mode
     * was requested), warn if the new generator attempt's text diverges
     * from the previous attempt by more than 50% (normalized Levenshtein
     * distance). The LLM should have applied a surgical fix; large diffs
     * mean it ignored the patch-mode instruction.
     *
     * Does NOT block the loop — purely monitoring/forensic log.
     *
     * @param array<int, array<string, mixed>> $dialogue
     */
    private function levenshteinGuardrail(array $dialogue, string $currentText, string $convId, int $attempt): void
    {
        // Find most recent generator + validator entries.
        $previousGenerator = null;
        $previousValidator = null;

        for ($i = \count($dialogue) - 1; $i >= 0; $i--) {
            $entry = $dialogue[$i];
            $role = $entry['role'] ?? '';

            if ($previousValidator === null && $role === 'validator') {
                $previousValidator = $entry;
            } elseif ($previousGenerator === null && $role === 'generator') {
                $previousGenerator = $entry;
            }

            if ($previousValidator !== null && $previousGenerator !== null) {
                break;
            }
        }

        if ($previousValidator === null || $previousGenerator === null) {
            return;
        }
        $correction = $previousValidator['correction'] ?? null;

        if (!\is_array($correction)) {
            return; // No patch-mode was issued; no guardrail to enforce.
        }
        $previousText = \is_string($previousGenerator['text'] ?? null) ? (string) $previousGenerator['text'] : '';

        if ($previousText === '' || $currentText === '') {
            return;
        }

        // levenshtein() in PHP is byte-based with a 255-char limit per
        // argument. For longer texts we approximate via similar_text()
        // (returns a percentage). Both yield a fitness measure in 0..1.
        $maxLen = max(\strlen($previousText), \strlen($currentText));

        if ($maxLen > 255) {
            similar_text($previousText, $currentText, $percent);
            $ratio = 1.0 - ($percent / 100.0);
        } else {
            $distance = levenshtein($previousText, $currentText);
            $ratio = $distance / $maxLen;
        }

        if ($ratio > 0.5) {
            $this->logger->warning('[RetryCoordinator] patch-mode ignored', [
                'conv_id' => $convId,
                'attempt' => $attempt,
                'diff_ratio' => round($ratio, 2),
                'previous_attempt_len' => \strlen($previousText),
                'current_attempt_len' => \strlen($currentText),
            ]);
        }
    }

    /**
     * Build the validator context dict from the existing LLM
     * context, extracting:
     *   - inbound_text: last inbound message body
     *   - inbound_from: from-header of that inbound
     *   - previous_outbound_messages: last 3 outbound messages
     *   - language: detected_language
     *
     * The keys 'direction' in $context['last_messages'] are strings 'in'/'out'
     * (see ConversationHistoryService line 253), NOT the DB-level integers 1/2.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function buildValidatorContext(array $context): array
    {
        /** @var array<int, array<string, mixed>> $lastMessages */
        $lastMessages = \is_array($context['last_messages'] ?? null) ? $context['last_messages'] : [];

        $inboundText = '';
        $inboundFrom = '';
        $honeypotEmail = '';
        $previousOutbound = [];

        foreach ($lastMessages as $msg) {
            $directionRaw = $msg['direction'] ?? null;
            $direction = \is_string($directionRaw) ? $directionRaw : '';

            if ($direction === 'in' && $inboundText === '') {
                // Capture the FIRST inbound encountered (typically newest).
                $bodyRaw = $msg['body_text'] ?? $msg['body'] ?? null;

                if (\is_string($bodyRaw)) {
                    $inboundText = $bodyRaw;
                }

                $headersRaw = $msg['headers'] ?? null;

                if (\is_array($headersRaw)) {
                    $fromRaw = $headersRaw['from'] ?? null;

                    if (\is_string($fromRaw)) {
                        $inboundFrom = $fromRaw;
                    }

                    // `to` header of the inbound is the honeypot
                    // mailbox the persona is replying from. Stays in memory
                    // only; never written to any committed file.
                    $toRaw = $headersRaw['to'] ?? null;

                    if (\is_string($toRaw)) {
                        $honeypotEmail = $toRaw;
                    }
                }
            } elseif ($direction === 'out' && \count($previousOutbound) < 3) {
                $bodyRaw = $msg['body_text'] ?? $msg['body'] ?? null;
                $previousOutbound[] = [
                    'body' => \is_string($bodyRaw) ? $bodyRaw : '',
                ];
            }
        }

        $languageRaw = $context['detected_language'] ?? null;

        return [
            'inbound_text' => $inboundText,
            'inbound_from' => $inboundFrom,
            'honeypot_email' => $honeypotEmail,
            'previous_outbound_messages' => $previousOutbound,
            'language' => \is_string($languageRaw) ? $languageRaw : 'en',
        ];
    }

    /**
     * @param array<string, mixed>             $context
     * @param array<int, array<string, mixed>> $dialogue
     *
     * @return array<string, mixed>
     */
    private function enrichContextWithDialogue(array $context, array $dialogue): array
    {
        if ($dialogue === []) {
            return $context;
        }

        $dialogueHistory = [];

        foreach ($dialogue as $entry) {
            /** @var string $role */
            $role = $entry['role'] ?? '';
            /** @var int $attempt */
            $attempt = $entry['attempt'] ?? 0;

            if ($role === 'generator') {
                /** @var string $text */
                $text = $entry['text'] ?? '';
                $dialogueHistory[] = ['role' => 'Generator (attempt ' . $attempt . ')', 'content' => $text];
            } elseif ($role === 'validator') {
                $approved = (bool) ($entry['approved'] ?? false);
                $content = $approved ? 'APPROVED' : 'REJECTED';

                if (!$approved) {
                    /** @var array<string> $reasons */
                    $reasons = is_array($entry['reasons'] ?? null) ? $entry['reasons'] : [];
                    $content .= "\nReasons: " . implode(', ', $reasons);

                    if (isset($entry['fix_suggestion']) && is_string($entry['fix_suggestion'])) {
                        $content .= "\nFix: " . $entry['fix_suggestion'];
                    }
                }
                $dialogueHistory[] = ['role' => 'Validator (attempt ' . $attempt . ')', 'content' => $content];
            } elseif ($role === 'policy_guard') {
                /** @var string $feedback */
                $feedback = $entry['feedback'] ?? '';
                $dialogueHistory[] = ['role' => 'PolicyGuard (attempt ' . $attempt . ')', 'content' => 'REJECTED - ' . $feedback];
            } elseif ($role === 'payment_instigation_guard') {
                /** @var string $feedback */
                $feedback = $entry['feedback'] ?? '';
                $dialogueHistory[] = ['role' => 'PaymentGuard (attempt ' . $attempt . ')', 'content' => 'REJECTED - ' . $feedback];
            }
        }

        $enrichedContext = $context;
        $enrichedContext['generation_dialogue'] = $dialogueHistory;

        // Find the most recent validator entry with a
        // structured correction and surface it as retry_correction so the
        // PromptBuilder can render the patch-mode block.
        for ($i = \count($dialogue) - 1; $i >= 0; $i--) {
            $entry = $dialogue[$i];

            if (($entry['role'] ?? '') !== 'validator') {
                continue;
            }
            $correction = $entry['correction'] ?? null;

            if (!\is_array($correction)) {
                continue;
            }
            $enrichedContext['retry_correction'] = $correction;

            break;
        }

        return $enrichedContext;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function generateText(array $context, string $personaCode): string
    {
        $prompts = $this->promptBuilder->buildGeneratorPrompts($context, $personaCode);
        $messages = [
            ['role' => 'system', 'content' => $prompts['system']],
            ['role' => 'user', 'content' => $prompts['user']],
        ];
        $options = [
            'model' => $this->getModelName(),
            'temperature' => 0.6,
            'max_tokens' => 400,
            'purpose' => 'reply_generation',
            'conversation_id' => $context['conv_id'] ?? null,
        ];

        return trim($this->llmClient->chat($messages, $options));
    }

    /**
     * @param array<string> $flags
     */
    private function buildPolicyFeedback(array $flags, ?PolicyGuardConfig $config = null): string
    {
        $config ??= PolicyGuardConfig::default();
        $messages = [];

        foreach ($flags as $flag) {
            if (str_starts_with($flag, 'too_short:')) {
                // Flag shape: too_short:{count}_words_min_{min}. Quantitative
                // feedback is what makes the retry correctable — the LLM
                // repeats the same length mistake unless told the achieved
                // count and the exact floor.
                $achieved = preg_match('/^too_short:(\d+)_words_min_\d+$/', $flag, $m) === 1
                    ? "it had {$m[1]} words"
                    : 'it was too short';
                $messages[] = "Reply too short: {$achieved}, but the minimum is {$config->minWords}."
                    . " Write at least {$config->minWords} words while keeping the persona's tone.";
            } elseif (str_starts_with($flag, 'too_long:')) {
                $achieved = preg_match('/^too_long:(\d+)_words$/', $flag, $m) === 1
                    ? "it had {$m[1]} words"
                    : 'it was too long';
                $messages[] = "Reply too long: {$achieved}, but the maximum is {$config->maxWords}."
                    . " Write at most {$config->maxWords} words.";
            } elseif (str_starts_with($flag, 'forbidden_pattern:')) {
                $word = substr($flag, strlen('forbidden_pattern:'));
                $messages[] = "Forbidden word detected: '$word' (never use this word)";
            } elseif (str_starts_with($flag, 'excessive_links:')) {
                $messages[] = 'Too many links (maximum 1 allowed)';
            } elseif ($flag === 'pii_detected') {
                $messages[] = 'Sensitive personal data detected (IBAN, phone number, address)';
            } elseif (str_starts_with($flag, 'out_of_band_channel:')) {
                // The reply tried to move the conversation off the email thread — a phone number,
                // a messaging handle/link, an email address, or a crypto wallet. Without an
                // actionable reason the generator repeats the same leak across every attempt and
                // burns to the fallback, so tell it exactly what to drop and to stay on the thread.
                $messages[] = 'Out-of-band contact channel detected (phone, messaging handle/link, email address, or crypto wallet). '
                    . 'Remove it entirely and keep the whole exchange on this email thread.';
            }
        }

        return implode('; ', $messages);
    }

    /**
     * @param array<string>                    $policyFlags
     * @param array<string>                    $validationReasons
     * @param array<int, array<string, mixed>> $dialogue
     *
     * @return array<string, mixed>
     */
    private function buildFallbackResponse(
        array $policyFlags,
        array $validationReasons,
        string $personaCode,
        int $attempts,
        array $dialogue,
        ?string $detectedLanguage = null,
        int $msgCount = 0,
        string $convId = '',
    ): array {
        $fb = $this->getFallbackProvider();

        // Rotate the fallback per turn, offset per conversation, so a canned line
        // is never sent twice in a row nor shared byte-identical across
        // conversations (both are bot tells).
        return [
            'text' => $fb->getFallback($detectedLanguage, $convId, $msgCount),
            'approved' => true,
            'fallback_used' => true,
            'policy_flags' => $policyFlags,
            'validation_reasons' => $validationReasons,
            'model' => $this->getModelName(),
            'persona' => $personaCode,
            'cost_estimate' => $this->estimateTotalCost($dialogue, $msgCount),
            'attempts' => $attempts,
            // No validator scores available in fallback path
            'validation_scores' => null,
            'ioc_likelihood' => null,
        ];
    }

    /**
     * Final-attempt guard failure: record the trace, emit the REPLY_REJECTED row,
     * build the canned fallback and stamp the trace onto it. This is the block that
     * every hard guard (and the post-loop validator failure) repeated verbatim.
     *
     * @param array<int, array<string, mixed>> $dialogue
     * @param list<string>                     $emitReasons     REPLY_REJECTED audit reasons
     * @param array<string>                    $fallbackFlags   policy_flags on the response
     * @param array<string>                    $fallbackReasons validation_reasons on the response
     *
     * @return array<string, mixed>
     */
    private function finalizeWithFallback(
        PipelineTrace $trace,
        int $attempt,
        ReplyContext $ctx,
        array $dialogue,
        string $stage,
        array $emitReasons,
        array $fallbackFlags,
        array $fallbackReasons,
    ): array {
        $trace->attempts = $attempt;
        $trace->fallbackUsed = true;
        $this->emitReplyRejected($ctx->convId, $stage, $attempt, $ctx->personaCode, $emitReasons);
        $result = $this->buildFallbackResponse(
            $fallbackFlags,
            $fallbackReasons,
            $ctx->personaCode,
            $attempt,
            $dialogue,
            $ctx->detectedLanguage,
            $ctx->messageCount,
            $ctx->convId,
        );
        $result['pipeline_trace'] = $trace->toArray();

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $dialogue
     */
    private function estimateTotalCost(array $dialogue, int $messageCount = 0): float
    {
        if (!$this->costEstimator instanceof \App\Application\LLM\CostEstimator) {
            return 0.0;
        }

        $totalCost = 0.0;
        $model = $this->getModelName();

        foreach ($dialogue as $entry) {
            if (($entry['role'] ?? '') === 'generator') {
                /** @var string $text */
                $text = $entry['text'] ?? '';
                $outputTokens = $this->costEstimator->approximateTokens($text);
                $totalCost += $this->costEstimator->estimate('openai', $model, 500, $outputTokens);
            } elseif (($entry['role'] ?? '') === 'validator') {
                $totalCost += $this->costEstimator->estimate('openai', 'gpt-4o-mini', 300, 50);
            }
        }

        if ($messageCount >= 2) {
            $totalCost += $this->costEstimator->estimate('openai', $model, 2000, 1500);
        }

        return round($totalCost, 6);
    }

    private function getModelName(): string
    {
        return $this->model;
    }
}

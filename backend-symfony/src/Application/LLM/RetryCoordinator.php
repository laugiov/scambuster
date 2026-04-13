<?php

declare(strict_types=1);

namespace App\Application\LLM;

use App\Domain\LLM\ComponentTrace;
use App\Domain\LLM\PipelineTrace;
use Psr\Log\LoggerInterface;

/**
 * Spec 065h — Owns the 3-attempt retry loop for reply generation.
 *
 * Extracted from ReplyOrchestrator::generate() to separate the
 * orchestration contract (what callers see) from the retry
 * implementation (this class).
 *
 * The coordinator calls each stage in order:
 *   1. Generate text via LLM
 *   2. PolicyGuard syntactic validation
 *   3. Operational leakage detection (spec 065d, optional)
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
final class RetryCoordinator
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly Port\LLMClientInterface $llmClient,
        private readonly PromptBuilder $promptBuilder,
        private readonly PolicyGuard $policyGuard,
        private readonly ReplyValidator $replyValidator,
        private readonly IOCLikelihoodScorer $iocScorer,
        private readonly LoggerInterface $logger,
        private readonly int $iocThreshold = 60,
        private readonly ?FallbackProvider $fallbackProvider = null,
        private readonly ?CostEstimator $costEstimator = null,
        private readonly ?OperationalLeakageDetector $leakDetector = null,
        private readonly ?\App\Application\Audit\AuditLogger $auditLogger = null,
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

        $messageCount = count($context['last_messages'] ?? []);
        $bestPolicyApprovedText = null;
        $fallback = $this->getFallbackProvider();

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $this->logger->info("[RetryCoordinator] Attempt {$attempt}/" . self::MAX_ATTEMPTS, [
                'conversation_id' => $convId,
            ]);

            // --- Stage 1: Generate ---
            $enrichedContext = $this->enrichContextWithDialogue($context, $dialogue);
            $genStartTime = microtime(true);
            $generatedText = $this->generateText($enrichedContext, $personaCode);
            $genDuration = microtime(true) - $genStartTime;

            if ($attempt === 1) {
                $trace->addComponent(ComponentTrace::ran('prompt_builder', round($genDuration * 1000, 2), [
                    'text_length' => strlen($generatedText),
                    'word_count' => str_word_count($generatedText),
                ], $this->costEstimator?->estimate('openai', $this->getModelName(), 500, $this->costEstimator->approximateTokens($generatedText))));
            }

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
                $feedback = $this->buildPolicyFeedback($policyResult['flags']);
                $dialogue[] = ['role' => 'policy_guard', 'attempt' => $attempt, 'approved' => false, 'feedback' => $feedback];

                if ($attempt === self::MAX_ATTEMPTS) {
                    $trace->attempts = $attempt;
                    $trace->fallbackUsed = true;
                    $result = $this->buildFallbackResponse(
                        $policyResult['flags'],
                        ['PolicyGuard hard rules failed after ' . self::MAX_ATTEMPTS . ' attempts'],
                        $personaCode,
                        $attempt,
                        $dialogue,
                        $detectedLanguage,
                        $messageCount,
                    );
                    $result['pipeline_trace'] = $trace->toArray();

                    return $result;
                }

                continue;
            }

            $bestPolicyApprovedText = $generatedText;

            // --- Stage 3: Operational leakage detection (spec 065d) ---
            if ($this->leakDetector !== null) {
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
                        $trace->attempts = $attempt;
                        $trace->fallbackUsed = true;
                        $result = $this->buildFallbackResponse(
                            ['operational_leak_detected'],
                            ['LLM leak detector rejected all ' . self::MAX_ATTEMPTS . ' attempts'],
                            $personaCode,
                            $attempt,
                            $dialogue,
                            $detectedLanguage,
                            $messageCount,
                        );
                        $result['pipeline_trace'] = $trace->toArray();

                        return $result;
                    }

                    continue;
                }
            }

            // --- Stage 4: ReplyValidator (semantic LLM) ---
            $valStartTime = microtime(true);

            try {
                $validatorResult = $this->replyValidator->validate($generatedText, $personaCode);
            } catch (\Throwable $e) {
                $this->logger->warning("[RetryCoordinator] Validator error attempt {$attempt}", [
                    'error' => $e->getMessage(),
                ]);
                $dialogue[] = ['role' => 'validator', 'attempt' => $attempt, 'approved' => false, 'reasons' => [$e->getMessage()]];

                if ($attempt === self::MAX_ATTEMPTS && $bestPolicyApprovedText !== null) { // @phpstan-ignore-line
                    break; // Use best-of-3
                }

                continue;
            }

            $valDuration = round((microtime(true) - $valStartTime) * 1000, 2);
            $trace->addComponent(ComponentTrace::ran('reply_validator', $valDuration, [
                'approved' => $validatorResult['approved'],
                'reasons' => $validatorResult['reasons'],
                'attempt' => $attempt,
            ], $this->costEstimator?->estimate('openai', 'gpt-4o-mini', 300, 50)));

            $dialogue[] = [
                'role' => 'validator',
                'attempt' => $attempt,
                'approved' => $validatorResult['approved'],
                'reasons' => $validatorResult['reasons'],
                'fix_suggestion' => $validatorResult['fix_suggestion'] ?? null,
            ];

            if ($validatorResult['approved']) {
                // --- Stage 5: IOC likelihood scoring ---
                $iocScore = $this->iocScorer->score($generatedText, $context);

                $trace->attempts = $attempt;
                $trace->addComponent(ComponentTrace::ran('ioc_scorer', 0, [
                    'score' => $iocScore,
                    'threshold' => $this->iocThreshold,
                ]));

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
                ];
            }

            // Validator rejected — retry
            if ($attempt === self::MAX_ATTEMPTS && $bestPolicyApprovedText !== null) { // @phpstan-ignore-line
                break; // Use best-of-3
            }
        }

        // --- Fallback: best-of-3 or canned ---
        if ($bestPolicyApprovedText !== null) {
            $trace->attempts = self::MAX_ATTEMPTS;

            return [
                'text' => $bestPolicyApprovedText,
                'approved' => true,
                'fallback_used' => false,
                'policy_flags' => [],
                'validation_reasons' => ['Best-of-3: PolicyGuard-approved, validator rejected'],
                'model' => $this->getModelName(),
                'persona' => $personaCode,
                'cost_estimate' => $this->estimateTotalCost($dialogue, $messageCount),
                'attempts' => self::MAX_ATTEMPTS,
                'ioc_likelihood' => 0,
                'pipeline_trace' => $trace->toArray(),
            ];
        }

        $trace->attempts = self::MAX_ATTEMPTS;
        $trace->fallbackUsed = true;
        $result = $this->buildFallbackResponse(
            [],
            ['All attempts failed'],
            $personaCode,
            self::MAX_ATTEMPTS,
            $dialogue,
            $detectedLanguage,
            $messageCount,
        );
        $result['pipeline_trace'] = $trace->toArray();

        return $result;
    }

    // ─── Private helpers (moved from ReplyOrchestrator) ────────────

    private function getFallbackProvider(): FallbackProvider
    {
        return $this->fallbackProvider ?? new FallbackProvider();
    }

    /**
     * @param array<string, mixed>             $context
     * @param array<int, array<string, mixed>> $dialogue
     *
     * @return array<string, mixed>
     */
    private function enrichContextWithDialogue(array $context, array $dialogue): array
    {
        if (empty($dialogue)) {
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
            }
        }

        $enrichedContext = $context;
        $enrichedContext['generation_dialogue'] = $dialogueHistory;

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
    private function buildPolicyFeedback(array $flags): string
    {
        $messages = [];

        foreach ($flags as $flag) {
            if (str_starts_with($flag, 'too_short:')) {
                $messages[] = 'Texte trop court (minimum 50 mots requis)';
            } elseif (str_starts_with($flag, 'too_long:')) {
                $messages[] = 'Texte trop long (maximum 150 mots autorisés)';
            } elseif (str_starts_with($flag, 'forbidden_pattern:')) {
                $word = substr($flag, strlen('forbidden_pattern:'));
                $messages[] = "Mot interdit détecté: '$word' (ne jamais utiliser ce mot)";
            } elseif (str_starts_with($flag, 'excessive_links:')) {
                $messages[] = 'Trop de liens (maximum 1 autorisé)';
            } elseif ($flag === 'pii_detected') {
                $messages[] = 'Données personnelles sensibles détectées (IBAN, téléphone, adresse)';
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
    ): array {
        $fb = $this->getFallbackProvider();

        return [
            'text' => $fb->getFallback($detectedLanguage),
            'approved' => true,
            'fallback_used' => true,
            'policy_flags' => $policyFlags,
            'validation_reasons' => $validationReasons,
            'model' => $this->getModelName(),
            'persona' => $personaCode,
            'cost_estimate' => $this->estimateTotalCost($dialogue, $msgCount),
            'attempts' => $attempts,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $dialogue
     */
    private function estimateTotalCost(array $dialogue, int $messageCount = 0): float
    {
        if ($this->costEstimator === null) {
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
        return 'gpt-4o';
    }
}

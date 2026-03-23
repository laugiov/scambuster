<?php

declare(strict_types=1);

namespace App\Application\LLM;

use App\Application\LLM\Port\LLMClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates LLM reply generation with iterative validation and IOC likelihood scoring
 *
 * Implements a dialogue between generator and validator where:
 * - Generator creates text
 * - Validator provides feedback
 * - Generator adjusts based on feedback (with full context)
 * - IOC likelihood is scored (0-100)
 * - Process repeats until approved or max retries reached
 */
final class ReplyOrchestrator
{
    private const MAX_ATTEMPTS = 3;
    private const DEFAULT_IOC_THRESHOLD = 60;

    /** @var string Placeholder text to use when all generation attempts fail */
    private const FALLBACK_PLACEHOLDER = "Bonjour,\n\nMerci pour votre message. J'ai bien reçu votre email et je vais regarder ça de plus près. Je vais prendre le temps de bien examiner les informations que vous m'avez envoyées et je reviens vers vous dès que possible avec une réponse détaillée.\n\nEn attendant, n'hésitez pas si vous avez d'autres questions ou informations à me communiquer.\n\nBonne journée";

    public function __construct(
        private readonly LLMClientInterface $llmClient,
        private readonly PromptBuilder $promptBuilder,
        private readonly PolicyGuard $policyGuard,
        private readonly ReplyValidator $replyValidator,
        private readonly IOCLikelihoodScorer $iocScorer,
        private readonly LoggerInterface $logger,
        private readonly int $iocThreshold = self::DEFAULT_IOC_THRESHOLD
    ) {
    }

    /**
     * Generate and validate a reply with iterative refinement and IOC scoring
     *
     * @param array<string, mixed> $context     Conversation context
     * @param string               $personaCode Persona code
     *
     * @return array<string, mixed>
     */
    public function generate(array $context, string $personaCode): array
    {
        $startTime = microtime(true);
        $dialogue = []; // Historique complet du dialogue générateur ↔ validateur

        $this->logger->info('[ReplyOrchestrator] ═══════════════════════════════════════════════════════', [
            'conversation_id' => $context['conv_id'],
        ]);
        $this->logger->info('[ReplyOrchestrator] 🚀 STARTING REPLY GENERATION ORCHESTRATION', [
            'conversation_id' => $context['conv_id'],
            'persona' => $personaCode,
            'max_attempts' => self::MAX_ATTEMPTS,
            'ioc_threshold' => $this->iocThreshold,
            'message_count' => count($context['last_messages'] ?? []),
        ]);

        try {
            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
                $this->logger->info('[ReplyOrchestrator] ─────────────────────────────────────────────────────────', [
                    'conversation_id' => $context['conv_id'],
                ]);
                $this->logger->info("[ReplyOrchestrator] 🔄 ATTEMPT {$attempt}/" . self::MAX_ATTEMPTS, [
                    'conversation_id' => $context['conv_id'],
                    'has_previous_dialogue' => !empty($dialogue),
                    'dialogue_entries_count' => count($dialogue),
                ]);

                // === ÉTAPE 1: Génération avec contexte du dialogue précédent ===
                $enrichedContext = $this->enrichContextWithDialogue($context, $dialogue);

                $this->logger->debug("[ReplyOrchestrator] [ATTEMPT {$attempt}] Step 1: Calling LLM Generator", [
                    'conversation_id' => $context['conv_id'],
                    'persona' => $personaCode,
                    'context_enriched_with_dialogue' => !empty($dialogue),
                ]);

                $genStartTime = microtime(true);
                $generatedText = $this->generateText($enrichedContext, $personaCode);
                $genDuration = microtime(true) - $genStartTime;

                // Enregistrer la tentative du générateur
                $dialogue[] = [
                    'role' => 'generator',
                    'attempt' => $attempt,
                    'text' => $generatedText,
                ];

                $this->logger->info("[ReplyOrchestrator] [ATTEMPT {$attempt}] ✅ LLM Generator returned text", [
                    'conversation_id' => $context['conv_id'],
                    'attempt' => $attempt,
                    'generation_duration_ms' => round($genDuration * 1000, 2),
                    'text_length' => strlen($generatedText),
                    'word_count' => str_word_count($generatedText),
                    'preview_first_100_chars' => substr($generatedText, 0, 100) . '...',
                ]);

                $this->logger->debug("[ReplyOrchestrator] [ATTEMPT {$attempt}] Generated text stats", [
                    'conversation_id' => $context['conv_id'],
                    'attempt' => $attempt,
                    'text_length' => strlen($generatedText),
                ]);

                // === ÉTAPE 2: Validation PolicyGuard (règles dures) ===
                $this->logger->debug("[ReplyOrchestrator] [ATTEMPT {$attempt}] Step 2: Calling PolicyGuard (syntactic validation)", [
                    'conversation_id' => $context['conv_id'],
                ]);

                $policyResult = $this->policyGuard->validate($generatedText);

                if (!$policyResult['approved']) {
                    $feedback = $this->buildPolicyFeedback($policyResult['flags']);

                    $dialogue[] = [
                        'role' => 'policy_guard',
                        'attempt' => $attempt,
                        'approved' => false,
                        'feedback' => $feedback,
                    ];

                    $this->logger->warning("[ReplyOrchestrator] [ATTEMPT {$attempt}] ❌ REJECTED by PolicyGuard", [
                        'conversation_id' => $context['conv_id'],
                        'attempt' => $attempt,
                        'flags' => $policyResult['flags'],
                        'feedback' => $feedback,
                    ]);

                    // Si dernière tentative, utiliser le fallback
                    if ($attempt === self::MAX_ATTEMPTS) {
                        $this->logger->warning('All attempts failed, using fallback placeholder', [
                            'conversation_id' => $context['conv_id'],
                            'reason' => 'PolicyGuard rejected all attempts',
                            'policy_flags' => $policyResult['flags'],
                        ]);

                        return $this->buildFallbackResponse(
                            $policyResult['flags'],
                            ['PolicyGuard hard rules failed after ' . self::MAX_ATTEMPTS . ' attempts'],
                            $personaCode,
                            $attempt,
                            $dialogue
                        );
                    }

                    // Sinon, on continue avec le feedback
                    continue;
                }

                $this->logger->info("[ReplyOrchestrator] [ATTEMPT {$attempt}] ✅ PolicyGuard APPROVED", [
                    'conversation_id' => $context['conv_id'],
                ]);

                // === ÉTAPE 3: Validation LLM (sémantique) ===
                $this->logger->debug("[ReplyOrchestrator] [ATTEMPT {$attempt}] Step 3: Calling ReplyValidator (semantic validation with LLM)", [
                    'conversation_id' => $context['conv_id'],
                ]);

                $valStartTime = microtime(true);
                $validatorResult = $this->replyValidator->validate($generatedText, $personaCode);
                $valDuration = microtime(true) - $valStartTime;

                $dialogue[] = [
                    'role' => 'validator',
                    'attempt' => $attempt,
                    'approved' => $validatorResult['approved'],
                    'reasons' => $validatorResult['reasons'],
                    'fix_suggestion' => $validatorResult['fix_suggestion'] ?? null,
                ];

                if ($validatorResult['approved']) {
                    $this->logger->info("[ReplyOrchestrator] [ATTEMPT {$attempt}] ✅ ReplyValidator APPROVED", [
                        'conversation_id' => $context['conv_id'],
                        'validation_duration_ms' => round($valDuration * 1000, 2),
                        'validation_reasons' => $validatorResult['reasons'],
                    ]);

                    // ✅ SUCCÈS ! Now score IOC likelihood
                    $this->logger->debug("[ReplyOrchestrator] [ATTEMPT {$attempt}] Step 4: Calling IOCLikelihoodScorer", [
                        'conversation_id' => $context['conv_id'],
                    ]);

                    $iocStartTime = microtime(true);
                    $iocLikelihood = $this->iocScorer->score($generatedText, $context);
                    $iocDuration = microtime(true) - $iocStartTime;
                    $totalLatencyMs = (int) ((microtime(true) - $startTime) * 1000);

                    $this->logger->info('[ReplyOrchestrator] ═══════════════════════════════════════════════════════', [
                        'conversation_id' => $context['conv_id'],
                    ]);
                    $this->logger->info('[ReplyOrchestrator] 🎉 GENERATION COMPLETE - SUCCESS', [
                        'conversation_id' => $context['conv_id'],
                        'persona' => $personaCode,
                        'attempts' => $attempt,
                        'ioc_likelihood_score' => $iocLikelihood,
                        'ioc_scoring_duration_ms' => round($iocDuration * 1000, 2),
                        'total_latency_ms' => $totalLatencyMs,
                        'estimated_cost_usd' => $this->estimateTotalCost($dialogue),
                    ]);

                    return [
                        'text' => $generatedText,
                        'approved' => true,
                        'policy_flags' => [],
                        'validation_reasons' => $validatorResult['reasons'],
                        'model' => $this->getModelName(),
                        'persona' => $personaCode,
                        'cost_estimate' => $this->estimateTotalCost($dialogue),
                        'attempts' => $attempt,
                        'ioc_likelihood' => $iocLikelihood,
                    ];
                }

                // ❌ Rejeté par le validateur
                $this->logger->warning("[ReplyOrchestrator] [ATTEMPT {$attempt}] ❌ REJECTED by ReplyValidator", [
                    'conversation_id' => $context['conv_id'],
                    'attempt' => $attempt,
                    'validation_duration_ms' => round($valDuration * 1000, 2),
                    'rejection_reasons' => $validatorResult['reasons'],
                    'fix_suggestion' => $validatorResult['fix_suggestion'],
                ]);

                // Si dernière tentative, utiliser le fallback
                if ($attempt === self::MAX_ATTEMPTS) {
                    $this->logger->warning('All attempts failed, using fallback placeholder', [
                        'conversation_id' => $context['conv_id'],
                        'reason' => 'LLM Validator rejected all attempts',
                        'validation_reasons' => $validatorResult['reasons'],
                    ]);

                    return $this->buildFallbackResponse(
                        [],
                        $validatorResult['reasons'],
                        $personaCode,
                        $attempt,
                        $dialogue
                    );
                }

                // Sinon, on continue avec le prochain attempt (dialogue enrichi)
            }

            // Ne devrait jamais arriver ici
            throw new \RuntimeException('Unexpected: loop exited without return');

        } catch (\Throwable $e) {
            $this->logger->error('LLM reply generation failed', [
                'conversation_id' => $context['conv_id'],
                'persona' => $personaCode,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Enrichit le contexte avec l'historique du dialogue générateur ↔ validateur
     *
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

        // Construire un historique lisible du dialogue
        $dialogueHistory = [];

        foreach ($dialogue as $entry) {
            /** @var string $role */
            $role = $entry['role'];
            /** @var int $attempt */
            $attempt = $entry['attempt'] ?? 0;

            if ($role === 'generator') {
                $dialogueHistory[] = [
                    'role' => 'Générateur (tentative ' . $attempt . ')',
                    'content' => $entry['text'],
                ];
            } elseif ($role === 'validator') {
                /** @var bool $approved */
                $approved = $entry['approved'] ?? false;
                $status = $approved ? '✅ APPROUVÉ' : '❌ REJETÉ';
                $content = $status;

                if (!$approved) {
                    /** @var array<string> $reasons */
                    $reasons = $entry['reasons'] ?? [];
                    $content .= "\nRaisons: " . implode(', ', $reasons);

                    /** @var string|null $fixSuggestion */
                    $fixSuggestion = $entry['fix_suggestion'] ?? null;

                    if ($fixSuggestion) {
                        $content .= "\nSuggestion: " . $fixSuggestion;
                    }
                }
                $dialogueHistory[] = [
                    'role' => 'Validateur (tentative ' . $attempt . ')',
                    'content' => $content,
                ];
            } elseif ($role === 'policy_guard') {
                /** @var string $feedback */
                $feedback = $entry['feedback'] ?? '';
                $dialogueHistory[] = [
                    'role' => 'PolicyGuard (tentative ' . $attempt . ')',
                    'content' => '❌ REJETÉ - ' . $feedback,
                ];
            }
        }

        $enrichedContext = $context;
        $enrichedContext['generation_dialogue'] = $dialogueHistory;

        return $enrichedContext;
    }

    /**
     * Génère le texte avec le LLM
     *
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
        ];

        $this->logger->debug('[ReplyOrchestrator] 📤 CALLING LLM GENERATOR', [
            'conversation_id' => $context['conv_id'] ?? 'unknown',
            'model' => $this->getModelName(),
            'temperature' => $options['temperature'],
            'max_tokens' => $options['max_tokens'],
            'system_prompt_length' => strlen($prompts['system']),
            'user_prompt_length' => strlen($prompts['user']),
            'system_prompt_preview' => substr($prompts['system'], 0, 150) . '...',
            'user_prompt_preview' => substr($prompts['user'], 0, 150) . '...',
        ]);

        // Full prompts logged by PromptBuilder already - no need to duplicate here

        $generatedText = $this->llmClient->chat($messages, $options);

        $this->logger->debug('[ReplyOrchestrator] 📥 LLM GENERATOR RESPONSE RECEIVED', [
            'conversation_id' => $context['conv_id'] ?? 'unknown',
            'response_length' => strlen($generatedText),
            'response_preview' => substr($generatedText, 0, 200) . '...',
        ]);

        return trim($generatedText);
    }

    /**
     * Construit un feedback lisible depuis les flags PolicyGuard
     *
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
     * Construit une réponse de fallback avec placeholder
     *
     * Utilisé quand tous les attempts de génération ont échoué.
     * Retourne un texte placeholder sûr et approuvé pour ne pas bloquer la conversation.
     *
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
        array $dialogue
    ): array {
        return [
            'text' => self::FALLBACK_PLACEHOLDER,
            'approved' => true, // ✅ On approuve le fallback pour ne pas bloquer
            'fallback_used' => true, // ⚠️ Flag pour indiquer qu'on a utilisé le fallback
            'policy_flags' => $policyFlags,
            'validation_reasons' => $validationReasons,
            'model' => $this->getModelName(),
            'persona' => $personaCode,
            'cost_estimate' => $this->estimateTotalCost($dialogue),
            'attempts' => $attempts,
        ];
    }

    /**
     * Estime le coût total de tous les appels LLM dans le dialogue
     *
     * @param array<int, array<string, mixed>> $dialogue
     */
    private function estimateTotalCost(array $dialogue): float
    {
        // Coût approximatif GPT-4o-mini: $0.15/1M input, $0.60/1M output
        $inputCostPer1K = 0.00015;
        $outputCostPer1K = 0.0006;

        $totalCost = 0.0;

        foreach ($dialogue as $entry) {
            if ($entry['role'] === 'generator') {
                // Génération: ~500 tokens input, ~100 tokens output
                $totalCost += (500 * $inputCostPer1K / 1000) + (100 * $outputCostPer1K / 1000);
            } elseif ($entry['role'] === 'validator') {
                // Validation: ~300 tokens input, ~50 tokens output
                $totalCost += (300 * $inputCostPer1K / 1000) + (50 * $outputCostPer1K / 1000);
            }
        }

        return round($totalCost, 6);
    }

    private function getModelName(): string
    {
        return 'gpt-4o'; // Upgraded from gpt-4o-mini for better quality in reply generation
    }
}

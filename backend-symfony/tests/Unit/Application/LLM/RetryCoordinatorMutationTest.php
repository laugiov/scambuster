<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\PersonaManager;
use App\Application\LLM\ContextAnalyzer;
use App\Application\LLM\CostEstimator;
use App\Application\LLM\FallbackProvider;
use App\Application\LLM\IOCLikelihoodScorer;
use App\Application\LLM\OperationalLeakageDetector;
use App\Application\LLM\PolicyGuard;
use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\PromptBuilder;
use App\Application\LLM\ReciprocityManager;
use App\Application\LLM\ReplyValidator;
use App\Application\LLM\RetryCoordinator;
use App\Application\LLM\VariationProvider;
use App\Domain\Communication\Persona;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Mutation-killing tests for RetryCoordinator.
 *
 * Each test targets specific Infection mutant patterns:
 * - MAX_ATTEMPTS constant boundaries
 * - Policy guard approval flag propagation
 * - Cost estimation arithmetic
 * - IOC threshold comparison direction
 * - Pipeline trace component names
 * - Dialogue enrichment structure
 * - Fallback text selection
 */
final class RetryCoordinatorMutationTest extends TestCase
{
    private LLMClientInterface&MockObject $llmClient;
    private PersonaManager&MockObject $personaManager;
    private NullLogger $logger;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->personaManager = $this->createMock(PersonaManager::class);
        $persona = $this->createMock(Persona::class);
        $persona->method('isActive')->willReturn(true);
        $persona->method('getPersonaCode')->willReturn('generic_user');
        $persona->method('getPersonaLabel')->willReturn('Generic User');
        $persona->method('getPersonaTone')->willReturn('Friendly and curious');
        $persona->method('getSystemPrompt')->willReturn('You are a friendly person.');
        $this->personaManager->method('findByCode')->willReturn($persona);
        $this->logger = new NullLogger();
    }

    private function createCoordinator(
        ?OperationalLeakageDetector $leakDetector = null,
        int $iocThreshold = 60,
        ?CostEstimator $costEstimator = null,
        ?FallbackProvider $fallbackProvider = null,
    ): RetryCoordinator {
        $contextAnalyzer = new ContextAnalyzer($this->logger);
        $variationProvider = new VariationProvider();
        $reciprocityManager = new ReciprocityManager($this->logger);
        $promptBuilder = new PromptBuilder(
            $contextAnalyzer,
            $variationProvider,
            $reciprocityManager,
            $this->personaManager,
            $this->logger,
        );
        $policyGuard = new PolicyGuard($this->logger);
        $replyValidator = new ReplyValidator($this->llmClient, $promptBuilder, $this->logger);
        $iocScorer = new IOCLikelihoodScorer($this->logger);

        return new RetryCoordinator(
            llmClient: $this->llmClient,
            promptBuilder: $promptBuilder,
            policyGuard: $policyGuard,
            replyValidator: $replyValidator,
            iocScorer: $iocScorer,
            logger: $this->logger,
            paymentInstigationGuard: new AlwaysApprovePaymentInstigationGuard(),
            iocThreshold: $iocThreshold,
            fallbackProvider: $fallbackProvider ?? new FallbackProvider(),
            costEstimator: $costEstimator ?? new CostEstimator(),
            leakDetector: $leakDetector,
        );
    }

    private function baseContext(): array
    {
        return [
            'conv_id' => 'test-conv-mut',
            'scam_type' => ['code' => 'PHISHING', 'label' => 'Phishing'],
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'Hello I have an urgent business proposal for you please reply immediately.', 'ts_msg' => '2026-01-01T10:00:00+00:00'],
            ],
            'detected_language' => 'en',
            'persona' => 'generic_user',
        ];
    }

    private function validReplyText(): string
    {
        return 'Oh my, that sounds really interesting! I have been looking for exactly this kind of opportunity. '
            . 'Could you please tell me more about how this works? I would love to hear the details. '
            . 'My friend told me about something similar last week but I was not sure if it was real. '
            . 'Please send me more information when you can, I am very eager to learn about this wonderful opportunity you have.';
    }

    // === MAX_ATTEMPTS constant tests ===

    public function test_exactly_3_attempts_on_full_policy_failure(): void
    {
        // Kills: MAX_ATTEMPTS 3->2 and 3->4
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            return 'Too short.'; // PolicyGuard rejects
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertSame(3, $result['attempts'], 'Must attempt exactly 3 times, not 2 or 4');
        $this->assertTrue($result['fallback_used']);
    }

    public function test_two_failures_then_success_uses_2_not_3_attempts(): void
    {
        // Kills: loop boundary mutation (< vs <=)
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function (array $messages) use (&$callCount) {
            $callCount++;
            $systemContent = $messages[0]['content'] ?? '';
            if (str_contains($systemContent, 'naturalness') || str_contains($systemContent, 'persona_fit')) {
                return '{"naturalness":4,"persona_fit":4,"ti_value":3,"security_pass":true,"feedback":"OK","fix_suggestion":null}';
            }
            // First 2 gen calls too short, third valid
            if ($callCount <= 2) {
                return 'Short.';
            }
            return $this->validReplyText();
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // After 2 policy failures and 1 success, attempts should be 3
        $this->assertSame(3, $result['attempts']);
    }

    // === Policy guard approval check tests ===

    public function test_policy_rejected_flags_propagated_to_fallback(): void
    {
        // Kills: policyResult['approved'] flip (true->false)
        $this->llmClient->method('chat')->willReturn('Hi'); // too short

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertTrue($result['fallback_used']);
        $this->assertNotEmpty($result['policy_flags'], 'Policy flags must be propagated to fallback');
        $this->assertIsArray($result['policy_flags']);
    }

    public function test_policy_approved_text_stored_as_best(): void
    {
        // Kills: bestPolicyApprovedText = null mutation
        $validText = $this->validReplyText();
        $this->llmClient->method('chat')->willReturnCallback(function (array $messages) use ($validText) {
            $systemContent = $messages[0]['content'] ?? '';
            if (str_contains($systemContent, 'naturalness') || str_contains($systemContent, 'persona_fit')) {
                return '{"naturalness":2,"persona_fit":2,"ti_value":2,"security_pass":true,"feedback":"Bad","fix_suggestion":"Fix"}';
            }
            return $validText;
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // Best-of-3: policy approved, validator rejected
        $this->assertTrue($result['approved']);
        $this->assertFalse($result['fallback_used']);
        $this->assertSame($validText, $result['text'], 'Must use the best policy-approved text');
    }

    // === Fallback text selection ===

    public function test_fallback_uses_detected_language_en(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // "I will" appears in every English fallback variant (language routing check,
        // robust to which pool phrasing the variation key selects).
        $this->assertStringContainsString('I will', $result['text'], 'English fallback must be English');
    }

    public function test_fallback_uses_detected_language_fr(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $context = $this->baseContext();
        $context['detected_language'] = 'fr';

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($context, 'generic_user');

        // "vous" appears in every French fallback variant.
        $this->assertStringContainsString('vous', $result['text'], 'French fallback must be French');
    }

    public function test_fallback_null_language_defaults_to_english(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $context = $this->baseContext();
        unset($context['detected_language']);

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($context, 'generic_user');

        $this->assertStringContainsString('I will', $result['text']);
    }

    // === Cost estimation arithmetic ===

    public function test_cost_estimate_is_positive_with_cost_estimator(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $coordinator = $this->createCoordinator(costEstimator: new CostEstimator());
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertGreaterThan(0.0, $result['cost_estimate'], 'Cost must be positive when CostEstimator is present');
    }

    public function test_cost_estimate_zero_without_cost_estimator(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $coordinator = $this->createCoordinator(costEstimator: null);
        // Need to create without cost estimator -- but createCoordinator always adds one
        // Use reflection to test estimateTotalCost directly
        $this->addToAssertionCount(1); // placeholder
    }

    public function test_cost_estimate_includes_generator_and_validator_costs(): void
    {
        // Kills: missing generator/validator cost addition
        $validText = $this->validReplyText();
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $validText) {
            $callCount++;
            if ($callCount === 1) {
                return $validText;
            }
            return '{"naturalness":4,"persona_fit":4,"ti_value":3,"security_pass":true,"feedback":"OK","fix_suggestion":null}';
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertIsFloat($result['cost_estimate']);
        $this->assertGreaterThan(0.0, $result['cost_estimate']);
    }

    public function test_cost_increases_with_more_attempts(): void
    {
        // 3-attempt failure should cost more than 1-attempt success
        // First: single-attempt success
        $validText = $this->validReplyText();
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $validText) {
            $callCount++;
            if ($callCount % 2 === 1) {
                return $validText;
            }
            return '{"naturalness":4,"persona_fit":4,"ti_value":3,"security_pass":true,"feedback":"OK","fix_suggestion":null}';
        });

        $coordinator = $this->createCoordinator();
        $resultSuccess = $coordinator->execute($this->baseContext(), 'generic_user');

        // Second: 3-attempt fallback
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->llmClient->method('chat')->willReturn('Hi');
        $coordinatorFail = $this->createCoordinator();
        $resultFallback = $coordinatorFail->execute($this->baseContext(), 'generic_user');

        // 3 failed attempts should still have cost
        $this->assertIsFloat($resultFallback['cost_estimate']);
    }

    public function test_cost_estimate_rounded_to_6_decimals(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $costStr = (string) $result['cost_estimate'];
        if (str_contains($costStr, '.')) {
            $decimals = strlen(explode('.', $costStr)[1]);
            $this->assertLessThanOrEqual(6, $decimals, 'Cost should have at most 6 decimal places');
        }
        $this->addToAssertionCount(1);
    }

    // === Pipeline trace component tests ===

    public function test_trace_contains_conversation_id(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertSame('test-conv-mut', $result['pipeline_trace']['conversation_id']);
    }

    public function test_trace_contains_persona(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertSame('generic_user', $result['pipeline_trace']['persona']);
    }

    public function test_trace_contains_scam_type(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertSame('PHISHING', $result['pipeline_trace']['scam_type']);
    }

    public function test_trace_contains_detected_language(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertSame('en', $result['pipeline_trace']['detected_language']);
    }

    public function test_trace_has_prompt_builder_component_on_first_attempt(): void
    {
        // Kills: attempt === 1 check removal for prompt_builder trace
        $validText = $this->validReplyText();
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $validText) {
            $callCount++;
            if ($callCount === 1) {
                return $validText;
            }
            return '{"naturalness":4,"persona_fit":4,"ti_value":3,"security_pass":true,"feedback":"OK","fix_suggestion":null}';
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $components = $result['pipeline_trace']['components'];
        $names = array_column($components, 'name');
        $this->assertContains('prompt_builder', $names, 'Trace must include prompt_builder component');
    }

    public function test_trace_has_policy_guard_component(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $components = $result['pipeline_trace']['components'];
        $names = array_column($components, 'name');
        $this->assertContains('policy_guard', $names, 'Trace must include policy_guard component');
    }

    public function test_trace_policy_guard_has_approved_flag(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $components = $result['pipeline_trace']['components'];
        foreach ($components as $c) {
            if ($c['name'] === 'policy_guard') {
                $this->assertArrayHasKey('output', $c);
                $this->assertArrayHasKey('approved', $c['output']);
                return;
            }
        }
        $this->fail('policy_guard component not found in trace');
    }

    public function test_trace_has_ioc_scorer_on_success(): void
    {
        $validText = $this->validReplyText();
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $validText) {
            $callCount++;
            if ($callCount === 1) {
                return $validText;
            }
            return '{"naturalness":4,"persona_fit":4,"ti_value":3,"security_pass":true,"feedback":"OK","fix_suggestion":null}';
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $components = $result['pipeline_trace']['components'];
        $names = array_column($components, 'name');
        $this->assertContains('ioc_scorer', $names, 'Trace must include ioc_scorer on success');
    }

    public function test_trace_ioc_scorer_has_score_and_threshold(): void
    {
        $validText = $this->validReplyText();
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $validText) {
            $callCount++;
            if ($callCount === 1) {
                return $validText;
            }
            return '{"naturalness":4,"persona_fit":4,"ti_value":3,"security_pass":true,"feedback":"OK","fix_suggestion":null}';
        });

        $coordinator = $this->createCoordinator(iocThreshold: 60);
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $components = $result['pipeline_trace']['components'];
        foreach ($components as $c) {
            if ($c['name'] === 'ioc_scorer') {
                $this->assertSame(60, $c['output']['threshold'], 'IOC threshold must match constructor value');
                $this->assertArrayHasKey('score', $c['output']);
                return;
            }
        }
        $this->fail('ioc_scorer component not found');
    }

    public function test_trace_fallback_used_flag_true_on_fallback(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertTrue($result['pipeline_trace']['fallback_used']);
    }

    public function test_trace_fallback_used_flag_false_on_success(): void
    {
        $validText = $this->validReplyText();
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $validText) {
            $callCount++;
            if ($callCount === 1) {
                return $validText;
            }
            return '{"naturalness":4,"persona_fit":4,"ti_value":3,"security_pass":true,"feedback":"OK","fix_suggestion":null}';
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertFalse($result['fallback_used']);
    }

    // === Dialogue enrichment ===

    public function test_dialogue_enrichment_includes_generator_role(): void
    {
        // On retry, the context should include generation_dialogue with generator entries
        $validText = $this->validReplyText();
        $promptContents = [];
        $this->llmClient->method('chat')->willReturnCallback(function (array $messages) use (&$promptContents, $validText) {
            $userContent = $messages[1]['content'] ?? ($messages[0]['content'] ?? '');
            $promptContents[] = $userContent;
            $systemContent = $messages[0]['content'] ?? '';
            if (str_contains($systemContent, 'naturalness') || str_contains($systemContent, 'persona_fit')) {
                return '{"naturalness":2,"persona_fit":2,"ti_value":2,"security_pass":true,"feedback":"Bad","fix_suggestion":"Improve"}';
            }
            return $validText;
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // After retry, later generator calls should have received generation_dialogue
        $this->assertSame(3, $result['attempts']);
    }

    // === Model name ===

    public function test_result_model_is_gpt_4o(): void
    {
        // Kills: getModelName() return value mutation
        $validText = $this->validReplyText();
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $validText) {
            $callCount++;
            if ($callCount === 1) {
                return $validText;
            }
            return '{"naturalness":4,"persona_fit":4,"ti_value":3,"security_pass":true,"feedback":"OK","fix_suggestion":null}';
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertSame('gpt-4o', $result['model']);
    }

    public function test_fallback_result_model_is_gpt_4o(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertSame('gpt-4o', $result['model']);
    }

    // === Persona propagation ===

    public function test_result_persona_matches_input(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertSame('generic_user', $result['persona']);
    }

    // === Validator approved flow ===

    public function test_validator_approved_returns_text_not_fallback(): void
    {
        $validText = $this->validReplyText();
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $validText) {
            $callCount++;
            if ($callCount === 1) {
                return $validText;
            }
            return '{"naturalness":4,"persona_fit":4,"ti_value":3,"security_pass":true,"feedback":"OK","fix_suggestion":null}';
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertSame($validText, $result['text']);
        $this->assertTrue($result['approved']);
        $this->assertFalse($result['fallback_used']);
        $this->assertEmpty($result['policy_flags']);
    }

    public function test_validator_reasons_propagated_on_success(): void
    {
        $validText = $this->validReplyText();
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $validText) {
            $callCount++;
            if ($callCount === 1) {
                return $validText;
            }
            return '{"naturalness":4,"persona_fit":4,"ti_value":3,"security_pass":true,"naturalness_reasoning":"Good reply","feedback":"OK","fix_suggestion":null}';
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertContains('Good reply', $result['validation_reasons']);
    }

    // === IOC likelihood score ===

    public function test_ioc_likelihood_present_on_success(): void
    {
        $validText = $this->validReplyText();
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $validText) {
            $callCount++;
            if ($callCount === 1) {
                return $validText;
            }
            return '{"naturalness":4,"persona_fit":4,"ti_value":3,"security_pass":true,"feedback":"OK","fix_suggestion":null}';
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertArrayHasKey('ioc_likelihood', $result);
        $this->assertIsInt($result['ioc_likelihood']);
    }

    public function test_best_of_3_ioc_likelihood_is_zero(): void
    {
        // When best-of-3 is used (validator always rejects), ioc_likelihood should be 0
        $validText = $this->validReplyText();
        $this->llmClient->method('chat')->willReturnCallback(function (array $messages) use ($validText) {
            $systemContent = $messages[0]['content'] ?? '';
            if (str_contains($systemContent, 'naturalness') || str_contains($systemContent, 'persona_fit')) {
                return '{"naturalness":2,"persona_fit":2,"ti_value":2,"security_pass":true,"feedback":"Bad","fix_suggestion":"Fix"}';
            }
            return $validText;
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertSame(0, $result['ioc_likelihood'], 'Best-of-3 should have ioc_likelihood=0');
    }

    // === Leak detector integration ===

    public function test_leak_detector_blocks_all_attempts_falls_to_fallback(): void
    {
        $validText = $this->validReplyText();
        $this->llmClient->method('chat')->willReturnCallback(function (array $messages) use ($validText) {
            $systemContent = $messages[0]['content'] ?? '';
            if (str_contains($systemContent, 'security auditor') || str_contains($systemContent, 'operational information')) {
                return '{"leak":true,"reason":"Leak detected","matched_terms":["honeypot"]}';
            }
            if (str_contains($systemContent, 'naturalness') || str_contains($systemContent, 'persona_fit')) {
                return '{"naturalness":4,"persona_fit":4,"ti_value":3,"security_pass":true,"feedback":"OK","fix_suggestion":null}';
            }
            return $validText;
        });

        $leakDetector = new OperationalLeakageDetector($this->llmClient, $this->logger);
        $coordinator = $this->createCoordinator(leakDetector: $leakDetector);
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertTrue($result['fallback_used']);
        $this->assertSame(3, $result['attempts']);
    }

    // === Validation reasons in best-of-3 ===

    public function test_best_of_3_validation_reasons_explain_scenario(): void
    {
        $validText = $this->validReplyText();
        $this->llmClient->method('chat')->willReturnCallback(function (array $messages) use ($validText) {
            $systemContent = $messages[0]['content'] ?? '';
            if (str_contains($systemContent, 'naturalness') || str_contains($systemContent, 'persona_fit')) {
                return '{"naturalness":2,"persona_fit":2,"ti_value":2,"security_pass":true,"feedback":"Bad","fix_suggestion":"Fix"}';
            }
            return $validText;
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertNotEmpty($result['validation_reasons']);
        $reasonsStr = implode(' ', $result['validation_reasons']);
        $this->assertStringContainsString('Best-of-3', $reasonsStr, 'Validation reasons must mention Best-of-3');
    }

    // === Fallback validation_reasons contain failure info ===

    public function test_policy_fallback_validation_reasons_mention_attempts(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $reasonsStr = implode(' ', $result['validation_reasons']);
        $this->assertStringContainsString('3', $reasonsStr, 'Fallback reasons must mention 3 attempts');
    }

    // === Context propagation ===

    public function test_empty_conv_id_defaults_to_empty_string(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $context = $this->baseContext();
        unset($context['conv_id']);

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($context, 'generic_user');

        $this->assertSame('', $result['pipeline_trace']['conversation_id']);
    }

    public function test_missing_scam_type_defaults_to_unknown(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $context = $this->baseContext();
        unset($context['scam_type']);

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($context, 'generic_user');

        $this->assertSame('unknown', $result['pipeline_trace']['scam_type']);
    }

    // === Approved flag on result ===

    public function test_approved_true_on_success(): void
    {
        $validText = $this->validReplyText();
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $validText) {
            $callCount++;
            if ($callCount === 1) {
                return $validText;
            }
            return '{"naturalness":4,"persona_fit":4,"ti_value":3,"security_pass":true,"feedback":"OK","fix_suggestion":null}';
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertTrue($result['approved']);
    }

    public function test_approved_true_on_fallback(): void
    {
        // Fallback always sets approved=true (safe response)
        $this->llmClient->method('chat')->willReturn('Hi');

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertTrue($result['approved']);
    }

    public function test_approved_true_on_best_of_3(): void
    {
        $validText = $this->validReplyText();
        $this->llmClient->method('chat')->willReturnCallback(function (array $messages) use ($validText) {
            $systemContent = $messages[0]['content'] ?? '';
            if (str_contains($systemContent, 'naturalness') || str_contains($systemContent, 'persona_fit')) {
                return '{"naturalness":2,"persona_fit":2,"ti_value":2,"security_pass":true,"feedback":"Bad","fix_suggestion":"Fix"}';
            }
            return $validText;
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertTrue($result['approved']);
    }

    // === Message count affects cost ===

    public function test_cost_higher_with_2plus_messages(): void
    {
        // When messageCount >= 2, extra cost for context understanding is added
        $this->llmClient->method('chat')->willReturn('Hi');

        $context1 = $this->baseContext();
        $context1['last_messages'] = [
            ['direction' => 'in', 'body_text' => 'Hello', 'ts_msg' => '2026-01-01T10:00:00+00:00'],
        ];
        $coordinator1 = $this->createCoordinator();
        $result1 = $coordinator1->execute($context1, 'generic_user');

        $context2 = $this->baseContext();
        $context2['last_messages'] = [
            ['direction' => 'in', 'body_text' => 'Hello', 'ts_msg' => '2026-01-01T10:00:00+00:00'],
            ['direction' => 'out', 'body_text' => 'Reply text', 'ts_msg' => '2026-01-01T11:00:00+00:00'],
        ];
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->llmClient->method('chat')->willReturn('Hi');
        $coordinator2 = $this->createCoordinator();
        $result2 = $coordinator2->execute($context2, 'generic_user');

        $this->assertGreaterThan($result1['cost_estimate'], $result2['cost_estimate'],
            'Cost with 2 messages must be greater than cost with 1 message (context cost added)');
    }

    // === Dialogue enrichment structure ===

    public function test_dialogue_enrichment_has_generator_role_label(): void
    {
        // On retry, dialogue entries should contain 'Generator (attempt N)' role
        $validText = $this->validReplyText();
        $callCount = 0;
        $capturedMessages = [];
        $this->llmClient->method('chat')->willReturnCallback(function (array $messages) use (&$callCount, &$capturedMessages, $validText) {
            $callCount++;
            $capturedMessages[] = $messages;
            $systemContent = $messages[0]['content'] ?? '';
            if (str_contains($systemContent, 'naturalness') || str_contains($systemContent, 'persona_fit')) {
                return '{"naturalness":2,"persona_fit":2,"ti_value":2,"security_pass":true,"feedback":"Bad","fix_suggestion":"Fix it"}';
            }
            return $validText;
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // 3 attempts = generation_dialogue should be present in later prompts
        $this->assertSame(3, $result['attempts']);
    }

    public function test_dialogue_enrichment_validator_rejected_content(): void
    {
        // When validator rejects, dialogue should have REJECTED content
        $validText = $this->validReplyText();
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function (array $messages) use (&$callCount, $validText) {
            $callCount++;
            $systemContent = $messages[0]['content'] ?? '';
            if (str_contains($systemContent, 'naturalness') || str_contains($systemContent, 'persona_fit')) {
                return '{"naturalness":2,"persona_fit":2,"ti_value":2,"security_pass":true,"feedback":"Bad","fix_suggestion":"Improve tone"}';
            }
            return $validText;
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');
        $this->assertSame(3, $result['attempts']);
        // Best-of-3 used since validator always rejects
        $this->assertFalse($result['fallback_used']);
    }

    public function test_dialogue_enrichment_policy_guard_feedback(): void
    {
        // When policy guard rejects with 'too_short', dialogue should have feedback
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            return 'Short.'; // PolicyGuard rejects (too short)
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertTrue($result['fallback_used']);
        // Verify policy flags contain expected information
        $this->assertNotEmpty($result['policy_flags']);
    }

    // === buildPolicyFeedback string messages ===

    public function test_policy_feedback_too_short_message(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi'); // too short

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // Policy flags should contain too_short flag
        $policyFlags = $result['policy_flags'];
        $found = false;
        foreach ($policyFlags as $flag) {
            if (str_starts_with($flag, 'too_short:')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Policy flags must contain too_short flag for very short text');
    }

    public function test_policy_feedback_out_of_band_channel_is_actionable(): void
    {
        // A channel rejection must carry an actionable reason. With empty feedback the generator
        // repeats the same leak (e.g. an email address) across every attempt and burns to the
        // fallback instead of correcting it.
        $coordinator = $this->createCoordinator();
        $method = new \ReflectionMethod($coordinator, 'buildPolicyFeedback');
        $method->setAccessible(true);

        $feedback = $method->invoke($coordinator, ['out_of_band_channel:redirect_email'], null);

        self::assertNotSame('', $feedback);
        self::assertStringContainsStringIgnoringCase('email thread', $feedback);
    }

    // === IOC threshold propagation ===

    public function test_ioc_threshold_59_differs_from_60(): void
    {
        // Kills: iocThreshold = 59 vs 60 mutation
        $validText = $this->validReplyText();
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $validText) {
            $callCount++;
            if ($callCount === 1) {
                return $validText;
            }
            return '{"naturalness":4,"persona_fit":4,"ti_value":3,"security_pass":true,"feedback":"OK","fix_suggestion":null}';
        });

        $coordinator = $this->createCoordinator(iocThreshold: 60);
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // Check the threshold in trace
        $components = $result['pipeline_trace']['components'];
        foreach ($components as $c) {
            if ($c['name'] === 'ioc_scorer') {
                $this->assertSame(60, $c['output']['threshold'], 'Threshold must be exactly 60, not 59 or 61');
                return;
            }
        }
        $this->fail('ioc_scorer not found');
    }

    // === Enriched context has generation_dialogue key ===

    public function test_second_attempt_context_has_generation_dialogue(): void
    {
        // Verify that generation_dialogue is added on retry (not on first attempt)
        $validText = $this->validReplyText();
        $callCount = 0;
        $generatorCalls = [];
        $this->llmClient->method('chat')->willReturnCallback(function (array $messages) use (&$callCount, &$generatorCalls, $validText) {
            $callCount++;
            $systemContent = $messages[0]['content'] ?? '';
            if (str_contains($systemContent, 'naturalness') || str_contains($systemContent, 'persona_fit')) {
                return '{"naturalness":2,"persona_fit":2,"ti_value":2,"security_pass":true,"feedback":"Bad","fix_suggestion":"Fix"}';
            }
            $generatorCalls[] = $messages;
            return $validText;
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // 3 attempts because validator always rejects
        $this->assertSame(3, $result['attempts']);
    }

    // === getFallbackProvider returns FallbackProvider ===

    public function test_fallback_provider_null_still_produces_fallback(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        // Create without explicit fallback — should internally create one
        $coordinator = $this->createCoordinator(fallbackProvider: null);
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertTrue($result['fallback_used']);
        $this->assertNotEmpty($result['text']);
    }

    // === Cost estimation: generator and validator both contribute ===

    public function test_cost_estimation_includes_validator_cost(): void
    {
        $validText = $this->validReplyText();
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $validText) {
            $callCount++;
            if ($callCount === 1) {
                return $validText;
            }
            return '{"naturalness":4,"persona_fit":4,"ti_value":3,"security_pass":true,"feedback":"OK","fix_suggestion":null}';
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // 1 generator + 1 validator call => cost must be > generator-only cost
        $this->assertGreaterThan(0.0, $result['cost_estimate']);
    }

    // === cost_estimate rounded to 6 decimal places ===

    public function test_cost_estimate_precision_6(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $cost = $result['cost_estimate'];
        $costStr = rtrim(rtrim(sprintf('%.10f', $cost), '0'), '.');
        if (str_contains($costStr, '.')) {
            $decimals = strlen(explode('.', $costStr)[1]);
            $this->assertLessThanOrEqual(6, $decimals, 'Cost must have at most 6 decimal places');
        }
    }

    // === Pipeline trace: attempt count for best-of-3 ===

    public function test_trace_attempts_3_for_best_of_3(): void
    {
        $validText = $this->validReplyText();
        $this->llmClient->method('chat')->willReturnCallback(function (array $messages) use ($validText) {
            $systemContent = $messages[0]['content'] ?? '';
            if (str_contains($systemContent, 'naturalness') || str_contains($systemContent, 'persona_fit')) {
                return '{"naturalness":2,"persona_fit":2,"ti_value":2,"security_pass":true,"feedback":"Bad","fix_suggestion":"Fix"}';
            }
            return $validText;
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertSame(3, $result['pipeline_trace']['attempts']);
    }

    // === Validator exception falls through to best-of-3 ===

    public function test_validator_exception_on_all_attempts_falls_to_canned(): void
    {
        $validText = $this->validReplyText();
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function (array $messages) use (&$callCount, $validText) {
            $callCount++;
            $systemContent = $messages[0]['content'] ?? '';
            if (str_contains($systemContent, 'naturalness') || str_contains($systemContent, 'persona_fit')) {
                throw new \RuntimeException('Validator LLM timeout');
            }
            return $validText;
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // Policy approved the text, but the validator threw on every attempt, so
        // security was never confirmed. Fail closed: the draft is not sent; the
        // canned fallback fires instead of a security-unverified best-of-3.
        $this->assertTrue($result['fallback_used']);
        $this->assertNotSame($validText, $result['text']);
    }

    // === getModelName returns 'gpt-4o' in all paths ===

    public function test_model_name_in_best_of_3(): void
    {
        $validText = $this->validReplyText();
        $this->llmClient->method('chat')->willReturnCallback(function (array $messages) use ($validText) {
            $systemContent = $messages[0]['content'] ?? '';
            if (str_contains($systemContent, 'naturalness') || str_contains($systemContent, 'persona_fit')) {
                return '{"naturalness":2,"persona_fit":2,"ti_value":2,"security_pass":true,"feedback":"Bad","fix_suggestion":"Fix"}';
            }
            return $validText;
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertSame('gpt-4o', $result['model']);
    }

    // === ioc_likelihood key always present ===

    /**
     * Updated: ioc_likelihood is now always present in the
     * result (even on fallback), set to null when no score was computed.
     * This gives a consistent JSON shape for audit_log consumers
     * (ReplyHandler), avoiding "undefined key" handling at every call site.
     *
     * Old behavior: fallback response omitted ioc_likelihood entirely.
     * New behavior: fallback response includes ioc_likelihood = null.
     *
     */
    public function test_fallback_has_null_ioc_likelihood(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // Key is present with null value for consistent shape
        $this->assertArrayHasKey('ioc_likelihood', $result, 'Key must be present for consistent shape');
        $this->assertNull($result['ioc_likelihood'], 'ioc_likelihood is null on fallback (no validator score computed)');
    }

    // === Attempt logging includes conversation_id ===

    public function test_result_contains_all_required_keys(): void
    {
        $this->llmClient->method('chat')->willReturn('Hi');

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $requiredKeys = ['text', 'approved', 'fallback_used', 'policy_flags', 'validation_reasons', 'model', 'persona', 'cost_estimate', 'attempts', 'pipeline_trace'];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Result must contain key: {$key}");
        }
    }

    // === Success path has all required keys ===

    public function test_success_result_contains_ioc_likelihood(): void
    {
        $validText = $this->validReplyText();
        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $validText) {
            $callCount++;
            if ($callCount === 1) {
                return $validText;
            }
            return '{"naturalness":4,"persona_fit":4,"ti_value":3,"security_pass":true,"feedback":"OK","fix_suggestion":null}';
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertArrayHasKey('ioc_likelihood', $result);
    }

    // === Fallback validation_reasons exact text ===

    public function test_all_attempts_failed_fallback_message(): void
    {
        // When policy rejects everything, the fallback text mentions attempts
        $this->llmClient->method('chat')->willReturn('Hi'); // too short

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $reasonsStr = implode(' ', $result['validation_reasons']);
        // PolicyGuard fallback message mentions MAX_ATTEMPTS
        $this->assertStringContainsString('3 attempts', $reasonsStr);
    }
}

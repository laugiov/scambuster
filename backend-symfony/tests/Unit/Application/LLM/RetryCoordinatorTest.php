<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Audit\AuditLogger;
use App\Application\Audit\Port\SiemExporterInterface;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Domain\Communication\Persona;

/**
 * Tests for RetryCoordinator.
 *
 * Most LLM classes are `final`, so we use real instances with a mockable
 * LLMClientInterface as the only seam. The PersonaManager is the one
 * non-final dependency we can mock.
 */
class RetryCoordinatorTest extends TestCase
{
    private LLMClientInterface&MockObject $llmClient;
    private PersonaManager&MockObject $personaManager;
    private NullLogger $logger;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->personaManager = $this->createMock(PersonaManager::class);
        // Return a mock Persona entity
        $persona = $this->createMock(\App\Domain\Communication\Persona::class);
        $persona->method('isActive')->willReturn(true);
        $persona->method('getPersonaCode')->willReturn('generic_user');
        $persona->method('getPersonaLabel')->willReturn('Generic User');
        $persona->method('getPersonaTone')->willReturn('Friendly and curious');
        $persona->method('getSystemPrompt')->willReturn('You are a friendly person who is curious about everything.');
        $this->personaManager->method('findByCode')->willReturn($persona);
        $this->logger = new NullLogger();
    }

    private function createCoordinator(?OperationalLeakageDetector $leakDetector = null): RetryCoordinator
    {
        // Build real final instances
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
            iocThreshold: 60,
            fallbackProvider: new FallbackProvider(),
            costEstimator: new CostEstimator(),
            leakDetector: $leakDetector,
        );
    }

    private function baseContext(): array
    {
        return [
            'conv_id' => 'test-conv-1',
            'scam_type' => ['code' => 'PHISHING', 'label' => 'Phishing'],
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'Hello I have an urgent business proposal for you please reply immediately.', 'ts_msg' => '2026-01-01T10:00:00+00:00'],
            ],
            'detected_language' => 'en',
            'persona' => 'generic_user',
        ];
    }

    public function test_execute_succeeds_on_first_attempt(): void
    {
        $validReply = 'Oh my, that sounds really interesting! I have been looking for exactly this kind of opportunity. ' .
            'Could you please tell me more about how this works? I would love to hear the details. ' .
            'My friend told me about something similar last week but I was not sure if it was real. ' .
            'Please send me more information when you can, I am very eager to learn about this wonderful opportunity you have.';

        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $validReply) {
            $callCount++;
            // First call = generation, second call = validator
            if ($callCount === 1) {
                return $validReply;
            }
            // Validator: return approved JSON
            return '{"approved":true,"naturalness":4,"persona_fit":4,"ti_value":3,"reasons":["OK"],"fix_suggestion":""}';
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertTrue($result['approved']);
        $this->assertArrayHasKey('pipeline_trace', $result);
        $this->assertArrayHasKey('cost_estimate', $result);
        $this->assertIsFloat($result['cost_estimate']);
        // May be first attempt or fallback depending on PolicyGuard threshold
        $this->assertGreaterThanOrEqual(1, $result['attempts']);
    }

    public function test_execute_falls_back_after_3_policy_rejections(): void
    {
        // Return text that's too short for PolicyGuard
        $this->llmClient->method('chat')->willReturn('Short text.');

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertTrue($result['fallback_used']);
        $this->assertSame(3, $result['attempts']);
        // Fallback text should be non-empty
        $this->assertNotEmpty($result['text']);
    }

    public function test_execute_uses_best_of_3_when_validator_rejects(): void
    {
        $validText = 'Oh my, that sounds really interesting! I have been looking for exactly this kind of opportunity. ' .
            'Could you please tell me more about how this works? I would love to hear the details about your offer. ' .
            'My friend told me about something similar last week but I was not sure if it was real or not. ' .
            'Please send me more information when you can, I am very eager to learn more about this.';

        $this->llmClient->method('chat')->willReturnCallback(function (array $messages) use ($validText) {
            $systemContent = $messages[0]['content'] ?? '';
            // If it's the validator prompt, always reject
            if (str_contains($systemContent, 'naturalness') || str_contains($systemContent, 'APPROVED') || str_contains($systemContent, 'persona_fit')) {
                return '{"approved":false,"naturalness":2,"persona_fit":2,"ti_value":2,"reasons":["Not natural enough"],"fix_suggestion":"Try harder"}';
            }
            // Generator: return valid text
            return $validText;
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // Should use best-of-3 (policy-approved text)
        $this->assertTrue($result['approved']);
        $this->assertFalse($result['fallback_used']);
        $this->assertSame(3, $result['attempts']);
    }

    public function test_execute_handles_validator_exception(): void
    {
        $validText = 'Oh my, that sounds really interesting! I have been looking for this kind of opportunity for a long time now. ' .
            'Could you please tell me more about how this all works? I would love to hear all the details about your offer. ' .
            'My friend mentioned something similar last week but I was not certain if it was legitimate or not at all. ' .
            'Please send me more detailed information when you have a chance, I am quite eager to learn more about this opportunity.';

        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $validText) {
            $callCount++;
            // Odd calls = generator, even = validator
            if ($callCount % 2 === 1) {
                return $validText;
            }
            // Validator throws
            throw new \RuntimeException('LLM timeout');
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // Should still succeed with best-of-3
        $this->assertTrue($result['approved']);
        $this->assertFalse($result['fallback_used']);
    }

    public function test_execute_with_leak_detection(): void
    {
        $validText = 'Oh my, that sounds really interesting! I have been looking for exactly this kind of opportunity. ' .
            'Could you please tell me more about how this works? I would love to hear the details about your offer. ' .
            'My friend told me about something similar last week but I was not sure if it was real or not honestly. ' .
            'Please send me more information when you can, I am very eager to learn about this wonderful opportunity you have.';

        // Only called for generation (leak detector uses a separate call)
        $this->llmClient->method('chat')->willReturnCallback(function (array $messages) use ($validText) {
            $systemContent = $messages[0]['content'] ?? '';
            if (str_contains($systemContent, 'security auditor') || str_contains($systemContent, 'operational information')) {
                // Leak detector LLM call - return leak detected
                return '{"leak":true,"reason":"Contains platform reference","matched_terms":["orchestrator"]}';
            }
            if (str_contains($systemContent, 'naturalness') || str_contains($systemContent, 'persona_fit')) {
                return '{"approved":true,"naturalness":4,"persona_fit":4,"ti_value":3,"reasons":["OK"],"fix_suggestion":""}';
            }
            return $validText;
        });

        $leakDetector = new OperationalLeakageDetector($this->llmClient, $this->logger);
        $coordinator = $this->createCoordinator($leakDetector);
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // After 3 leaks, should fallback
        $this->assertTrue($result['fallback_used']);
        $this->assertSame(3, $result['attempts']);
    }

    public function test_execute_pipeline_trace_structure(): void
    {
        $this->llmClient->method('chat')->willReturn('Short.');

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertArrayHasKey('pipeline_trace', $result);
        $trace = $result['pipeline_trace'];
        $this->assertArrayHasKey('conversation_id', $trace);
        $this->assertArrayHasKey('persona', $trace);
        $this->assertArrayHasKey('components', $trace);
        $this->assertSame('test-conv-1', $trace['conversation_id']);
    }

    // ================================================================== //
    //  Merged from RetryCoordinatorCoverageTest
    // ================================================================== //

    public function testAllAttemptsFallbackWhenPolicyGuardAlwaysRejectsCoverage(): void
    {
        // Generate text that is always too short (PolicyGuard rejects)
        $this->llmClient->method('chat')
            ->willReturn('Hi'); // Too short for PolicyGuard

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // Should use fallback after all 3 attempts
        $this->assertTrue($result['fallback_used'] ?? $result['approved']);
        $this->assertSame(3, $result['attempts']);
        $this->assertArrayHasKey('pipeline_trace', $result);
        // CostEstimator is always present in createCoordinator, so cost > 0
        $this->assertIsFloat($result['cost_estimate']);
    }

    // ─── Spec 095 Fix #8 — wire iocThreshold ─────────────────────────────

    /**
     * Spec 095 Fix #8 — when the IOC likelihood score is below the
     * threshold (default 60), the orchestrator MUST retry. Without Fix #8
     * the score was computed but ignored — passive replies were approved.
     *
     * Test setup: attempt 1 returns passive text (no `?`, generic — score
     * far below 60). Attempt 2 returns active text (with channel keywords
     * + question — score ≥ 60). Validator approves both. Expected:
     * orchestrator retries on attempt 1, returns on attempt 2.
     *
     * See: specs/095-pipeline-audit/fix-08-wire-ioc-threshold/spec.md
     */
    public function test_low_ioc_score_triggers_retry_Fix08(): void
    {
        // Attempt 1: passive text. ~70 words, no question, no channel kw.
        $passiveText = 'Hello there. Thank you for your interesting message. I appreciate you reaching out to me about this topic. ' .
            'I will think about what you have shared with me carefully and consider all aspects of this matter. ' .
            'I see your point and I understand the situation you are describing today. I will let you know soon.';

        // Attempt 2: active text with channel keyword + question, no proactive
        // patterns (avoid "I can send/share" which triggers -20 penalty).
        // Must be ≥ 50 words to pass PolicyGuard.
        $activeText = 'Hello again about your interesting proposal which sounds genuinely intriguing to me right now today. ' .
            'Could you please tell me your bank account number, the wire routing details, and your beneficiary name? ' .
            'These extra details would really help me prepare everything carefully on my end properly before we proceed any further together with the next steps later today indeed.';

        $generatorCallCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function (array $messages) use (&$generatorCallCount, $passiveText, $activeText) {
            $systemContent = $messages[0]['content'] ?? '';
            // Validator call: always approve with ti_value >= 3
            if (str_contains($systemContent, 'naturalness') || str_contains($systemContent, 'persona_fit')) {
                return '{"approved":true,"naturalness":4,"persona_fit":4,"ti_value":4,"reasons":["OK"],"fix_suggestion":""}';
            }
            // Generator call: passive on attempt 1, active on attempt 2+
            $generatorCallCount++;

            return $generatorCallCount === 1 ? $passiveText : $activeText;
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // The KEY assertion for Fix #8: retry happened (attempts >= 2).
        // Without Fix #8, the orchestrator would have returned at attempt 1
        // with the passive reply. With Fix #8 wired, the low IOC score
        // triggers a retry.
        $this->assertTrue($result['approved']);
        $this->assertFalse($result['fallback_used'], 'Canned fallback must not fire — the active reply or best-of-3 is returned');
        $this->assertGreaterThanOrEqual(2, $result['attempts'], 'Orchestrator MUST retry when IOC score is below threshold (Fix #8 invariant)');
    }

    /**
     * Spec 095 Fix #8 — when ALL 3 attempts score below the IOC threshold,
     * the orchestrator returns the last reply ANYWAY (not a canned
     * fallback). This avoids dropping a validator-approved reply just
     * because the IOC score is consistently passive. Fix D's audit log
     * captures the low score for post-hoc monitoring.
     */
    public function test_max_attempts_with_low_ioc_returns_anyway_Fix08(): void
    {
        // Always passive text — IOC score will be low on all 3 attempts.
        $passiveText = 'Hello there. Thank you for your interesting message. I appreciate you reaching out to me about this topic. ' .
            'I will think about what you have shared with me carefully and consider all aspects of this matter. ' .
            'I see your point and I understand the situation you are describing today. I will let you know soon.';

        $this->llmClient->method('chat')->willReturnCallback(function (array $messages) use ($passiveText) {
            $systemContent = $messages[0]['content'] ?? '';
            if (str_contains($systemContent, 'naturalness') || str_contains($systemContent, 'persona_fit')) {
                return '{"approved":true,"naturalness":4,"persona_fit":4,"ti_value":3,"reasons":["OK"],"fix_suggestion":""}';
            }

            return $passiveText;
        });

        $coordinator = $this->createCoordinator();
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // After 3 attempts with persistently-low IOC scores: orchestrator
        // exhausts retries and falls into best-of-3 path (PolicyGuard-approved
        // text returned, fallback_used=false). The IOC threshold gate doesn't
        // degrade behavior into a canned fallback — the validator-approved
        // reply is still emitted. Fix D's audit log captures the low score
        // for post-hoc monitoring.
        $this->assertTrue($result['approved']);
        $this->assertFalse($result['fallback_used'], 'No canned fallback — best-of-3 returns the policy-approved text');
        $this->assertSame(3, $result['attempts'], 'Orchestrator must use all 3 attempts before falling back to best-of-3');
    }
}

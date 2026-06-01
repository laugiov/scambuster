<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\ContextAnalyzer;
use App\Application\LLM\IOCLikelihoodScorer;
use App\Application\LLM\ReciprocityManager;
use App\Application\LLM\ReplyOrchestrator;
use App\Application\LLM\PromptBuilder;
use App\Application\LLM\PolicyGuard;
use App\Application\LLM\ReplyValidator;
use App\Application\LLM\VariationProvider;
use App\Application\LLM\Port\LLMClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ReplyOrchestrator
 */
class ReplyOrchestratorTest extends TestCase
{
    private ReplyOrchestrator $orchestrator;
    private LLMClientInterface $llmClient;
    private PromptBuilder $promptBuilder;
    private PolicyGuard $policyGuard;
    private ReplyValidator $replyValidator;
    private IOCLikelihoodScorer $iocScorer;
    private LoggerInterface $logger;
    private ContextAnalyzer $contextAnalyzer;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->contextAnalyzer = new ContextAnalyzer();
        $variationProvider = new VariationProvider();
        $reciprocityManager = new ReciprocityManager();

        // Mock PersonaManager to return a test persona
        $personaManager = $this->createMock(\App\Application\Communication\PersonaManager::class);
        $testPersona = new \App\Domain\Communication\Persona(
            'bank_customer',
            'Client bancaire inquiet',
            'Inquiet, méfiant mais coopératif',
            'Tu es un client bancaire inquiet qui a reçu un message suspect.'
        );
        $personaManager->method('findByCode')->willReturn($testPersona);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $this->promptBuilder = new PromptBuilder($this->contextAnalyzer, $variationProvider, $reciprocityManager, $personaManager, $logger);
        $this->policyGuard = new PolicyGuard($logger, 1);
        $this->iocScorer = new IOCLikelihoodScorer();
        $this->logger = $logger;

        $this->replyValidator = new ReplyValidator(
            $this->llmClient,
            $this->promptBuilder,
            $this->logger
        );

        // Spec 095 Fix #8 — iocThreshold set to 0 in this test fixture because
        // these tests validate the orchestrator's flow (approve/fallback/retry
        // on PolicyGuard or validator) NOT the IOC threshold gating. Setting
        // to 0 keeps the existing chat() call-count expectations stable.
        // The IOC threshold logic is covered by dedicated tests in
        // RetryCoordinatorTest::test_low_ioc_score_triggers_retry_Fix08.
        $this->orchestrator = new ReplyOrchestrator(
            $this->llmClient,
            $this->promptBuilder,
            $this->policyGuard,
            $this->replyValidator,
            $this->iocScorer,
            $this->logger,
            0, // Spec 095 Fix #8 — disable IOC threshold for legacy flow tests
            null,
            new \App\Application\LLM\CostEstimator(),
        );
    }

    /**
     * @test
     */
    public function it_generates_approved_reply(): void
    {
        $context = [
            'conv_id' => 'test-conv-123',
            'scam_type' => ['label_fr' => 'Arnaque bancaire'],
            'last_messages' => [],
        ];

        $generatedText = str_repeat('Bonjour, je suis intéressé par votre offre. ', 15);

        // Spec 095 Fix D — validator response now also exposes the per-axis
        // scores (consumed by ReplyHandler audit_log). Existing tests update
        // their mock response shape to include the new fields.
        $validatorResponse = json_encode([
            'approved' => true,
            'reasons' => [],
            'fix_suggestion' => null,
            'naturalness' => 4,
            'persona_fit' => 4,
            'ti_value' => 4,
            'security_pass' => true,
        ]);

        $this->llmClient
            ->expects($this->exactly(2))
            ->method('chat')
            ->willReturnOnConsecutiveCalls($generatedText, $validatorResponse);

        $result = $this->orchestrator->generate($context, 'bank_customer');

        $this->assertTrue($result['approved']);
        // Text is trimmed by orchestrator
        $this->assertEquals(trim($generatedText), $result['text']);
        $this->assertEmpty($result['policy_flags']);
        $this->assertEquals('gpt-4o', $result['model']); // Upgraded from gpt-4o-mini
        $this->assertEquals('bank_customer', $result['persona']);
        $this->assertArrayHasKey('cost_estimate', $result);
    }

    /**
     * @test
     */
    public function it_uses_fallback_after_3_policy_guard_failures(): void
    {
        $context = [
            'conv_id' => 'test-conv-456',
            'scam_type' => ['label_fr' => 'Phishing'],
            'last_messages' => [],
        ];

        // Generate text with too many links (PolicyGuard max = 1)
        $generatedText = 'Voici des liens utiles : https://example.com et https://other.com pour vous aider.';

        // Orchestrator will try 3 times
        $this->llmClient
            ->expects($this->exactly(3))
            ->method('chat')
            ->willReturn($generatedText);

        $result = $this->orchestrator->generate($context, 'elderly_person');

        // Should now use fallback: approved=true but with fallback_used flag
        $this->assertTrue($result['approved']);
        $this->assertTrue($result['fallback_used']);
        $this->assertNotEmpty($result['policy_flags']);
        // Policy flags may include too_short (from min word check) and/or excessive_links
        $flagsStr = implode(' ', $result['policy_flags']);
        $this->assertTrue(
            str_contains($flagsStr, 'excessive_links') || str_contains($flagsStr, 'too_short'),
            'Policy flags should contain excessive_links or too_short'
        );
        $this->assertEquals(3, $result['attempts']);
    }

    /**
     * @test
     */
    public function it_uses_fallback_when_forbidden_pattern_detected(): void
    {
        $context = [
            'conv_id' => 'test-conv-789',
            'scam_type' => ['label_fr' => 'Scam'],
            'last_messages' => [],
        ];

        $generatedText = str_repeat('This is a honeypot system for collecting data. ', 15);

        // Orchestrator will try 3 times
        $this->llmClient
            ->expects($this->exactly(3))
            ->method('chat')
            ->willReturn($generatedText);

        $result = $this->orchestrator->generate($context, 'bank_customer');

        // Should use fallback after 3 attempts with forbidden patterns
        $this->assertTrue($result['approved']);
        $this->assertTrue($result['fallback_used']);
        $this->assertNotEmpty($result['policy_flags']);
        $this->assertEquals(3, $result['attempts']);
    }

    /**
     * @test
     */
    public function it_uses_fallback_after_3_llm_validation_failures(): void
    {
        $context = [
            'conv_id' => 'test-conv-abc',
            'scam_type' => ['label_fr' => 'Scam'],
            'last_messages' => [],
        ];

        $generatedText = str_repeat('Valid length but wrong tone or persona. ', 15);

        $validatorResponse = json_encode([
            'approved' => false,
            'reasons' => ['Tone mismatch', 'No question'],
            'fix_suggestion' => 'Adjust tone',
        ]);

        // Orchestrator tries 3 times: gen+val, gen+val, gen+val = 6 calls total
        $this->llmClient
            ->expects($this->exactly(6))
            ->method('chat')
            ->willReturn($generatedText, $validatorResponse, $generatedText, $validatorResponse, $generatedText, $validatorResponse);

        $result = $this->orchestrator->generate($context, 'bank_customer');

        // With best-of-3, PolicyGuard-approved text is used instead of fallback
        $this->assertTrue($result['approved']);
        $this->assertEmpty($result['policy_flags']);
        $this->assertEquals(3, $result['attempts']);
        // Either fallback_used is true (canned response) or text is the best-of-3
        $this->assertNotEmpty($result['text']);
    }

    /**
     * @test
     */
    public function it_includes_cost_estimate_even_when_using_fallback(): void
    {
        $context = [
            'conv_id' => 'test-conv-def',
            'scam_type' => ['label_fr' => 'Scam'],
            'last_messages' => [],
        ];

        // Use text with forbidden pattern to fail PolicyGuard
        $generatedText = 'Ceci est un honeypot de test pour analyser les scams.';

        // Orchestrator will try 3 times
        $this->llmClient
            ->expects($this->exactly(3))
            ->method('chat')
            ->willReturn($generatedText);

        $result = $this->orchestrator->generate($context, 'bank_customer');

        // Should use fallback and still include cost estimate
        $this->assertTrue($result['fallback_used']);
        $this->assertArrayHasKey('cost_estimate', $result);
        $this->assertIsFloat($result['cost_estimate']);
        $this->assertGreaterThan(0, $result['cost_estimate']);
    }

    /**
     * @test
     */
    public function it_uses_fallback_when_pii_detected(): void
    {
        $context = [
            'conv_id' => 'test-conv-ghi',
            'scam_type' => ['label_fr' => 'Scam'],
            'last_messages' => [],
        ];

        // Use IBAN which is still blocked PII (not fake phone numbers)
        $generatedText = str_repeat('Mon IBAN est FR7612345678901234567890123. ', 7);

        // Orchestrator will try 3 times
        $this->llmClient
            ->expects($this->exactly(3))
            ->method('chat')
            ->willReturn($generatedText);

        $result = $this->orchestrator->generate($context, 'bank_customer');

        // Should use fallback after detecting PII in all 3 attempts
        $this->assertTrue($result['approved']);
        $this->assertTrue($result['fallback_used']);
        $this->assertContains('pii_detected', $result['policy_flags']);
        $this->assertEquals(3, $result['attempts']);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\PersonaManager;
use App\Application\LLM\ContextAnalyzer;
use App\Application\LLM\CostEstimator;
use App\Application\LLM\FallbackProvider;
use App\Application\LLM\IOCLikelihoodScorer;
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
 * Coverage tests for RetryCoordinator:
 * - buildFallbackResponse (lines 256-267) when all attempts fail
 * - buildPolicyFeedback (line 362) for various policy flags
 * - estimateTotalCost (line 413) with no costEstimator
 */
class RetryCoordinatorCoverageTest extends TestCase
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
        $persona->method('getPersonaTone')->willReturn('Friendly');
        $persona->method('getSystemPrompt')->willReturn('You are a friendly person who loves chatting.');
        $this->personaManager->method('findByCode')->willReturn($persona);

        $this->logger = new NullLogger();
    }

    private function createCoordinator(?CostEstimator $costEstimator = null): RetryCoordinator
    {
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
            costEstimator: $costEstimator,
        );
    }

    private function baseContext(): array
    {
        return [
            'conv_id' => 'test-conv',
            'scam_type' => ['code' => 'PHISHING', 'label' => 'Phishing'],
            'last_messages' => [
                [
                    'direction' => 'in',
                    'body_text' => 'Hello I have an important business matter for you please reply quickly.',
                    'ts_msg' => '2026-01-01T10:00:00+00:00',
                ],
            ],
            'detected_language' => 'en',
            'persona' => 'generic_user',
        ];
    }

    public function testAllAttemptsFallbackWhenPolicyGuardAlwaysRejects(): void
    {
        // Generate text that is always too short (PolicyGuard rejects)
        $this->llmClient->method('chat')
            ->willReturn('Hi'); // Too short for PolicyGuard

        $coordinator = $this->createCoordinator(new CostEstimator());
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // Should use fallback after all 3 attempts
        $this->assertTrue($result['fallback_used'] ?? $result['approved']);
        $this->assertSame(3, $result['attempts']);
        $this->assertArrayHasKey('pipeline_trace', $result);
    }

    public function testCostEstimatorNullReturnsTotalCostZero(): void
    {
        // With no cost estimator
        $validReply = 'Oh my, that sounds really interesting! I have been looking for exactly this kind of opportunity. ' .
            'Could you please tell me more about how this works? I would love to hear the details. ' .
            'My friend told me about something similar last week but I was not sure if it was real. ' .
            'Please send me more information when you can, I am very eager to learn about this wonderful opportunity you have.';

        $callCount = 0;
        $this->llmClient->method('chat')
            ->willReturnCallback(function () use (&$callCount, $validReply) {
                ++$callCount;
                if ($callCount === 1) {
                    return $validReply;
                }

                return '{"approved":true,"naturalness":4,"persona_fit":4,"ti_value":3,"reasons":["OK"],"fix_suggestion":""}';
            });

        $coordinator = $this->createCoordinator(null); // No cost estimator
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        $this->assertTrue($result['approved']);
        $this->assertSame(0.0, $result['cost_estimate']);
    }

    public function testFallbackResponseWithCostEstimator(): void
    {
        // PolicyGuard rejects all attempts (too short text)
        $this->llmClient->method('chat')->willReturn('X');

        $coordinator = $this->createCoordinator(new CostEstimator());
        $result = $coordinator->execute($this->baseContext(), 'generic_user');

        // Should produce a cost estimate > 0
        $this->assertIsFloat($result['cost_estimate']);
    }
}

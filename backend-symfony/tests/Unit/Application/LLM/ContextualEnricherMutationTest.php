<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\MessageAnonymizer;
use App\Application\LLM\ContextualEnrichmentRequest;
use App\Application\LLM\ContextualEnrichmentResult;
use App\Application\LLM\ContextualEnricher;
use App\Application\LLM\Prompt\PromptProvider;
use App\Application\LLM\Port\LLMClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Mutation-killing tests for ContextualEnricher.
 *
 * Targets:
 * - LLM messages array (system 'cybersecurity analyst' + user role)
 * - Temperature 0.3, max_tokens 500, purpose 'contextual_enrichment'
 * - JSON extraction from markdown code blocks
 * - Non-array response => null
 * - Available message count (1, 2, 3)
 * - Event dispatch (LlmCallCompletedEvent)
 * - Prompt template replacements
 * - Failure => null (never throws)
 * - ContextualEnrichmentResult factory method validation
 */
final class ContextualEnricherMutationTest extends TestCase
{
    private LLMClientInterface&MockObject $llmClient;
    private EventDispatcherInterface&MockObject $dispatcher;
    private ContextualEnricher $enricher;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);

        // MessageAnonymizer is final — instantiate real one
        $anonymizer = new MessageAnonymizer();

        $this->enricher = new ContextualEnricher(
            $this->llmClient,
            $anonymizer,
            $this->dispatcher,
            new NullLogger(),
            new PromptProvider('/nonexistent-prompt-dir', new NullLogger()),
        );
    }

    private function makeRequest(
        ?string $stimulus = 'Our reply text',
        ?string $previousInbound = 'Previous scammer message',
    ): ContextualEnrichmentRequest {
        return new ContextualEnrichmentRequest(
            iocTypes: ['url', 'iban'],
            scamType: 'PHISHING',
            personaCode: 'elderly_person',
            revelationTurn: 3,
            totalTurns: 5,
            revelationMessageText: 'Click here http://evil.com and send money',
            stimulusMessageText: $stimulus,
            previousInboundText: $previousInbound,
        );
    }

    private function validLlmResponse(): string
    {
        return json_encode([
            'stimulus_type' => 'DIRECT_REQUEST',
            'scammer_urgency_score' => 0.65,
            'language_switch_detected' => false,
            'hesitation_detected' => false,
            'context_excerpt' => 'Scammer provided payment details after victim asked for payment information',
            'enrichment_confidence' => 0.82,
            'ioc_roles' => [
                ['type' => 'url', 'role' => 'PHISHING_CREDENTIAL_URL'],
                ['type' => 'iban', 'role' => 'MONEY_MULE_ACCOUNT'],
            ],
        ]);
    }

    // === Successful enrichment ===

    public function test_successful_enrichment_returns_result(): void
    {
        $this->llmClient->method('chat')->willReturn($this->validLlmResponse());
        $this->dispatcher->expects($this->once())->method('dispatch');

        $result = $this->enricher->enrich($this->makeRequest());

        $this->assertNotNull($result);
        $this->assertSame('DIRECT_REQUEST', $result->stimulusType);
        $this->assertSame(0.65, $result->urgencyScore);
        $this->assertFalse($result->languageSwitch);
        $this->assertFalse($result->hesitationDetected);
        $this->assertSame(0.82, $result->enrichmentConfidence);
    }

    // === IOC roles mapped correctly ===

    public function test_ioc_roles_mapped(): void
    {
        $this->llmClient->method('chat')->willReturn($this->validLlmResponse());

        $result = $this->enricher->enrich($this->makeRequest());

        $this->assertSame('PHISHING_CREDENTIAL_URL', $result->iocRoles['url']);
        $this->assertSame('MONEY_MULE_ACCOUNT', $result->iocRoles['iban']);
    }

    // === LLM messages structure ===

    public function test_llm_called_with_system_and_user_roles(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages, array $options) {
                $this->assertCount(2, $messages);
                $this->assertSame('system', $messages[0]['role']);
                $this->assertSame('user', $messages[1]['role']);
                $this->assertStringContainsString('cybersecurity analyst', $messages[0]['content']);
                $this->assertStringContainsString('valid JSON', $messages[0]['content']);

                return $this->validLlmResponse();
            });

        $this->enricher->enrich($this->makeRequest());
    }

    public function test_llm_options_temperature_and_max_tokens(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages, array $options) {
                $this->assertSame(0.3, $options['temperature']);
                $this->assertSame(500, $options['max_tokens']);
                $this->assertSame('contextual_enrichment', $options['purpose']);

                return $this->validLlmResponse();
            });

        $this->enricher->enrich($this->makeRequest());
    }

    // === JSON extraction from markdown ===

    public function test_json_extracted_from_markdown_code_block(): void
    {
        $wrappedResponse = "```json\n" . $this->validLlmResponse() . "\n```";
        $this->llmClient->method('chat')->willReturn($wrappedResponse);

        $result = $this->enricher->enrich($this->makeRequest());
        $this->assertNotNull($result);
        $this->assertSame('DIRECT_REQUEST', $result->stimulusType);
    }

    // === Non-array response => null ===

    public function test_non_array_response_returns_null(): void
    {
        $this->llmClient->method('chat')->willReturn('"just a string"');

        $result = $this->enricher->enrich($this->makeRequest());
        $this->assertNull($result);
    }

    // === Exception => null (never throws) ===

    public function test_llm_exception_returns_null(): void
    {
        $this->llmClient->method('chat')->willThrowException(new \RuntimeException('timeout'));

        $result = $this->enricher->enrich($this->makeRequest());
        $this->assertNull($result);
    }

    public function test_invalid_json_returns_null(): void
    {
        $this->llmClient->method('chat')->willReturn('not valid json at all {broken');

        $result = $this->enricher->enrich($this->makeRequest());
        $this->assertNull($result);
    }

    // === Prompt template replacements ===

    public function test_prompt_contains_scam_type(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) {
                $prompt = $messages[1]['content'];
                $this->assertStringContainsString('PHISHING', $prompt);

                return $this->validLlmResponse();
            });

        $this->enricher->enrich($this->makeRequest());
    }

    public function test_prompt_contains_persona_code(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) {
                $prompt = $messages[1]['content'];
                $this->assertStringContainsString('elderly_person', $prompt);

                return $this->validLlmResponse();
            });

        $this->enricher->enrich($this->makeRequest());
    }

    public function test_prompt_contains_ioc_types(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) {
                $prompt = $messages[1]['content'];
                $this->assertStringContainsString('url', $prompt);
                $this->assertStringContainsString('iban', $prompt);

                return $this->validLlmResponse();
            });

        $this->enricher->enrich($this->makeRequest());
    }

    public function test_prompt_contains_revelation_turn(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) {
                $prompt = $messages[1]['content'];
                $this->assertStringContainsString('3', $prompt);
                $this->assertStringContainsString('5', $prompt);

                return $this->validLlmResponse();
            });

        $this->enricher->enrich($this->makeRequest());
    }

    // === Event dispatched ===

    public function test_event_dispatched_on_success(): void
    {
        $this->llmClient->method('chat')->willReturn($this->validLlmResponse());
        $this->dispatcher->expects($this->once())->method('dispatch');

        $this->enricher->enrich($this->makeRequest());
    }

    // === ContextualEnrichmentResult: fromLlmResponse tests ===

    public function test_result_unknown_stimulus_type_defaults_to_unknown(): void
    {
        $result = ContextualEnrichmentResult::fromLlmResponse(
            ['stimulus_type' => 'INVALID_TYPE', 'scammer_urgency_score' => 0.5],
            ['url'],
            3,
        );
        $this->assertSame('UNKNOWN', $result->stimulusType);
    }

    public function test_result_urgency_score_clamped_to_0_1(): void
    {
        $result = ContextualEnrichmentResult::fromLlmResponse(
            ['stimulus_type' => 'PASSIVE', 'scammer_urgency_score' => 1.5],
            [],
            3,
        );
        $this->assertSame(1.0, $result->urgencyScore);

        $result2 = ContextualEnrichmentResult::fromLlmResponse(
            ['stimulus_type' => 'PASSIVE', 'scammer_urgency_score' => -0.5],
            [],
            3,
        );
        $this->assertSame(0.0, $result2->urgencyScore);
    }

    public function test_result_confidence_capped_by_available_messages_1(): void
    {
        $result = ContextualEnrichmentResult::fromLlmResponse(
            ['enrichment_confidence' => 0.95],
            ['url'],
            1, // 1 message => max 0.60
        );
        $this->assertLessThanOrEqual(0.60, $result->enrichmentConfidence);
    }

    public function test_result_confidence_capped_by_available_messages_2(): void
    {
        $result = ContextualEnrichmentResult::fromLlmResponse(
            ['enrichment_confidence' => 0.95],
            ['url'],
            2, // 2 messages => max 0.80
        );
        $this->assertLessThanOrEqual(0.80, $result->enrichmentConfidence);
    }

    public function test_result_confidence_capped_at_090_with_3_messages_no_richness(): void
    {
        $result = ContextualEnrichmentResult::fromLlmResponse(
            ['enrichment_confidence' => 0.95],
            ['url'],
            3,
        );
        // Base cap 0.90 with no richness bonuses (no stimulus_message, no ioc_types > 3)
        $this->assertSame(0.90, $result->enrichmentConfidence);
    }

    public function test_result_missing_ioc_types_get_unknown_role(): void
    {
        $result = ContextualEnrichmentResult::fromLlmResponse(
            ['ioc_roles' => [['type' => 'url', 'role' => 'PHISHING_CREDENTIAL_URL']]],
            ['url', 'iban'], // iban not in ioc_roles
            3,
        );
        $this->assertSame('PHISHING_CREDENTIAL_URL', $result->iocRoles['url']);
        $this->assertSame('UNKNOWN', $result->iocRoles['iban']);
    }

    public function test_result_invalid_role_defaults_to_unknown(): void
    {
        $result = ContextualEnrichmentResult::fromLlmResponse(
            ['ioc_roles' => [['type' => 'url', 'role' => 'TOTALLY_INVALID_ROLE']]],
            ['url'],
            3,
        );
        $this->assertSame('UNKNOWN', $result->iocRoles['url']);
    }

    public function test_result_context_excerpt_truncated_to_295(): void
    {
        $longExcerpt = str_repeat('x', 400);
        $result = ContextualEnrichmentResult::fromLlmResponse(
            ['context_excerpt' => $longExcerpt],
            [],
            3,
        );
        $this->assertLessThanOrEqual(295, mb_strlen($result->contextExcerpt));
    }

    public function test_result_valid_stimulus_types_accepted(): void
    {
        foreach (['URGENCY_PRESSURE', 'TRUST_BUILDING', 'DIRECT_REQUEST', 'DOCUMENT_REQUEST', 'PAYMENT_INITIATION', 'PASSIVE'] as $type) {
            $result = ContextualEnrichmentResult::fromLlmResponse(
                ['stimulus_type' => $type],
                [],
                3,
            );
            $this->assertSame($type, $result->stimulusType);
        }
    }

    public function test_result_valid_roles_accepted(): void
    {
        foreach (['PAYMENT_DESTINATION', 'PHISHING_CREDENTIAL_URL', 'CONTACT_CHANNEL', 'INFRASTRUCTURE_DOMAIN', 'MONEY_MULE_ACCOUNT'] as $role) {
            $result = ContextualEnrichmentResult::fromLlmResponse(
                ['ioc_roles' => [['type' => 'url', 'role' => $role]]],
                ['url'],
                3,
            );
            $this->assertSame($role, $result->iocRoles['url']);
        }
    }

    public function test_result_boolean_fields_default_false(): void
    {
        $result = ContextualEnrichmentResult::fromLlmResponse([], [], 3);
        $this->assertFalse($result->languageSwitch);
        $this->assertFalse($result->hesitationDetected);
    }

    public function test_result_missing_urgency_defaults_to_0(): void
    {
        $result = ContextualEnrichmentResult::fromLlmResponse([], [], 3);
        $this->assertSame(0.0, $result->urgencyScore);
    }

    public function test_result_missing_confidence_defaults_to_0(): void
    {
        $result = ContextualEnrichmentResult::fromLlmResponse([], [], 3);
        $this->assertSame(0.0, $result->enrichmentConfidence);
    }

    public function test_result_missing_excerpt_defaults_to_empty(): void
    {
        $result = ContextualEnrichmentResult::fromLlmResponse([], [], 3);
        $this->assertSame('', $result->contextExcerpt);
    }
}

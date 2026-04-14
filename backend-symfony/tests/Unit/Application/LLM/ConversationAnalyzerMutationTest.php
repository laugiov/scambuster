<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\ConversationAnalyzer;
use App\Application\LLM\Port\LLMClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Mutation-killing tests for ConversationAnalyzer.
 *
 * Targets:
 * - Generic instructions tone value
 * - Generic instructions structure keys
 * - Cache key determinism
 * - Message count threshold (< 2)
 * - IOC summary formatting
 * - Conversation history formatting (direction labels)
 * - Long conversation summarization boundaries
 * - IBAN capture detection
 * - JSON sanitization (multiplication symbol, trailing comma)
 * - Required field validation
 */
final class ConversationAnalyzerMutationTest extends TestCase
{
    private LLMClientInterface&MockObject $llmClient;
    private LoggerInterface&MockObject $logger;
    private ConversationAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->analyzer = new ConversationAnalyzer($this->llmClient, $this->logger);
    }

    private function contextWith(int $messageCount, array $extraIocs = []): array
    {
        $messages = [];
        for ($i = 0; $i < $messageCount; $i++) {
            $messages[] = [
                'direction' => $i % 2 === 0 ? 'in' : 'out',
                'body_text' => "Message $i content",
                'ts_msg' => '2026-01-01T' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ':00:00+00:00',
            ];
        }

        return [
            'conversation_id' => 'test-conv-mut',
            'scam_type' => 'PHISHING',
            'persona_code' => 'generic_user',
            'all_messages' => $messages,
            'extracted_iocs' => $extraIocs,
        ];
    }

    private function validLlmResponse(array $overrides = []): string
    {
        $data = array_merge([
            'repetitions_detected' => [],
            'strategic_analysis' => 'Test analysis',
            'missing_iocs' => [],
            'tone_recommendation' => 'inquiet',
            'strategic_suggestions' => [],
            'instructions' => [
                'interdictions' => [],
                'obligations' => ['Varie ton style'],
                'objectif_strategique' => 'Obtenir des IOCs',
                'style_ton' => 'Naturel',
            ],
        ], $overrides);

        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    // === Generic instructions (< 2 messages) ===

    public function test_generic_tone_is_inquiet(): void
    {
        // Kills: 'inquiet' -> '' or other value
        $result = $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(1));
        $this->assertSame('inquiet', $result['tone_recommendation']);
    }

    public function test_generic_analysis_text_contains_pas_assez(): void
    {
        $result = $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(1));
        $this->assertStringContainsString('Pas assez de messages', $result['analysis']);
    }

    public function test_generic_repetitions_detected_is_empty_array(): void
    {
        $result = $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(1));
        $this->assertSame([], $result['repetitions_detected']);
    }

    public function test_generic_strategic_suggestions_is_empty_array(): void
    {
        $result = $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(1));
        $this->assertSame([], $result['strategic_suggestions']);
    }

    public function test_generic_instructions_has_interdictions_key(): void
    {
        $result = $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(1));
        $this->assertArrayHasKey('interdictions', $result['instructions_for_llm']);
    }

    public function test_generic_instructions_has_obligations_key(): void
    {
        $result = $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(1));
        $this->assertArrayHasKey('obligations', $result['instructions_for_llm']);
    }

    public function test_generic_instructions_has_objectif_strategique(): void
    {
        $result = $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(1));
        $this->assertArrayHasKey('objectif_strategique', $result['instructions_for_llm']);
    }

    public function test_generic_instructions_has_style_ton(): void
    {
        $result = $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(1));
        $this->assertArrayHasKey('style_ton', $result['instructions_for_llm']);
    }

    public function test_generic_obligations_count_is_3(): void
    {
        $result = $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(1));
        $this->assertCount(3, $result['instructions_for_llm']['obligations']);
    }

    public function test_generic_interdictions_count_is_1(): void
    {
        $result = $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(1));
        $this->assertCount(1, $result['instructions_for_llm']['interdictions']);
    }

    // === Threshold: < 2 messages triggers generic ===

    public function test_0_messages_returns_generic(): void
    {
        $result = $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(0));
        $this->assertSame('inquiet', $result['tone_recommendation']);
        $this->assertStringContainsString('Pas assez', $result['analysis']);
    }

    public function test_1_message_returns_generic(): void
    {
        $result = $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(1));
        $this->assertSame('inquiet', $result['tone_recommendation']);
    }

    public function test_2_messages_calls_llm_not_generic(): void
    {
        // Kills: count < 2 mutated to count < 1 or count <= 2
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->willReturn($this->validLlmResponse());

        $result = $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(2));
        $this->assertSame('inquiet', $result['tone_recommendation']);
    }

    // === Cache key determinism ===

    public function test_cache_key_same_for_same_conv_and_count(): void
    {
        // Kills: cache key mutation (removing conv_id or count)
        $this->llmClient->expects($this->once()) // Only 1 LLM call = cache hit on second
            ->method('chat')
            ->willReturn($this->validLlmResponse());

        $context = $this->contextWith(3);
        $result1 = $this->analyzer->analyzeAndGenerateInstructions($context);
        $result2 = $this->analyzer->analyzeAndGenerateInstructions($context);

        $this->assertSame($result1, $result2);
    }

    public function test_cache_key_different_for_different_message_count(): void
    {
        $this->llmClient->expects($this->exactly(2))
            ->method('chat')
            ->willReturn($this->validLlmResponse());

        $context2 = $this->contextWith(2);
        $context3 = $this->contextWith(3);
        $context3['conversation_id'] = 'test-conv-mut'; // same conv_id

        $this->analyzer->analyzeAndGenerateInstructions($context2);
        $this->analyzer->analyzeAndGenerateInstructions($context3);

        $this->addToAssertionCount(1); // verified by expects(exactly(2))
    }

    // === IOC summary formatting ===

    public function test_no_iocs_shows_aucun_in_prompt(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function ($messages) {
                    $this->assertStringContainsString('Aucun IOC extrait', $messages[0]['content']);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn($this->validLlmResponse());

        $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(2));
    }

    public function test_iocs_grouped_by_type_in_prompt(): void
    {
        $iocs = [
            ['type' => 'email', 'value' => 'a@b.com'],
            ['type' => 'email', 'value' => 'c@d.com'],
            ['type' => 'phone', 'value' => '+33123456789'],
        ];

        $this->llmClient->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function ($messages) {
                    $prompt = $messages[0]['content'];
                    $this->assertStringContainsString('email (2)', $prompt);
                    $this->assertStringContainsString('phone (1)', $prompt);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn($this->validLlmResponse());

        $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(2, $iocs));
    }

    // === Conversation history formatting ===

    public function test_in_direction_labeled_scammer(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function ($messages) {
                    $this->assertStringContainsString('SCAMMER', $messages[0]['content']);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn($this->validLlmResponse());

        $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(2));
    }

    public function test_out_direction_labeled_victime(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function ($messages) {
                    $this->assertStringContainsString('VICTIME', $messages[0]['content']);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn($this->validLlmResponse());

        $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(3)); // 3 msgs includes out
    }

    // === Long conversation summarization ===

    public function test_10_messages_no_summary(): void
    {
        // Kills: MAX_MESSAGES_WITHOUT_SUMMARY boundary (10 -> 9 or 11)
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function ($messages) {
                    $this->assertStringNotContainsString('RÉSUMÉ', $messages[0]['content']);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn($this->validLlmResponse());

        $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(10));
    }

    public function test_11_messages_triggers_summary(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function ($messages) {
                    $this->assertStringContainsString('RÉSUMÉ', $messages[0]['content']);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn($this->validLlmResponse());

        $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(11));
    }

    // === IBAN capture detection ===

    public function test_iban_in_recent_scammer_message_detected(): void
    {
        $context = [
            'conversation_id' => 'test-iban',
            'scam_type' => 'PHISHING',
            'persona_code' => 'generic_user',
            'all_messages' => [
                ['direction' => 'in', 'body_text' => 'Hello friend', 'ts_msg' => '2026-01-01T00:00:00+00:00'],
                ['direction' => 'out', 'body_text' => 'I need payment info', 'ts_msg' => '2026-01-01T01:00:00+00:00'],
                ['direction' => 'in', 'body_text' => 'My IBAN is FR7612345678901234567890123', 'ts_msg' => '2026-01-01T02:00:00+00:00'],
            ],
            'extracted_iocs' => [
                ['type' => 'iban', 'value' => 'FR7612345678901234567890123'],
            ],
        ];

        $this->llmClient->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function ($messages) {
                    $this->assertStringContainsString('IBAN_CAPTURED', $messages[0]['content']);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn($this->validLlmResponse());

        $this->analyzer->analyzeAndGenerateInstructions($context);
    }

    public function test_no_iban_ioc_returns_no_recent_iban(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function ($messages) {
                    $this->assertStringContainsString('NO_RECENT_IBAN', $messages[0]['content']);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn($this->validLlmResponse());

        $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(2));
    }

    public function test_iban_ioc_exists_but_not_in_recent_messages(): void
    {
        // IBAN in iocs but NOT in recent message text
        $context = [
            'conversation_id' => 'test-old-iban',
            'scam_type' => 'PHISHING',
            'persona_code' => 'generic_user',
            'all_messages' => [
                ['direction' => 'in', 'body_text' => 'Hello there.', 'ts_msg' => '2026-01-01T00:00:00+00:00'],
                ['direction' => 'out', 'body_text' => 'What do you need?', 'ts_msg' => '2026-01-01T01:00:00+00:00'],
                ['direction' => 'in', 'body_text' => 'Just confirming our deal.', 'ts_msg' => '2026-01-01T02:00:00+00:00'],
            ],
            'extracted_iocs' => [
                ['type' => 'iban', 'value' => 'FR7612345678901234567890123'],
            ],
        ];

        $this->llmClient->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function ($messages) {
                    $this->assertStringContainsString('NO_RECENT_IBAN', $messages[0]['content']);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn($this->validLlmResponse());

        $this->analyzer->analyzeAndGenerateInstructions($context);
    }

    // === LLM options ===

    public function test_llm_model_is_gpt_4o(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->with(
                $this->anything(),
                $this->callback(function ($options) {
                    $this->assertSame('gpt-4o', $options['model']);
                    return true;
                })
            )
            ->willReturn($this->validLlmResponse());

        $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(2));
    }

    public function test_llm_temperature_is_0_3(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->with(
                $this->anything(),
                $this->callback(function ($options) {
                    $this->assertSame(0.3, $options['temperature']);
                    return true;
                })
            )
            ->willReturn($this->validLlmResponse());

        $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(2));
    }

    public function test_llm_max_tokens_is_3000(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->with(
                $this->anything(),
                $this->callback(function ($options) {
                    $this->assertSame(3000, $options['max_tokens']);
                    return true;
                })
            )
            ->willReturn($this->validLlmResponse());

        $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(2));
    }

    public function test_llm_response_format_is_json_object(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->with(
                $this->anything(),
                $this->callback(function ($options) {
                    $this->assertSame(['type' => 'json_object'], $options['response_format']);
                    return true;
                })
            )
            ->willReturn($this->validLlmResponse());

        $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(2));
    }

    // === JSON parsing edge cases ===

    public function test_parse_response_with_markdown_json_block(): void
    {
        $llmResponse = '```json
{"strategic_analysis": "test", "repetitions_detected": [], "tone_recommendation": "confiant", "strategic_suggestions": [], "instructions": {"interdictions": [], "obligations": ["vary"]}}
```';

        $this->llmClient->method('chat')->willReturn($llmResponse);

        $result = $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(2));
        $this->assertSame('confiant', $result['tone_recommendation']);
    }

    public function test_parse_response_missing_instructions_key_falls_to_generic(): void
    {
        $llmResponse = '{"strategic_analysis": "test", "repetitions_detected": [], "tone_recommendation": "calm"}';
        $this->llmClient->method('chat')->willReturn($llmResponse);

        $result = $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(2));
        // Falls back to generic
        $this->assertSame('inquiet', $result['tone_recommendation']);
    }

    public function test_parse_response_invalid_instructions_type_falls_to_generic(): void
    {
        // instructions is string instead of object
        $llmResponse = '{"strategic_analysis": "test", "repetitions_detected": [], "tone_recommendation": "calm", "instructions": "string not object"}';
        $this->llmClient->method('chat')->willReturn($llmResponse);

        $result = $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(2));
        $this->assertSame('inquiet', $result['tone_recommendation']);
    }

    public function test_parse_response_missing_obligations_falls_to_generic(): void
    {
        $llmResponse = '{"strategic_analysis": "test", "repetitions_detected": [], "tone_recommendation": "calm", "instructions": {"interdictions": []}}';
        $this->llmClient->method('chat')->willReturn($llmResponse);

        $result = $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(2));
        $this->assertSame('inquiet', $result['tone_recommendation']);
    }

    // === Prompt content verification ===

    public function test_prompt_contains_scam_type(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function ($messages) {
                    $this->assertStringContainsString('PHISHING', $messages[0]['content']);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn($this->validLlmResponse());

        $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(2));
    }

    public function test_prompt_contains_message_count(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function ($messages) {
                    $this->assertStringContainsString('3', $messages[0]['content']);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn($this->validLlmResponse());

        $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(3));
    }

    public function test_prompt_contains_persona_code(): void
    {
        $this->llmClient->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function ($messages) {
                    $this->assertStringContainsString('generic_user', $messages[0]['content']);
                    return true;
                }),
                $this->anything()
            )
            ->willReturn($this->validLlmResponse());

        $this->analyzer->analyzeAndGenerateInstructions($this->contextWith(2));
    }
}

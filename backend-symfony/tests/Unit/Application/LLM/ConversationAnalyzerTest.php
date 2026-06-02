<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\ConversationAnalyzer;
use App\Application\LLM\Port\LLMClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ConversationAnalyzerTest extends TestCase
{
    private LLMClientInterface $llmClient;
    private LoggerInterface $logger;
    private ConversationAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->analyzer = new ConversationAnalyzer(
            $this->llmClient,
            $this->logger
        );
    }

    public function testItReturnsGenericInstructionsWhenNotEnoughMessages(): void
    {
        $context = [
            'conversation_id' => 'test-conv-1',
            'scam_type' => 'phishing',
            'persona_code' => 'generic_user',
            'all_messages' => [
                [
                    'direction' => 'in',
                    'body_text' => 'Hello',
                    'ts_msg' => '2025-10-27T10:00:00+00:00',
                ],
            ],
            'extracted_iocs' => [],
        ];

        $result = $this->analyzer->analyzeAndGenerateInstructions($context);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('analysis', $result);
        $this->assertArrayHasKey('repetitions_detected', $result);
        $this->assertArrayHasKey('strategic_suggestions', $result);
        $this->assertArrayHasKey('tone_recommendation', $result);
        $this->assertArrayHasKey('instructions_for_llm', $result);

        $this->assertSame('worried', $result['tone_recommendation']);
        $this->assertEmpty($result['repetitions_detected']);
        // instructions_for_llm is now a structured array, not a string
        $this->assertIsArray($result['instructions_for_llm']);
        $this->assertArrayHasKey('interdictions', $result['instructions_for_llm']);
        $this->assertArrayHasKey('obligations', $result['instructions_for_llm']);
    }

    public function testItAnalyzesConversationWithMultipleMessages(): void
    {
        $context = [
            'conversation_id' => 'test-conv-2',
            'scam_type' => 'phishing',
            'persona_code' => 'generic_user',
            'all_messages' => [
                [
                    'direction' => 'in',
                    'body_text' => 'Hello, I am from your bank',
                    'ts_msg' => '2025-10-27T10:00:00+00:00',
                    'subject' => 'Urgent action required',
                ],
                [
                    'direction' => 'out',
                    'body_text' => 'Bonjour, je suis inquiet',
                    'ts_msg' => '2025-10-27T10:01:00+00:00',
                ],
                [
                    'direction' => 'in',
                    'body_text' => 'Please send your bank details',
                    'ts_msg' => '2025-10-27T10:02:00+00:00',
                ],
            ],
            'extracted_iocs' => [
                ['type' => 'email', 'value' => 'scammer@evil.com', 'category' => 'contact'],
            ],
        ];

        $llmResponse = json_encode([
            'repetitions_detected' => ['Répète "je suis inquiet" trop souvent'],
            'strategic_analysis' => 'Le scammer est engagé, conversation avance bien',
            'missing_iocs' => ['IBAN', 'Numéro de téléphone'],
            'tone_recommendation' => 'suspicious',
            'strategic_suggestions' => ['Demander plus de preuves'],
            'instructions' => [
                'interdictions' => ["INTERDIT d'utiliser 'je suis inquiet' (déjà utilisé × 2)"],
                'obligations' => ["Varie ton expression d'inquiétude", "Utilise des formulations différentes"],
                'objectif_strategique' => "Obtenir l'IBAN du scammer",
                'style_ton' => "Méfiant mais coopératif, 80-100 mots",
            ],
        ], JSON_THROW_ON_ERROR);

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function ($messages) {
                    $this->assertIsArray($messages);
                    $this->assertCount(1, $messages);
                    $this->assertSame('user', $messages[0]['role']);
                    $this->assertStringContainsString('Scam type: phishing', $messages[0]['content']);
                    $this->assertStringContainsString('Number of messages exchanged: 3', $messages[0]['content']);
                    $this->assertStringContainsString('email (1)', $messages[0]['content']);

                    return true;
                }),
                $this->callback(function ($options) {
                    $this->assertArrayHasKey('model', $options);
                    $this->assertArrayHasKey('temperature', $options);
                    $this->assertArrayHasKey('max_tokens', $options);
                    $this->assertArrayHasKey('response_format', $options);
                    $this->assertSame('gpt-4o', $options['model']); // Upgraded from gpt-4o-mini
                    $this->assertSame(0.3, $options['temperature']);
                    $this->assertSame(3000, $options['max_tokens']); // Increased from 2500
                    $this->assertSame(['type' => 'json_object'], $options['response_format']);

                    return true;
                })
            )
            ->willReturn($llmResponse);

        $result = $this->analyzer->analyzeAndGenerateInstructions($context);

        $this->assertIsArray($result);
        $this->assertSame('Le scammer est engagé, conversation avance bien', $result['analysis']);
        $this->assertCount(1, $result['repetitions_detected']);
        $this->assertSame('Répète "je suis inquiet" trop souvent', $result['repetitions_detected'][0]);
        $this->assertSame('suspicious', $result['tone_recommendation']);
        // instructions_for_llm is now a structured array
        $this->assertIsArray($result['instructions_for_llm']);
        $this->assertArrayHasKey('interdictions', $result['instructions_for_llm']);
        $this->assertArrayHasKey('obligations', $result['instructions_for_llm']);
        $this->assertStringContainsString('je suis inquiet', $result['instructions_for_llm']['interdictions'][0]);
    }

    public function testItCachesAnalysisResults(): void
    {
        $context = [
            'conversation_id' => 'test-conv-3',
            'scam_type' => 'phishing',
            'persona_code' => 'generic_user',
            'all_messages' => [
                [
                    'direction' => 'in',
                    'body_text' => 'Message 1',
                    'ts_msg' => '2025-10-27T10:00:00+00:00',
                ],
                [
                    'direction' => 'out',
                    'body_text' => 'Message 2',
                    'ts_msg' => '2025-10-27T10:01:00+00:00',
                ],
            ],
            'extracted_iocs' => [],
        ];

        $llmResponse = json_encode([
            'repetitions_detected' => [],
            'strategic_analysis' => 'Test analysis',
            'missing_iocs' => [],
            'tone_recommendation' => 'worried',
            'strategic_suggestions' => [],
            'instructions' => [
                'interdictions' => [],
                'obligations' => ["Varie ton style"],
                'objectif_strategique' => "Obtenir des IOCs",
                'style_ton' => "Naturel",
            ],
        ], JSON_THROW_ON_ERROR);

        // LLM should only be called ONCE
        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($llmResponse);

        // First call
        $result1 = $this->analyzer->analyzeAndGenerateInstructions($context);

        // Second call with same context (should use cache)
        $result2 = $this->analyzer->analyzeAndGenerateInstructions($context);

        $this->assertSame($result1, $result2);
        $this->assertSame('Test analysis', $result1['analysis']);
    }

    public function testItInvalidatesCacheWhenMessageCountChanges(): void
    {
        $context = [
            'conversation_id' => 'test-conv-4',
            'scam_type' => 'phishing',
            'persona_code' => 'generic_user',
            'all_messages' => [
                [
                    'direction' => 'in',
                    'body_text' => 'Message 1',
                    'ts_msg' => '2025-10-27T10:00:00+00:00',
                ],
                [
                    'direction' => 'out',
                    'body_text' => 'Message 2',
                    'ts_msg' => '2025-10-27T10:01:00+00:00',
                ],
            ],
            'extracted_iocs' => [],
        ];

        $llmResponse1 = json_encode([
            'repetitions_detected' => [],
            'strategic_analysis' => 'Analysis with 2 messages',
            'missing_iocs' => [],
            'tone_recommendation' => 'worried',
            'strategic_suggestions' => [],
            'instructions' => [
                'interdictions' => [],
                'obligations' => ["Varie ton style"],
                'objectif_strategique' => "Obtenir des IOCs",
                'style_ton' => "Naturel",
            ],
        ], JSON_THROW_ON_ERROR);

        $llmResponse2 = json_encode([
            'repetitions_detected' => ['New repetition detected'],
            'strategic_analysis' => 'Analysis with 3 messages',
            'missing_iocs' => [],
            'tone_recommendation' => 'suspicious',
            'strategic_suggestions' => [],
            'instructions' => [
                'interdictions' => ["INTERDIT de répéter"],
                'obligations' => ["Varie davantage"],
                'objectif_strategique' => "Obtenir IBAN",
                'style_ton' => "Méfiant",
            ],
        ], JSON_THROW_ON_ERROR);

        // LLM should be called TWICE (different message count)
        $this->llmClient
            ->expects($this->exactly(2))
            ->method('chat')
            ->willReturnOnConsecutiveCalls($llmResponse1, $llmResponse2);

        // First call with 2 messages
        $result1 = $this->analyzer->analyzeAndGenerateInstructions($context);
        $this->assertSame('Analysis with 2 messages', $result1['analysis']);

        // Second call with 3 messages (cache should be invalidated)
        $context['all_messages'][] = [
            'direction' => 'in',
            'body_text' => 'Message 3',
            'ts_msg' => '2025-10-27T10:02:00+00:00',
        ];

        $result2 = $this->analyzer->analyzeAndGenerateInstructions($context);
        $this->assertSame('Analysis with 3 messages', $result2['analysis']);
    }

    public function testItHandlesLlmException(): void
    {
        $context = [
            'conversation_id' => 'test-conv-5',
            'scam_type' => 'phishing',
            'persona_code' => 'generic_user',
            'all_messages' => [
                [
                    'direction' => 'in',
                    'body_text' => 'Message 1',
                    'ts_msg' => '2025-10-27T10:00:00+00:00',
                ],
                [
                    'direction' => 'out',
                    'body_text' => 'Message 2',
                    'ts_msg' => '2025-10-27T10:01:00+00:00',
                ],
            ],
            'extracted_iocs' => [],
        ];

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willThrowException(new \RuntimeException('LLM API error'));

        $result = $this->analyzer->analyzeAndGenerateInstructions($context);

        // Should return generic instructions as fallback
        $this->assertIsArray($result);
        $this->assertStringContainsString('Not enough messages', $result['analysis']);
        $this->assertSame('worried', $result['tone_recommendation']);
        // instructions_for_llm is now a structured array
        $this->assertIsArray($result['instructions_for_llm']);
        $this->assertArrayHasKey('interdictions', $result['instructions_for_llm']);
        $this->assertArrayHasKey('obligations', $result['instructions_for_llm']);
    }

    public function testItHandlesInvalidJsonResponse(): void
    {
        $context = [
            'conversation_id' => 'test-conv-6',
            'scam_type' => 'phishing',
            'persona_code' => 'generic_user',
            'all_messages' => [
                [
                    'direction' => 'in',
                    'body_text' => 'Message 1',
                    'ts_msg' => '2025-10-27T10:00:00+00:00',
                ],
                [
                    'direction' => 'out',
                    'body_text' => 'Message 2',
                    'ts_msg' => '2025-10-27T10:01:00+00:00',
                ],
            ],
            'extracted_iocs' => [],
        ];

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn('Invalid JSON response {]');

        $result = $this->analyzer->analyzeAndGenerateInstructions($context);

        // Should return generic instructions as fallback
        $this->assertIsArray($result);
        $this->assertStringContainsString('Not enough messages', $result['analysis']);
        $this->assertSame('worried', $result['tone_recommendation']);
    }

    public function testItHandlesMissingRequiredFields(): void
    {
        $context = [
            'conversation_id' => 'test-conv-7',
            'scam_type' => 'phishing',
            'persona_code' => 'generic_user',
            'all_messages' => [
                [
                    'direction' => 'in',
                    'body_text' => 'Message 1',
                    'ts_msg' => '2025-10-27T10:00:00+00:00',
                ],
                [
                    'direction' => 'out',
                    'body_text' => 'Message 2',
                    'ts_msg' => '2025-10-27T10:01:00+00:00',
                ],
            ],
            'extracted_iocs' => [],
        ];

        // Missing required field: tone_recommendation
        $llmResponse = json_encode([
            'repetitions_detected' => [],
            'instructions_for_llm' => 'Test',
        ], JSON_THROW_ON_ERROR);

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($llmResponse);

        $result = $this->analyzer->analyzeAndGenerateInstructions($context);

        // Should return generic instructions as fallback
        $this->assertIsArray($result);
        $this->assertStringContainsString('Not enough messages', $result['analysis']);
    }

    public function testItFormatsIocsSummaryCorrectly(): void
    {
        $context = [
            'conversation_id' => 'test-conv-8',
            'scam_type' => 'phishing',
            'persona_code' => 'generic_user',
            'all_messages' => [
                [
                    'direction' => 'in',
                    'body_text' => 'Contact me at scammer@evil.com or call +33123456789',
                    'ts_msg' => '2025-10-27T10:00:00+00:00',
                ],
                [
                    'direction' => 'out',
                    'body_text' => 'Je vous remercie',
                    'ts_msg' => '2025-10-27T10:01:00+00:00',
                ],
            ],
            'extracted_iocs' => [
                ['type' => 'email', 'value' => 'scammer@evil.com'],
                ['type' => 'phone', 'value' => '+33123456789'],
                ['type' => 'email', 'value' => 'scammer2@evil.com'],
            ],
        ];

        $llmResponse = json_encode([
            'repetitions_detected' => [],
            'strategic_analysis' => 'Test',
            'missing_iocs' => [],
            'tone_recommendation' => 'worried',
            'strategic_suggestions' => [],
            'instructions_for_llm' => 'Test',
        ], JSON_THROW_ON_ERROR);

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function ($messages) {
                    $prompt = $messages[0]['content'];
                    // Should contain "email (2), phone (1)"
                    $this->assertStringContainsString('email (2)', $prompt);
                    $this->assertStringContainsString('phone (1)', $prompt);

                    return true;
                }),
                $this->anything()
            )
            ->willReturn($llmResponse);

        $this->analyzer->analyzeAndGenerateInstructions($context);
    }

    public function testItFormatsConversationHistoryWithSubjects(): void
    {
        $context = [
            'conversation_id' => 'test-conv-9',
            'scam_type' => 'phishing',
            'persona_code' => 'generic_user',
            'all_messages' => [
                [
                    'direction' => 'in',
                    'body_text' => 'Hello',
                    'ts_msg' => '2025-10-27T10:00:00+00:00',
                    'subject' => 'Urgent action required',
                ],
                [
                    'direction' => 'out',
                    'body_text' => 'Bonjour',
                    'ts_msg' => '2025-10-27T10:01:00+00:00',
                    'subject' => 'Re: Urgent action required',
                ],
            ],
            'extracted_iocs' => [],
        ];

        $llmResponse = json_encode([
            'repetitions_detected' => [],
            'strategic_analysis' => 'Test',
            'missing_iocs' => [],
            'tone_recommendation' => 'worried',
            'strategic_suggestions' => [],
            'instructions_for_llm' => 'Test',
        ], JSON_THROW_ON_ERROR);

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function ($messages) {
                    $prompt = $messages[0]['content'];
                    $this->assertStringContainsString('Message #1 - SCAMMER', $prompt);
                    $this->assertStringContainsString('Subject: Urgent action required', $prompt);
                    $this->assertStringContainsString('Message #2 - VICTIM', $prompt);
                    $this->assertStringContainsString('Subject: Re: Urgent action required', $prompt);

                    return true;
                }),
                $this->anything()
            )
            ->willReturn($llmResponse);

        $this->analyzer->analyzeAndGenerateInstructions($context);
    }

    public function testItSummarizesLongConversations(): void
    {
        $messages = [];

        // Create 15 messages (> MAX_MESSAGES_WITHOUT_SUMMARY = 10)
        for ($i = 1; $i <= 15; ++$i) {
            $messages[] = [
                'direction' => ($i % 2 === 1) ? 'in' : 'out',
                'body_text' => "Message $i",
                'ts_msg' => '2025-10-27T10:00:00+00:00',
            ];
        }

        $context = [
            'conversation_id' => 'test-conv-10',
            'scam_type' => 'phishing',
            'persona_code' => 'generic_user',
            'all_messages' => $messages,
            'extracted_iocs' => [],
        ];

        $llmResponse = json_encode([
            'repetitions_detected' => [],
            'strategic_analysis' => 'Test',
            'missing_iocs' => [],
            'tone_recommendation' => 'worried',
            'strategic_suggestions' => [],
            'instructions_for_llm' => 'Test',
        ], JSON_THROW_ON_ERROR);

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function ($messages) {
                    $prompt = $messages[0]['content'];
                    // Should contain summary marker
                    $this->assertStringContainsString('SUMMARY', $prompt);
                    $this->assertStringContainsString('messages exchanged', $prompt);

                    return true;
                }),
                $this->anything()
            )
            ->willReturn($llmResponse);

        $this->analyzer->analyzeAndGenerateInstructions($context);
    }

    // ================================================================== //
    //  Merged from ConversationAnalyzerCoverageTest
    // ================================================================== //

    private function buildContext(): array
    {
        return [
            'conversation_id' => 'test-conv',
            'scam_type' => 'PHISHING',
            'persona_code' => 'generic_user',
            'all_messages' => [
                ['direction' => 'in', 'body_text' => 'Hello 1', 'ts_msg' => '2026-01-01T00:00:00+00:00'],
                ['direction' => 'out', 'body_text' => 'Reply 1', 'ts_msg' => '2026-01-01T01:00:00+00:00'],
                ['direction' => 'in', 'body_text' => 'Hello 2', 'ts_msg' => '2026-01-01T02:00:00+00:00'],
                ['direction' => 'out', 'body_text' => 'Reply 2', 'ts_msg' => '2026-01-01T03:00:00+00:00'],
                ['direction' => 'in', 'body_text' => 'Hello 3', 'ts_msg' => '2026-01-01T04:00:00+00:00'],
            ],
        ];
    }

    public function testAnalyzeHandlesJsonWithMultiplicationSymbol(): void
    {
        $llmResponse = '```json
{
    "strategic_analysis": "test",
    "repetitions_detected": ["Bonjour," × 3, "Suite..." × 2],
    "tone_recommendation": "more assertive",
    "strategic_suggestions": [],
    "instructions": {
        "interdictions": ["Do not repeat"],
        "obligations": ["Use new phrases"]
    }
}
```';

        $this->llmClient->method('chat')->willReturn($llmResponse);

        $result = $this->analyzer->analyzeAndGenerateInstructions($this->buildContext());

        $this->assertArrayHasKey('instructions_for_llm', $result);
        $this->assertSame('more assertive', $result['tone_recommendation']);
    }

    public function testAnalyzeHandlesJsonWithTrailingComma(): void
    {
        $llmResponse = '{
    "strategic_analysis": "test analysis",
    "repetitions_detected": ["word1",],
    "tone_recommendation": "assertive",
    "strategic_suggestions": [],
    "instructions": {
        "interdictions": ["stop repeating",],
        "obligations": ["use variety"]
    }
}';

        $this->llmClient->method('chat')->willReturn($llmResponse);

        $result = $this->analyzer->analyzeAndGenerateInstructions($this->buildContext());

        $this->assertArrayHasKey('repetitions_detected', $result);
    }

    public function testAnalyzeHandlesJsonWithoutMarkdownBlock(): void
    {
        // extractJsonFromResponse falls through to raw JSON extraction
        $llmResponse = 'Here is the analysis: {"strategic_analysis": "test", "repetitions_detected": [], "tone_recommendation": "calm", "strategic_suggestions": [], "instructions": {"interdictions": [], "obligations": []}} end of response';

        $this->llmClient->method('chat')->willReturn($llmResponse);

        $result = $this->analyzer->analyzeAndGenerateInstructions($this->buildContext());

        $this->assertSame('calm', $result['tone_recommendation']);
    }

    public function testAnalyzeFallsBackToGenericOnInvalidJson(): void
    {
        $this->llmClient->method('chat')->willReturn('Not valid JSON at all');

        $result = $this->analyzer->analyzeAndGenerateInstructions($this->buildContext());

        // Should return generic instructions (fallback), not throw
        $this->assertArrayHasKey('instructions_for_llm', $result);
        $this->assertArrayHasKey('tone_recommendation', $result);
        $this->assertNotEmpty($result['instructions_for_llm']);
    }

    public function testAnalyzeFallsBackToGenericOnMissingRequiredFields(): void
    {
        $llmResponse = '{"strategic_analysis": "test"}'; // missing required fields

        $this->llmClient->method('chat')->willReturn($llmResponse);

        $result = $this->analyzer->analyzeAndGenerateInstructions($this->buildContext());

        // Should return generic instructions
        $this->assertArrayHasKey('instructions_for_llm', $result);
    }

    public function testAnalyzeFallsBackToGenericOnInvalidInstructionsStructure(): void
    {
        $llmResponse = '{"strategic_analysis": "test", "repetitions_detected": [], "tone_recommendation": "calm", "instructions": "not an object"}';

        $this->llmClient->method('chat')->willReturn($llmResponse);

        $result = $this->analyzer->analyzeAndGenerateInstructions($this->buildContext());

        // Should return generic instructions
        $this->assertArrayHasKey('instructions_for_llm', $result);
    }

    public function testAnalyzeFallsBackOnLlmException(): void
    {
        $this->llmClient->method('chat')
            ->willThrowException(new \RuntimeException('LLM unavailable'));

        $result = $this->analyzer->analyzeAndGenerateInstructions($this->buildContext());

        // Should return generic instructions (fallback)
        $this->assertArrayHasKey('instructions_for_llm', $result);
        $this->assertArrayHasKey('tone_recommendation', $result);
    }

    public function testAnalyzeWithIocsInContext(): void
    {
        $validResponse = '{"strategic_analysis": "Scammer engaged", "repetitions_detected": [], "tone_recommendation": "warm", "strategic_suggestions": ["Ask about payment"], "instructions": {"interdictions": ["Do not reveal truth"], "obligations": ["Stay engaged"]}}';

        $this->llmClient->method('chat')->willReturn($validResponse);

        $context = $this->buildContext();
        $context['extracted_iocs'] = [
            ['type' => 'iban', 'value' => 'FR7612345', 'category' => 'financial'],
            ['type' => 'email', 'value' => 'scammer@evil.com', 'category' => 'contact'],
        ];

        $result = $this->analyzer->analyzeAndGenerateInstructions($context);

        $this->assertSame('warm', $result['tone_recommendation']);
    }

    /**
     * Spec 095 Fix #12 — ConversationAnalyzer's strategic analysis prompt
     * must now be in English (was 264 lines of French pre-Fix #12).
     * Eliminates LLM code-switching with the 90 % EN corpus.
     *
     * See: specs/095-pipeline-audit/fix-12-translate-remaining-prompts/spec.md
     */
    public function testAnalysisPromptIsInEnglish_Fix12(): void
    {
        $validResponse = '{"strategic_analysis": "OK", "repetitions_detected": [], "tone_recommendation": "confident", "strategic_suggestions": [], "instructions": {"interdictions": [], "obligations": []}}';

        $captured = '';
        $this->llmClient->method('chat')->willReturnCallback(function (array $messages) use (&$captured, $validResponse) {
            $captured = ($messages[0]['content'] ?? '') . "\n---\n" . ($messages[1]['content'] ?? '');

            return $validResponse;
        });

        $context = $this->buildContext();
        $this->analyzer->analyzeAndGenerateInstructions($context);

        // No FR markers
        $this->assertStringNotContainsString('Tu es un analyste', $captured);
        $this->assertStringNotContainsString('CONTEXTE :', $captured);
        $this->assertStringNotContainsString('Voici', $captured);
        $this->assertStringNotContainsString('OBJECTIF DE L', $captured);
        // EN markers present
        $this->assertStringContainsString('You are', $captured);
        $this->assertStringContainsString('CONTEXT', $captured);
        $this->assertStringContainsString('OBJECTIVE', $captured);
    }
}

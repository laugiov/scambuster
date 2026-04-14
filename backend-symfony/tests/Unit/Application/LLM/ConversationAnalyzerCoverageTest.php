<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\ConversationAnalyzer;
use App\Application\LLM\Port\LLMClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Coverage tests for ConversationAnalyzer private methods:
 * - sanitizeJsonResponse (trailing comma, x symbol)
 * - extractJsonFromResponse (JSON without markdown, bare JSON)
 * - parseAnalysisResponse error handling -> fallback to generic
 */
class ConversationAnalyzerCoverageTest extends TestCase
{
    private ConversationAnalyzer $analyzer;
    private LLMClientInterface $llmClient;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);

        $this->analyzer = new ConversationAnalyzer(
            $this->llmClient,
            new NullLogger(),
        );
    }

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
}

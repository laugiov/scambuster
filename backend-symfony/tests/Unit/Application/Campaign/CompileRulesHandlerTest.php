<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Campaign;

use App\Application\Campaign\RuleCompiler;
use App\Application\LLM\Port\LLMClientInterface;
use App\Application\Campaign\PromptBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for RuleCompiler (the core logic behind CompileRulesHandler).
 *
 * CompileRulesHandler itself depends on final CampaignRepository so it
 * cannot be unit-tested with mocks. We test the compiler directly since
 * its LLMClientInterface is mockable.
 */
class CompileRulesHandlerTest extends TestCase
{
    public function testCompileReturnsDslOnFirstAttempt(): void
    {
        $validDsl = 'RULE campaign.test_1 { WHERE subject containsAny ["urgent"] ACTION flag(HIGH) }';

        $llmClient = $this->createMock(LLMClientInterface::class);
        $llmClient->method('chat')->willReturn($validDsl);

        $promptBuilder = new PromptBuilder();

        $compiler = new RuleCompiler($llmClient, $promptBuilder, new NullLogger());
        $result = $compiler->compile('campaign:\n  summary: test');

        $this->assertSame($validDsl, $result['rules_dsl']);
        $this->assertSame(1, $result['rules_count']);
        $this->assertSame(1, $result['attempts']);
    }

    public function testCompileExtractsDslFromMarkdownBlock(): void
    {
        $markdown = "Here are the rules:\n```dsl\nRULE campaign.test_1 { WHERE body fuzzy \"scam\" ACTION flag(MEDIUM) }\n```";

        $llmClient = $this->createMock(LLMClientInterface::class);
        $llmClient->method('chat')->willReturn($markdown);

        $promptBuilder = new PromptBuilder();

        $compiler = new RuleCompiler($llmClient, $promptBuilder, new NullLogger());
        $result = $compiler->compile('campaign:\n  summary: markdown');

        $this->assertSame(1, $result['rules_count']);
        $this->assertStringContainsString('RULE campaign.test_1', $result['rules_dsl']);
    }

    public function testCompileThrowsAfterMaxRetriesOnInvalidDsl(): void
    {
        $llmClient = $this->createMock(LLMClientInterface::class);
        $llmClient->method('chat')->willReturn('This is not valid DSL at all');

        $promptBuilder = new PromptBuilder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DSL compilation failed after 3 attempts');

        $compiler = new RuleCompiler($llmClient, $promptBuilder, new NullLogger());
        $compiler->compile('campaign:\n  summary: failing');
    }

    public function testCompileCountsMultipleRules(): void
    {
        $dsl = <<<'DSL'
RULE campaign.phish_1 { WHERE subject containsAny ["payment"] ACTION flag(HIGH) }
RULE campaign.phish_2 { WHERE body fuzzy "wire transfer" ACTION flag(MEDIUM) }
DSL;

        $llmClient = $this->createMock(LLMClientInterface::class);
        $llmClient->method('chat')->willReturn($dsl);

        $promptBuilder = new PromptBuilder();

        $compiler = new RuleCompiler($llmClient, $promptBuilder, new NullLogger());
        $result = $compiler->compile('campaign:\n  summary: multi');

        $this->assertSame(2, $result['rules_count']);
    }

    public function testGenerateTestsReturnsPositiveAndNegativeCases(): void
    {
        $llmClient = $this->createMock(LLMClientInterface::class);
        $promptBuilder = new PromptBuilder();

        $compiler = new RuleCompiler($llmClient, $promptBuilder, new NullLogger());

        $examples = [
            'pos' => [
                ['subject' => 'Urgent payment', 'body' => 'Wire transfer now'],
            ],
            'neg' => [
                ['subject' => 'Newsletter', 'body' => 'Weekly update'],
            ],
        ];

        $result = $compiler->generateTests('RULE test { ... }', $examples);

        $this->assertArrayHasKey('test_cases', $result);
        $this->assertCount(2, $result['test_cases']);
        $this->assertTrue($result['test_cases'][0]['expected']);
        $this->assertFalse($result['test_cases'][1]['expected']);
    }

    public function testGenerateTestsWithEmptyExamples(): void
    {
        $llmClient = $this->createMock(LLMClientInterface::class);
        $promptBuilder = new PromptBuilder();

        $compiler = new RuleCompiler($llmClient, $promptBuilder, new NullLogger());

        $result = $compiler->generateTests('RULE test { ... }', ['pos' => [], 'neg' => []]);

        $this->assertArrayHasKey('test_cases', $result);
        $this->assertEmpty($result['test_cases']);
    }
}

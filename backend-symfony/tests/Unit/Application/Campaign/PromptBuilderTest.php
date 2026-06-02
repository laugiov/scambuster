<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Campaign;

use App\Application\Campaign\PromptBuilder;
use App\Domain\Communication\Message;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PromptBuilder
 */
final class PromptBuilderTest extends TestCase
{
    private PromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PromptBuilder();
    }

    public function testBuildCampaignProfilerPromptsReturnsSystemAndUser(): void
    {
        $messages = [$this->createMockMessage('Subject1', 'Body1')];

        $prompts = $this->builder->buildCampaignProfilerPrompts($messages);

        $this->assertArrayHasKey('system', $prompts);
        $this->assertArrayHasKey('user', $prompts);
        $this->assertIsString($prompts['system']);
        $this->assertIsString($prompts['user']);
    }

    public function testBuildCampaignProfilerPromptsSystemContainsYAMLStructure(): void
    {
        $messages = [$this->createMockMessage('Test', 'Test')];

        $prompts = $this->builder->buildCampaignProfilerPrompts($messages);

        $this->assertStringContainsString('campaign:', $prompts['system']);
        $this->assertStringContainsString('variants:', $prompts['system']);
        $this->assertStringContainsString('infra:', $prompts['system']);
        $this->assertStringContainsString('summary', $prompts['system']);
        $this->assertStringContainsString('tactics', $prompts['system']);
    }

    public function testBuildCampaignProfilerPromptsMasksEmailAddresses(): void
    {
        $message = $this->createMockMessage(
            'Account notification',
            'Contact us at support@example.com for help',
            ['from' => 'sender@test.com']
        );

        $prompts = $this->builder->buildCampaignProfilerPrompts([$message]);

        $this->assertStringContainsString('su***@example.com', $prompts['user']);
        $this->assertStringContainsString('se***@test.com', $prompts['user']);
        $this->assertStringNotContainsString('support@example.com', $prompts['user']);
        $this->assertStringNotContainsString('sender@test.com', $prompts['user']);
    }

    public function testBuildCampaignProfilerPromptsDefangsUrls(): void
    {
        $message = $this->createMockMessage(
            'Test',
            'Visit http://malicious.com and https://phishing.net for details'
        );

        $prompts = $this->builder->buildCampaignProfilerPrompts([$message]);

        $this->assertStringContainsString('hxxp://malicious.com', $prompts['user']);
        $this->assertStringContainsString('hxxps://phishing.net', $prompts['user']);
        $this->assertStringNotContainsString('http://malicious.com', $prompts['user']);
        $this->assertStringNotContainsString('https://phishing.net', $prompts['user']);
    }

    public function testBuildCampaignProfilerPromptsIncludesDkimStatus(): void
    {
        $message = $this->createMockMessage(
            'Test',
            'Body',
            ['auth' => ['dkim' => false, 'spf' => true]]
        );

        $prompts = $this->builder->buildCampaignProfilerPrompts([$message]);

        $this->assertStringContainsString('DKIM: fail', $prompts['user']);
    }

    public function testBuildCampaignProfilerPromptsIncludesMessageCount(): void
    {
        $messages = [
            $this->createMockMessage('S1', 'B1'),
            $this->createMockMessage('S2', 'B2'),
            $this->createMockMessage('S3', 'B3'),
        ];

        $prompts = $this->builder->buildCampaignProfilerPrompts($messages);

        $this->assertStringContainsString('3', $prompts['user']);
    }

    public function testBuildRuleCompilerPromptsReturnsSystemAndUser(): void
    {
        $profileYaml = "campaign:\n  summary: Test";
        $examples = ['pos' => [], 'neg' => []];

        $prompts = $this->builder->buildRuleCompilerPrompts($profileYaml, $examples);

        $this->assertArrayHasKey('system', $prompts);
        $this->assertArrayHasKey('user', $prompts);
        $this->assertIsString($prompts['system']);
        $this->assertIsString($prompts['user']);
    }

    public function testBuildRuleCompilerPromptsSystemContainsDSLSyntax(): void
    {
        $profileYaml = "campaign:\n  summary: Test";
        $examples = ['pos' => [], 'neg' => []];

        $prompts = $this->builder->buildRuleCompilerPrompts($profileYaml, $examples);

        $this->assertStringContainsString('RULE', $prompts['system']);
        $this->assertStringContainsString('WHERE', $prompts['system']);
        $this->assertStringContainsString('ACTION', $prompts['system']);
        $this->assertStringContainsString('simhash≈', $prompts['system']);
        $this->assertStringContainsString('containsAny', $prompts['system']);
    }

    public function testBuildRuleCompilerPromptsUserContainsProfileYaml(): void
    {
        $profileYaml = "campaign:\n  summary: Bank phishing\n  risk: 5";
        $examples = ['pos' => [], 'neg' => []];

        $prompts = $this->builder->buildRuleCompilerPrompts($profileYaml, $examples);

        $this->assertStringContainsString('Bank phishing', $prompts['user']);
        $this->assertStringContainsString('risk: 5', $prompts['user']);
    }

    public function testBuildRuleCompilerPromptsIncludesPositiveExamples(): void
    {
        $profileYaml = "campaign:\n  summary: Test";
        $examples = [
            'pos' => [
                ['subject' => 'Test positive', 'body' => 'Example', 'dkim' => 'fail'],
            ],
            'neg' => [],
        ];

        $prompts = $this->builder->buildRuleCompilerPrompts($profileYaml, $examples);

        $this->assertStringContainsString('Test positive', $prompts['user']);
        $this->assertStringContainsString('DKIM: fail', $prompts['user']);
    }

    public function testBuildRuleCompilerPromptsIncludesNegativeExamples(): void
    {
        $profileYaml = "campaign:\n  summary: Test";
        $examples = [
            'pos' => [],
            'neg' => [
                ['subject' => 'Legitimate email', 'body' => 'Real', 'dkim' => 'pass'],
            ],
        ];

        $prompts = $this->builder->buildRuleCompilerPrompts($profileYaml, $examples);

        $this->assertStringContainsString('Legitimate email', $prompts['user']);
        $this->assertStringContainsString('DKIM: pass', $prompts['user']);
    }

    public function testTruncateTextLimitsLength(): void
    {
        $longText = str_repeat('A', 1000);
        $message = $this->createMockMessage('Subject', $longText);

        $prompts = $this->builder->buildCampaignProfilerPrompts([$message]);

        // Body should be truncated (default 500 chars)
        $this->assertLessThan(1000, strlen($prompts['user']));
    }

    /**
     * Spec 095 Fix #12 — Campaign profiler prompt must now be in English
     * (was French pre-Fix #12). Eliminates LLM code-switching.
     *
     * See: specs/095-pipeline-audit/fix-12-translate-remaining-prompts/spec.md
     */
    public function testCampaignProfilerPromptIsInEnglish_Fix12(): void
    {
        $messages = [$this->createMockMessage('Test', 'Test body')];
        $prompts = $this->builder->buildCampaignProfilerPrompts($messages);

        $combined = $prompts['system'] . "\n" . $prompts['user'];

        // No FR markers
        $this->assertStringNotContainsString('Tu es un analyste', $combined);
        $this->assertStringNotContainsString('À partir d', $combined);
        $this->assertStringNotContainsString('Décrire', $combined);
        $this->assertStringNotContainsString('Voici un échantillon', $combined);
        // EN markers present
        $this->assertStringContainsString('You are', $combined);
        $this->assertStringContainsString('campaign', $combined);
    }

    /**
     * Spec 095 Fix #12 — Rule compiler prompt must now be in English
     * (was French pre-Fix #12).
     *
     * See: specs/095-pipeline-audit/fix-12-translate-remaining-prompts/spec.md
     */
    public function testRuleCompilerPromptIsInEnglish_Fix12(): void
    {
        $prompts = $this->builder->buildRuleCompilerPrompts('campaign:\n  summary: test', ['pos' => [], 'neg' => []]);

        $combined = $prompts['system'] . "\n" . $prompts['user'];

        // No FR markers
        $this->assertStringNotContainsString('Tu es un expert', $combined);
        $this->assertStringNotContainsString('À partir d', $combined);
        $this->assertStringNotContainsString('Aucune PII', $combined);
        $this->assertStringNotContainsString('Profil YAML', $combined);
        // EN markers present
        $this->assertStringContainsString('You are', $combined);
        $this->assertStringContainsString('MailGuard DSL', $combined);
    }

    private function createMockMessage(
        string $subject,
        string $body,
        ?array $headers = null
    ): Message {
        $message = $this->createMock(Message::class);
        $message->method('getSubject')->willReturn($subject);
        $message->method('getBodyText')->willReturn($body);
        $message->method('getHeaders')->willReturn($headers ?? []);

        return $message;
    }
}

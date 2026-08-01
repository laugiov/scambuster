<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Campaign;

use App\Application\Campaign\RuleCompiler;
use App\Application\Campaign\PromptBuilder;
use App\Application\LLM\Port\LLMClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RuleCompiler with mocked LLM
 */
final class RuleCompilerTest extends TestCase
{
    private RuleCompiler $compiler;
    private LLMClientInterface $llmClient;
    private PromptBuilder $promptBuilder;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->promptBuilder = new PromptBuilder();
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->compiler = new RuleCompiler(
            $this->llmClient,
            $this->promptBuilder,
            $this->logger
        );
    }

    public function testCompileSuccessReturnsRulesDsl(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $examples = ['pos' => [], 'neg' => []];
        $validDsl = $this->getValidDSL();

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($validDsl);

        $result = $this->compiler->compile($profileYaml, $examples);

        $this->assertArrayHasKey('rules_dsl', $result);
        $this->assertArrayHasKey('rules_count', $result);
        $this->assertArrayHasKey('attempts', $result);
        $this->assertStringContainsString('RULE', $result['rules_dsl']);
    }

    public function testCompileRetryLogicSucceedsOnSecondAttempt(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $examples = ['pos' => [], 'neg' => []];
        $validDsl = $this->getValidDSL();

        // First call fails, second succeeds
        $this->llmClient
            ->expects($this->exactly(2))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('Timeout')),
                $validDsl
            );

        $result = $this->compiler->compile($profileYaml, $examples);

        $this->assertEquals(2, $result['attempts']);
        $this->assertStringContainsString('RULE', $result['rules_dsl']);
    }

    public function testCompileThrowsAfterMaxRetries(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $examples = ['pos' => [], 'neg' => []];

        $this->llmClient
            ->expects($this->exactly(3))
            ->method('chat')
            ->willThrowException(new \RuntimeException('Persistent error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed after 3 attempts');

        $this->compiler->compile($profileYaml, $examples);
    }

    public function testValidateDSLAcceptsValidSyntax(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $examples = ['pos' => [], 'neg' => []];
        $validDsl = $this->getValidDSL();

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($validDsl);

        $result = $this->compiler->compile($profileYaml, $examples);

        $this->assertGreaterThan(0, $result['rules_count']);
    }

    public function testValidateDSLThrowsOnMissingWhereClause(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $examples = ['pos' => [], 'neg' => []];

        $invalidDsl = "RULE test.rule {\n  ACTION block\n}";

        $this->llmClient
            ->expects($this->atLeastOnce())
            ->method('chat')
            ->willReturn($invalidDsl);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed after 3 attempts');

        $this->compiler->compile($profileYaml, $examples);
    }

    public function testValidateDSLThrowsOnMissingActionClause(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $examples = ['pos' => [], 'neg' => []];

        $invalidDsl = "RULE test.rule {\n  WHERE subject containsAny [\"test\"]\n}";

        $this->llmClient
            ->expects($this->atLeastOnce())
            ->method('chat')
            ->willReturn($invalidDsl);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed after 3 attempts');

        $this->compiler->compile($profileYaml, $examples);
    }

    public function testValidateDSLThrowsOnNoValidFields(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $examples = ['pos' => [], 'neg' => []];

        $invalidDsl = "RULE test.rule {\n  WHERE invalid_field = \"test\"\n  ACTION block\n}";

        $this->llmClient
            ->expects($this->atLeastOnce())
            ->method('chat')
            ->willReturn($invalidDsl);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed after 3 attempts');

        $this->compiler->compile($profileYaml, $examples);
    }

    public function testValidateDSLThrowsOnNoValidOperators(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $examples = ['pos' => [], 'neg' => []];

        $invalidDsl = "RULE test.rule {\n  WHERE subject is \"test\"\n  ACTION block\n}";

        $this->llmClient
            ->expects($this->atLeastOnce())
            ->method('chat')
            ->willReturn($invalidDsl);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed after 3 attempts');

        $this->compiler->compile($profileYaml, $examples);
    }

    public function testCountRulesReturnsCorrectCount(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $examples = ['pos' => [], 'neg' => []];

        $dslWithThreeRules = <<<DSL
RULE scam.bank_001 {
  WHERE subject simhash≈"compte bloqué" ±15%
  ACTION block
}

RULE scam.bank_002 {
  WHERE body containsAny ["vérifier", "urgent"]
  ACTION quarantine
}

RULE scam.bank_003 {
  WHERE dkim.pass = false
  ACTION flag
}
DSL;

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($dslWithThreeRules);

        $result = $this->compiler->compile($profileYaml, $examples);

        $this->assertEquals(3, $result['rules_count']);
    }

    public function testExtractDSLFromMarkdownCodeBlock(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $examples = ['pos' => [], 'neg' => []];
        $validDsl = $this->getValidDSL();
        $markdownResponse = "```dsl\n{$validDsl}\n```";

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($markdownResponse);

        $result = $this->compiler->compile($profileYaml, $examples);

        $this->assertStringContainsString('RULE', $result['rules_dsl']);
        $this->assertStringNotContainsString('```', $result['rules_dsl']);
    }

    public function testGenerateTestsReturnsPositiveAndNegativeCases(): void
    {
        $rulesDsl = $this->getValidDSL();
        $examples = [
            'pos' => [
                ['subject' => 'Urgent', 'body' => 'Verify', 'dkim' => 'fail'],
            ],
            'neg' => [
                ['subject' => 'Newsletter', 'body' => 'News', 'dkim' => 'pass'],
            ],
        ];

        $result = $this->compiler->generateTests($rulesDsl, $examples);

        $this->assertArrayHasKey('test_cases', $result);
        $this->assertCount(2, $result['test_cases']); // 1 pos + 1 neg
        $this->assertTrue($result['test_cases'][0]['expected']); // Positive
        $this->assertFalse($result['test_cases'][1]['expected']); // Negative
    }

    private function getValidProfileYaml(): string
    {
        return <<<YAML
campaign:
  summary: "Bank phishing campaign"
  tactics: ["urgency", "impersonation"]
  target_audience: "bank customers"
  cta: "click verification link"
  risk: 5
variants:
  subjects: ["Account suspended", "Verify your identity"]
  display_names: ["Bank Security", "Account Team"]
  url_shapes: ["bank-lookalike.com/verify"]
infra:
  domain_age_pattern: "<7d"
  dkim_spf_pattern: "fail|absent"
  mx_provider_pattern: "low-cost"
YAML;
    }

    private function getValidDSL(): string
    {
        return <<<DSL
RULE scam.bank_phishing_2025_10 {
  WHERE subject simhash≈"compte bloqué" ±15%
    AND dkim.pass = false
  ACTION block
}
DSL;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Campaign;

use App\Application\Campaign\PromptBuilder;
use App\Application\Campaign\RuleCompiler;
use App\Application\LLM\Port\LLMClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Reinforced unit tests for RuleCompiler
 *
 * Covers:
 * - Complete DSL validation (syntax, fields, operators)
 * - Retry logic (timeout, errors, backoff)
 * - DSL extraction (markdown, multiple formats)
 * - Rule counting (1-3 rules)
 * - Test generation (positive/negative examples)
 * - Edge cases (complex DSL, invalid syntax)
 * - Realistic scenarios (PayPal, banks)
 */
final class RuleCompilerEnhancedTest extends TestCase
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

    // ==================== Tests Compilation Success ====================

    public function testCompileSucceedsOnFirstAttempt(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $validDSL = $this->getValidDSL();

        $this->llmClient->expects($this->once())->method('chat')->willReturn($validDSL);

        $result = $this->compiler->compile($profileYaml);

        $this->assertEquals(1, $result['attempts']);
        $this->assertEquals(1, $result['rules_count']);
        $this->assertStringContainsString('RULE', $result['rules_dsl']);
    }

    public function testCompileSucceedsOnSecondAttempt(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $validDSL = $this->getValidDSL();

        // First attempt fails, second succeeds
        $this->llmClient
            ->expects($this->exactly(2))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('Timeout')),
                $validDSL
            );

        $result = $this->compiler->compile($profileYaml);

        $this->assertEquals(2, $result['attempts']);
        $this->assertEquals(1, $result['rules_count']);
    }

    public function testCompileSucceedsOnThirdAttempt(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $validDSL = $this->getValidDSL();

        $this->llmClient
            ->expects($this->exactly(3))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('Error 1')),
                $this->throwException(new \RuntimeException('Error 2')),
                $validDSL
            );

        $result = $this->compiler->compile($profileYaml);

        $this->assertEquals(3, $result['attempts']);
    }

    public function testCompileFailsAfter3Attempts(): void
    {
        $profileYaml = $this->getValidProfileYaml();

        $this->llmClient
            ->expects($this->exactly(3))
            ->method('chat')
            ->willThrowException(new \RuntimeException('Persistent error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed after 3 attempts');

        $this->compiler->compile($profileYaml);
    }

    // ==================== Tests DSL Extraction ====================

    public function testExtractDSLFromMarkdownCodeBlock(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $validDSL = $this->getValidDSL();

        // LLM returns DSL wrapped in markdown
        $markdownResponse = "```dsl\n{$validDSL}\n```";

        $this->llmClient->method('chat')->willReturn($markdownResponse);

        $result = $this->compiler->compile($profileYaml);

        $this->assertStringContainsString('RULE', $result['rules_dsl']);
        $this->assertStringNotContainsString('```', $result['rules_dsl']);
    }

    public function testExtractDSLFromMarkdownWithoutLanguage(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $validDSL = $this->getValidDSL();

        $markdownResponse = "```\n{$validDSL}\n```";

        $this->llmClient->method('chat')->willReturn($markdownResponse);

        $result = $this->compiler->compile($profileYaml);

        $this->assertStringContainsString('RULE', $result['rules_dsl']);
    }

    public function testExtractDSLFromRawResponse(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $validDSL = $this->getValidDSL();

        $this->llmClient->method('chat')->willReturn($validDSL);

        $result = $this->compiler->compile($profileYaml);

        $this->assertStringContainsString('RULE', $result['rules_dsl']);
    }

    public function testExtractDSLFromResponseWithPrefixText(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $validDSL = $this->getValidDSL();

        $responseWithPrefix = "Here are the detection rules:\n\n{$validDSL}";

        $this->llmClient->method('chat')->willReturn($responseWithPrefix);

        $result = $this->compiler->compile($profileYaml);

        $this->assertStringContainsString('RULE', $result['rules_dsl']);
    }

    public function testExtractDSLThrowsWhenNoDSLFound(): void
    {
        $profileYaml = $this->getValidProfileYaml();

        // Response without valid DSL
        $invalidResponse = "I cannot generate rules for this campaign.";

        $this->llmClient->method('chat')->willReturn($invalidResponse);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed after 3 attempts');

        $this->compiler->compile($profileYaml);
    }

    // ==================== Tests DSL Validation ====================

    public function testValidationAcceptsValidDSL(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $validDSL = $this->getValidDSL();

        $this->llmClient->method('chat')->willReturn($validDSL);

        $result = $this->compiler->compile($profileYaml);

        $this->assertStringContainsString('RULE', $result['rules_dsl']);
    }

    public function testValidationRejectsDSLWithoutRULEKeyword(): void
    {
        $profileYaml = $this->getValidProfileYaml();

        $invalidDSL = <<<DSL
scam.test {
  WHERE subject.simhash≈"test"
  ACTION tag="test"
}
DSL;

        $this->llmClient->method('chat')->willReturn($invalidDSL);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed after 3 attempts');

        $this->compiler->compile($profileYaml);
    }

    public function testValidationRejectsDSLWithoutWHEREClause(): void
    {
        $profileYaml = $this->getValidProfileYaml();

        $invalidDSL = <<<DSL
RULE scam.test {
  ACTION tag="test"
}
DSL;

        $this->llmClient->method('chat')->willReturn($invalidDSL);

        $this->expectException(\RuntimeException::class);

        $this->compiler->compile($profileYaml);
    }

    public function testValidationRejectsDSLWithoutACTIONClause(): void
    {
        $profileYaml = $this->getValidProfileYaml();

        $invalidDSL = <<<DSL
RULE scam.test {
  WHERE subject.simhash≈"test"
}
DSL;

        $this->llmClient->method('chat')->willReturn($invalidDSL);

        $this->expectException(\RuntimeException::class);

        $this->compiler->compile($profileYaml);
    }

    public function testValidationRejectsDSLWithoutValidFields(): void
    {
        $profileYaml = $this->getValidProfileYaml();

        // Uses invalid field "invalid_field"
        $invalidDSL = <<<DSL
RULE scam.test {
  WHERE invalid_field = "test"
  ACTION tag="test"
}
DSL;

        $this->llmClient->method('chat')->willReturn($invalidDSL);

        $this->expectException(\RuntimeException::class);

        $this->compiler->compile($profileYaml);
    }

    public function testValidationRejectsDSLWithoutValidOperators(): void
    {
        $profileYaml = $this->getValidProfileYaml();

        // No valid operators AND no valid fields
        $invalidDSL = <<<DSL
RULE scam.test {
  WHERE invalid_field something "test"
  ACTION tag="test"
}
DSL;

        $this->llmClient->method('chat')->willReturn($invalidDSL);

        $this->expectException(\RuntimeException::class);

        $this->compiler->compile($profileYaml);
    }

    public function testValidationAcceptsDSLWithSubjectField(): void
    {
        $profileYaml = $this->getValidProfileYaml();

        $dsl = <<<DSL
RULE scam.test {
  WHERE subject.simhash≈"urgent account"
  ACTION tag="test", score+=20
}
DSL;

        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($profileYaml);

        $this->assertStringContainsString('subject', $result['rules_dsl']);
    }

    public function testValidationAcceptsDSLWithBodyField(): void
    {
        $profileYaml = $this->getValidProfileYaml();

        $dsl = <<<DSL
RULE scam.test {
  WHERE body.containsAny ["verify", "confirm"]
  ACTION tag="test"
}
DSL;

        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($profileYaml);

        $this->assertStringContainsString('body', $result['rules_dsl']);
    }

    public function testValidationAcceptsDSLWithUrlDomainAgeField(): void
    {
        $profileYaml = $this->getValidProfileYaml();

        $dsl = <<<DSL
RULE scam.test {
  WHERE url.domain.age < 7d
    AND subject.simhash≈"test"
  ACTION tag="test"
}
DSL;

        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($profileYaml);

        $this->assertStringContainsString('url.domain.age', $result['rules_dsl']);
    }

    public function testValidationAcceptsDSLWithDKIMField(): void
    {
        $profileYaml = $this->getValidProfileYaml();

        $dsl = <<<DSL
RULE scam.test {
  WHERE dkim.pass = false
    AND subject.simhash≈"test"
  ACTION tag="test"
}
DSL;

        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($profileYaml);

        $this->assertStringContainsString('dkim.pass', $result['rules_dsl']);
    }

    public function testValidationAcceptsDSLWithSPFField(): void
    {
        $profileYaml = $this->getValidProfileYaml();

        $dsl = <<<DSL
RULE scam.test {
  WHERE spf.pass = false
    AND subject.simhash≈"test"
  ACTION tag="test"
}
DSL;

        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($profileYaml);

        $this->assertStringContainsString('spf.pass', $result['rules_dsl']);
    }

    // ==================== Tests Rule Counting ====================

    public function testCountsOneRule(): void
    {
        $profileYaml = $this->getValidProfileYaml();
        $dsl = $this->getValidDSL();

        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($profileYaml);

        $this->assertEquals(1, $result['rules_count']);
    }

    public function testCountsTwoRules(): void
    {
        $profileYaml = $this->getValidProfileYaml();

        $dsl = <<<DSL
RULE scam.test_1 {
  WHERE subject.simhash≈"test"
  ACTION tag="test1"
}

RULE scam.test_2 {
  WHERE body.containsAny ["verify"]
  ACTION tag="test2"
}
DSL;

        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($profileYaml);

        $this->assertEquals(2, $result['rules_count']);
    }

    public function testCountsThreeRules(): void
    {
        $profileYaml = $this->getValidProfileYaml();

        $dsl = <<<DSL
RULE scam.test_1 {
  WHERE subject.simhash≈"urgent"
  ACTION tag="test1"
}

RULE scam.test_2 {
  WHERE body.containsAny ["verify"]
    AND url.domain.age < 7d
  ACTION tag="test2"
}

RULE scam.test_3 {
  WHERE dkim.pass = false
  ACTION tag="test3", score+=10
}
DSL;

        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($profileYaml);

        $this->assertEquals(3, $result['rules_count']);
    }

    // ==================== Tests Test Generation ====================

    public function testGenerateTestsWithEmptyExamples(): void
    {
        $dsl = $this->getValidDSL();
        $examples = ['pos' => [], 'neg' => []];

        $result = $this->compiler->generateTests($dsl, $examples);

        $this->assertArrayHasKey('test_cases', $result);
        $this->assertEmpty($result['test_cases']);
    }

    public function testGenerateTestsWithPositiveExamples(): void
    {
        $dsl = $this->getValidDSL();
        $examples = [
            'pos' => [
                ['subject' => 'Urgent', 'body' => 'Verify your account', 'dkim' => 'fail'],
                ['subject' => 'Alert', 'body' => 'Confirm identity', 'dkim' => 'fail'],
            ],
            'neg' => [],
        ];

        $result = $this->compiler->generateTests($dsl, $examples);

        $this->assertCount(2, $result['test_cases']);
        $this->assertTrue($result['test_cases'][0]['expected']);
        $this->assertTrue($result['test_cases'][1]['expected']);
    }

    public function testGenerateTestsWithNegativeExamples(): void
    {
        $dsl = $this->getValidDSL();
        $examples = [
            'pos' => [],
            'neg' => [
                ['subject' => 'Newsletter', 'body' => 'Latest news', 'dkim' => 'pass'],
                ['subject' => 'Invoice', 'body' => 'Payment due', 'dkim' => 'pass'],
            ],
        ];

        $result = $this->compiler->generateTests($dsl, $examples);

        $this->assertCount(2, $result['test_cases']);
        $this->assertFalse($result['test_cases'][0]['expected']);
        $this->assertFalse($result['test_cases'][1]['expected']);
    }

    public function testGenerateTestsWithMixedExamples(): void
    {
        $dsl = $this->getValidDSL();
        $examples = [
            'pos' => [
                ['subject' => 'Phishing', 'body' => 'Click here', 'dkim' => 'fail'],
            ],
            'neg' => [
                ['subject' => 'Newsletter', 'body' => 'News', 'dkim' => 'pass'],
            ],
        ];

        $result = $this->compiler->generateTests($dsl, $examples);

        $this->assertCount(2, $result['test_cases']);
        $this->assertTrue($result['test_cases'][0]['expected']); // Positive
        $this->assertFalse($result['test_cases'][1]['expected']); // Negative
    }

    // ==================== Realistic Scenarios Tests ====================

    public function testPayPalPhishingRuleCompilation(): void
    {
        $profileYaml = <<<YAML
campaign:
  summary: "PayPal impersonation phishing"
  tactics: ["urgency", "impersonation"]
  target_audience: "PayPal users"
  cta: "click verification link"
  risk: 5
variants:
  subjects: ["Account Suspended", "Security Alert"]
  display_names: ["PayPal Security"]
  url_shapes: ["paypal-lookalike.com"]
infra:
  domain_age_pattern: "<14d"
  dkim_spf_pattern: "fail"
YAML;

        $paypalDSL = <<<DSL
RULE scam.paypal_2025_10 {
  WHERE subject.simhash≈"paypal account suspended" ±15%
    AND body.containsAny ["verify", "suspended", "security"]
    AND url.domain.age < 14d
    AND dkim.pass = false
  ACTION tag="campaign:paypal_phishing", score+=50
}
DSL;

        $this->llmClient->method('chat')->willReturn($paypalDSL);

        $result = $this->compiler->compile($profileYaml);

        $this->assertEquals(1, $result['rules_count']);
        $this->assertStringContainsString('paypal', $result['rules_dsl']);
        $this->assertStringContainsString('score+=50', $result['rules_dsl']);
    }

    public function testBankPhishingRuleWithRetries(): void
    {
        $profileYaml = <<<YAML
campaign:
  summary: "Generic bank phishing"
  tactics: ["urgency", "account blocking"]
  target_audience: "bank customers"
  cta: "unlock account"
  risk: 4
variants:
  subjects: ["Compte bloqué"]
  display_names: ["Sécurité Bancaire"]
infra:
  domain_age_pattern: "<7d"
YAML;

        $bankDSL = <<<DSL
RULE scam.bank_generic_2025_10 {
  WHERE subject.simhash≈"compte bloqué" ±10%
    AND url.domain.age < 7d
  ACTION tag="campaign:bank_phishing", score+=40
}
DSL;

        // Fails once, then succeeds
        $this->llmClient
            ->expects($this->exactly(2))
            ->method('chat')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('Timeout')),
                $bankDSL
            );

        $result = $this->compiler->compile($profileYaml);

        $this->assertEquals(2, $result['attempts']);
        $this->assertEquals(1, $result['rules_count']);
    }

    public function testComplexDSLWithMultipleConditions(): void
    {
        $profileYaml = $this->getValidProfileYaml();

        $complexDSL = <<<DSL
RULE scam.complex_2025_10 {
  WHERE subject.simhash≈"urgent action required" ±20%
    AND body.containsAny ["verify account", "confirm identity", "security update"]
    AND url.domain.age < 14d
    AND sender.display_name ∈ ["Security Team", "Account Support"]
    AND dkim.pass ∈ {false, null}
    AND spf.pass = false
  ACTION tag="campaign:complex_phishing", score+=60, quarantine=true
}
DSL;

        $this->llmClient->method('chat')->willReturn($complexDSL);

        $result = $this->compiler->compile($profileYaml);

        $this->assertEquals(1, $result['rules_count']);
        $this->assertStringContainsString('sender.display_name', $result['rules_dsl']);
        $this->assertStringContainsString('quarantine=true', $result['rules_dsl']);
    }

    // ==================== Helper Methods ====================

    private function getValidProfileYaml(): string
    {
        return <<<YAML
campaign:
  summary: "Generic phishing"
  tactics: ["urgency"]
  target_audience: "users"
  cta: "click link"
  risk: 3
variants:
  subjects: ["Test"]
  display_names: ["Support"]
  url_shapes: ["example.com"]
infra:
  domain_age_pattern: "<7d"
YAML;
    }

    private function getValidDSL(): string
    {
        return <<<DSL
RULE scam.test_2025_10 {
  WHERE subject.simhash≈"urgent account"
    AND body.containsAny ["verify", "confirm"]
  ACTION tag="campaign:test", score+=30
}
DSL;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Campaign;

use App\Application\Campaign\PromptBuilder;
use App\Application\Campaign\RuleCompiler;
use App\Application\LLM\Port\LLMClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Mutation-killing tests for RuleCompiler.
 *
 * Targets:
 * - DSL validation: WHERE clause required
 * - DSL validation: ACTION clause required
 * - DSL validation: valid fields required
 * - DSL validation: valid operators required
 * - Rule counting correctness
 * - MAX_RETRIES=3 boundary
 * - Error message contains rule name
 * - Extraction from markdown vs raw
 * - Test generation positive/negative correctness
 * - Allowed fields list completeness
 * - Allowed operators list completeness
 */
final class RuleCompilerMutationTest extends TestCase
{
    private RuleCompiler $compiler;
    private LLMClientInterface&MockObject $llmClient;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->compiler = new RuleCompiler($this->llmClient, new PromptBuilder(), $this->logger);
    }

    private function validDsl(): string
    {
        return <<<'DSL'
RULE scam.test_001 {
  WHERE subject simhash≈"urgent"
    AND body containsAny ["verify", "confirm"]
  ACTION tag="test", score+=30
}
DSL;
    }

    private function profileYaml(): string
    {
        return <<<'YAML'
campaign:
  summary: "Test"
  tactics: ["urgency"]
  target_audience: "users"
  cta: "click"
  risk: 3
variants:
  subjects: ["Test"]
  display_names: ["Support"]
  url_shapes: ["test.com"]
infra:
  domain_age_pattern: "<7d"
YAML;
    }

    // === WHERE clause required ===

    public function test_dsl_without_where_fails(): void
    {
        $dsl = "RULE test.rule {\n  ACTION block\n}";
        $this->llmClient->method('chat')->willReturn($dsl);

        $this->expectException(\RuntimeException::class);
        $this->compiler->compile($this->profileYaml());
    }

    // === ACTION clause required ===

    public function test_dsl_without_action_fails(): void
    {
        $dsl = "RULE test.rule {\n  WHERE subject containsAny [\"test\"]\n}";
        $this->llmClient->method('chat')->willReturn($dsl);

        $this->expectException(\RuntimeException::class);
        $this->compiler->compile($this->profileYaml());
    }

    // === Valid fields required ===

    public function test_dsl_with_invalid_field_only_fails(): void
    {
        $dsl = "RULE test.rule {\n  WHERE invalid_field = \"test\"\n  ACTION block\n}";
        $this->llmClient->method('chat')->willReturn($dsl);

        $this->expectException(\RuntimeException::class);
        $this->compiler->compile($this->profileYaml());
    }

    public function test_dsl_with_subject_field_succeeds(): void
    {
        $dsl = "RULE test.rule {\n  WHERE subject simhash≈\"test\"\n  ACTION block\n}";
        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($this->profileYaml());
        $this->assertStringContainsString('subject', $result['rules_dsl']);
    }

    public function test_dsl_with_body_field_succeeds(): void
    {
        $dsl = "RULE test.rule {\n  WHERE body containsAny [\"test\"]\n  ACTION block\n}";
        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($this->profileYaml());
        $this->assertStringContainsString('body', $result['rules_dsl']);
    }

    public function test_dsl_with_url_domain_age_field_succeeds(): void
    {
        $dsl = "RULE test.rule {\n  WHERE url.domain.age < 7d\n    AND subject simhash≈\"x\"\n  ACTION block\n}";
        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($this->profileYaml());
        $this->assertStringContainsString('url.domain.age', $result['rules_dsl']);
    }

    public function test_dsl_with_sender_display_name_field_succeeds(): void
    {
        $dsl = "RULE test.rule {\n  WHERE sender.display_name = \"Bank\"\n    AND subject simhash≈\"x\"\n  ACTION block\n}";
        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($this->profileYaml());
        $this->assertStringContainsString('sender.display_name', $result['rules_dsl']);
    }

    public function test_dsl_with_dkim_pass_field_succeeds(): void
    {
        $dsl = "RULE test.rule {\n  WHERE dkim.pass = false\n    AND subject simhash≈\"x\"\n  ACTION block\n}";
        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($this->profileYaml());
        $this->assertStringContainsString('dkim.pass', $result['rules_dsl']);
    }

    public function test_dsl_with_spf_pass_field_succeeds(): void
    {
        $dsl = "RULE test.rule {\n  WHERE spf.pass = false\n    AND subject simhash≈\"x\"\n  ACTION block\n}";
        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($this->profileYaml());
        $this->assertStringContainsString('spf.pass', $result['rules_dsl']);
    }

    // === Valid operators required ===

    public function test_dsl_without_valid_operator_fails(): void
    {
        $dsl = "RULE test.rule {\n  WHERE subject is \"test\"\n  ACTION block\n}";
        $this->llmClient->method('chat')->willReturn($dsl);

        $this->expectException(\RuntimeException::class);
        $this->compiler->compile($this->profileYaml());
    }

    public function test_dsl_with_simhash_operator_succeeds(): void
    {
        $dsl = "RULE test.rule {\n  WHERE subject simhash≈\"test\"\n  ACTION block\n}";
        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($this->profileYaml());
        $this->assertGreaterThan(0, $result['rules_count']);
    }

    public function test_dsl_with_containsAny_operator_succeeds(): void
    {
        $dsl = "RULE test.rule {\n  WHERE body containsAny [\"test\"]\n  ACTION block\n}";
        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($this->profileYaml());
        $this->assertGreaterThan(0, $result['rules_count']);
    }

    public function test_dsl_with_less_than_operator_succeeds(): void
    {
        $dsl = "RULE test.rule {\n  WHERE url.domain.age < 7d\n    AND subject simhash≈\"x\"\n  ACTION block\n}";
        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($this->profileYaml());
        $this->assertGreaterThan(0, $result['rules_count']);
    }

    public function test_dsl_with_equals_operator_succeeds(): void
    {
        $dsl = "RULE test.rule {\n  WHERE dkim.pass = false\n    AND subject simhash≈\"x\"\n  ACTION block\n}";
        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($this->profileYaml());
        $this->assertGreaterThan(0, $result['rules_count']);
    }

    // === Rule counting ===

    public function test_count_1_rule(): void
    {
        $this->llmClient->method('chat')->willReturn($this->validDsl());

        $result = $this->compiler->compile($this->profileYaml());
        $this->assertSame(1, $result['rules_count']);
    }

    public function test_count_2_rules(): void
    {
        $dsl = $this->validDsl() . "\n\nRULE scam.test_002 {\n  WHERE body containsAny [\"phish\"]\n  ACTION block\n}";
        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($this->profileYaml());
        $this->assertSame(2, $result['rules_count']);
    }

    public function test_count_3_rules(): void
    {
        $dsl = $this->validDsl()
            . "\n\nRULE scam.test_002 {\n  WHERE body containsAny [\"phish\"]\n  ACTION block\n}"
            . "\n\nRULE scam.test_003 {\n  WHERE dkim.pass = false\n    AND subject simhash≈\"x\"\n  ACTION flag\n}";
        $this->llmClient->method('chat')->willReturn($dsl);

        $result = $this->compiler->compile($this->profileYaml());
        $this->assertSame(3, $result['rules_count']);
    }

    // === MAX_RETRIES = 3 ===

    public function test_retries_exactly_3_times_then_fails(): void
    {
        $this->llmClient->expects($this->exactly(3))
            ->method('chat')
            ->willThrowException(new \RuntimeException('timeout'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed after 3 attempts');

        $this->compiler->compile($this->profileYaml());
    }

    public function test_error_message_contains_original_error(): void
    {
        $this->llmClient->method('chat')->willThrowException(new \RuntimeException('Specific LLM error'));

        try {
            $this->compiler->compile($this->profileYaml());
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Specific LLM error', $e->getMessage());
            $this->assertStringContainsString('3 attempts', $e->getMessage());
        }
    }

    // === Extraction from markdown ===

    public function test_dsl_extracted_from_markdown_dsl_block(): void
    {
        $dsl = $this->validDsl();
        $this->llmClient->method('chat')->willReturn("```dsl\n{$dsl}\n```");

        $result = $this->compiler->compile($this->profileYaml());
        $this->assertStringNotContainsString('```', $result['rules_dsl']);
        $this->assertStringContainsString('RULE', $result['rules_dsl']);
    }

    public function test_dsl_extracted_from_markdown_generic_block(): void
    {
        $dsl = $this->validDsl();
        $this->llmClient->method('chat')->willReturn("```\n{$dsl}\n```");

        $result = $this->compiler->compile($this->profileYaml());
        $this->assertStringContainsString('RULE', $result['rules_dsl']);
    }

    public function test_dsl_extracted_from_raw_response(): void
    {
        $this->llmClient->method('chat')->willReturn($this->validDsl());

        $result = $this->compiler->compile($this->profileYaml());
        $this->assertStringContainsString('RULE', $result['rules_dsl']);
    }

    public function test_no_dsl_in_response_fails(): void
    {
        $this->llmClient->method('chat')->willReturn('I cannot generate rules.');

        $this->expectException(\RuntimeException::class);
        $this->compiler->compile($this->profileYaml());
    }

    // === Test generation ===

    public function test_generate_tests_empty_produces_empty(): void
    {
        $result = $this->compiler->generateTests($this->validDsl(), ['pos' => [], 'neg' => []]);
        $this->assertEmpty($result['test_cases']);
    }

    public function test_generate_tests_positive_expected_true(): void
    {
        $result = $this->compiler->generateTests($this->validDsl(), [
            'pos' => [['subject' => 'Urgent', 'body' => 'Verify']],
            'neg' => [],
        ]);
        $this->assertTrue($result['test_cases'][0]['expected']);
    }

    public function test_generate_tests_negative_expected_false(): void
    {
        $result = $this->compiler->generateTests($this->validDsl(), [
            'pos' => [],
            'neg' => [['subject' => 'Newsletter', 'body' => 'News']],
        ]);
        $this->assertFalse($result['test_cases'][0]['expected']);
    }

    public function test_generate_tests_description_contains_positive(): void
    {
        $result = $this->compiler->generateTests($this->validDsl(), [
            'pos' => [['subject' => 'x']],
            'neg' => [],
        ]);
        $this->assertStringContainsString('Positive', $result['test_cases'][0]['description']);
    }

    public function test_generate_tests_description_contains_negative(): void
    {
        $result = $this->compiler->generateTests($this->validDsl(), [
            'pos' => [],
            'neg' => [['subject' => 'x']],
        ]);
        $this->assertStringContainsString('Negative', $result['test_cases'][0]['description']);
    }

    public function test_generate_tests_input_has_subject_body_dkim(): void
    {
        $result = $this->compiler->generateTests($this->validDsl(), [
            'pos' => [['subject' => 'S', 'body' => 'B', 'dkim' => 'fail']],
            'neg' => [],
        ]);
        $input = $result['test_cases'][0]['input'];
        $this->assertSame('S', $input['subject']);
        $this->assertSame('B', $input['body']);
        $this->assertSame('fail', $input['dkim']);
    }

    public function test_generate_tests_missing_fields_default_to_empty_or_null(): void
    {
        $result = $this->compiler->generateTests($this->validDsl(), [
            'pos' => [[]],
            'neg' => [],
        ]);
        $input = $result['test_cases'][0]['input'];
        $this->assertSame('', $input['subject']);
        $this->assertSame('', $input['body']);
        $this->assertNull($input['dkim']);
    }

    // === Compile returns correct keys ===

    public function test_compile_result_has_rules_dsl(): void
    {
        $this->llmClient->method('chat')->willReturn($this->validDsl());
        $result = $this->compiler->compile($this->profileYaml());
        $this->assertArrayHasKey('rules_dsl', $result);
    }

    public function test_compile_result_has_rules_count(): void
    {
        $this->llmClient->method('chat')->willReturn($this->validDsl());
        $result = $this->compiler->compile($this->profileYaml());
        $this->assertArrayHasKey('rules_count', $result);
    }

    public function test_compile_result_has_attempts(): void
    {
        $this->llmClient->method('chat')->willReturn($this->validDsl());
        $result = $this->compiler->compile($this->profileYaml());
        $this->assertArrayHasKey('attempts', $result);
        $this->assertSame(1, $result['attempts']);
    }
}

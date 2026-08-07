<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\ContextAnalyzer;
use App\Application\LLM\ReciprocityManager;
use App\Application\LLM\ReplyValidator;
use App\Application\LLM\PromptBuilder;
use App\Application\LLM\VariationProvider;
use App\Application\LLM\Port\LLMClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ReplyValidator with mocked LLM
 */
class ReplyValidatorTest extends TestCase
{
    private ReplyValidator $validator;
    private LLMClientInterface $llmClient;
    private PromptBuilder $promptBuilder;
    private LoggerInterface $logger;
    private ContextAnalyzer $contextAnalyzer;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->contextAnalyzer = new ContextAnalyzer();
        $variationProvider = new VariationProvider();
        $reciprocityManager = new ReciprocityManager();

        // Mock PersonaManager to return a test persona
        $personaManager = $this->createMock(\App\Application\Communication\PersonaManager::class);
        $testPersona = new \App\Domain\Communication\Persona(
            'bank_customer',
            'Client bancaire inquiet',
            'Inquiet, méfiant mais coopératif',
            'Tu es un client bancaire inquiet qui a reçu un message suspect.'
        );
        $personaManager->method('findByCode')->willReturn($testPersona);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $this->promptBuilder = new PromptBuilder($this->contextAnalyzer, $variationProvider, $reciprocityManager, $personaManager, $logger);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->validator = new ReplyValidator(
            $this->llmClient,
            $this->promptBuilder,
            $this->logger
        );
    }

    public function test_validate_forces_json_response_format(): void
    {
        $captured = [];
        $this->llmClient->method('chat')->willReturnCallback(
            function (array $messages, array $options) use (&$captured): string {
                $captured = $options;

                return '{"naturalness":4,"persona_fit":4,"ti_value":3,"security_pass":true,"reasons":["ok"]}';
            }
        );

        $this->validator->validate('A perfectly ordinary reply for the validator to score.', 'generic_user');

        self::assertSame(['type' => 'json_object'], $captured['response_format'] ?? null, 'validator must request JSON mode, not free text');
    }

    /**
     * @test
     */
    public function it_validates_approved_reply(): void
    {
        $text = str_repeat('Bonjour, je suis intéressé. ', 15);

        $mockLlmResponse = json_encode([
            'approved' => true,
            'reasons' => ['Tone is appropriate', 'Contains question'],
            'fix_suggestion' => null,
        ]);

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($mockLlmResponse);

        $result = $this->validator->validate($text, 'bank_customer');

        $this->assertTrue($result['approved']);
        $this->assertCount(2, $result['reasons']);
        $this->assertNull($result['fix_suggestion']);
    }

    /**
     * @test
     */
    public function it_validates_rejected_reply(): void
    {
        $text = 'Too short';

        $mockLlmResponse = json_encode([
            'approved' => false,
            'reasons' => ['Text too short', 'No question asked'],
            'fix_suggestion' => 'Add more context and a question',
        ]);

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($mockLlmResponse);

        $result = $this->validator->validate($text, 'bank_customer');

        $this->assertFalse($result['approved']);
        $this->assertCount(2, $result['reasons']);
        $this->assertEquals('Add more context and a question', $result['fix_suggestion']);
    }

    /**
     * @test
     */
    public function it_extracts_json_from_markdown_code_block(): void
    {
        $text = str_repeat('Valid text. ', 20);

        $mockLlmResponse = <<<'JSON'
```json
{
    "approved": true,
    "reasons": ["Good"],
    "fix_suggestion": null
}
```
JSON;

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($mockLlmResponse);

        $result = $this->validator->validate($text, 'bank_customer');

        $this->assertTrue($result['approved']);
    }

    /**
     * @test
     */
    public function it_extracts_json_without_code_block(): void
    {
        $text = str_repeat('Valid text. ', 20);

        $mockLlmResponse = '{"approved": true, "reasons": [], "fix_suggestion": null}';

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($mockLlmResponse);

        $result = $this->validator->validate($text, 'bank_customer');

        $this->assertTrue($result['approved']);
    }

    /**
     * @test
     */
    public function it_throws_exception_for_invalid_json(): void
    {
        $text = str_repeat('Some text. ', 20);

        $mockLlmResponse = 'This is not JSON';

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($mockLlmResponse);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Validator returned invalid JSON');

        $this->validator->validate($text, 'bank_customer');
    }

    /**
     * @test
     */
    public function it_throws_exception_for_missing_approved_field(): void
    {
        $text = str_repeat('Some text. ', 20);

        $mockLlmResponse = '{"reasons": [], "fix_suggestion": null}';

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($mockLlmResponse);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing or invalid "approved" field');

        $this->validator->validate($text, 'bank_customer');
    }

    /**
     * @test
     */
    public function it_uses_low_temperature_for_validation(): void
    {
        $text = str_repeat('Valid text. ', 20);

        $mockLlmResponse = '{"naturalness":4,"naturalness_reasoning":"Good","persona_fit":4,"persona_fit_reasoning":"Good","ti_value":3,"ti_value_reasoning":"OK","security_pass":true,"security_reasoning":"Safe","feedback":"Good","fix_suggestion":null}';

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->anything(),
                $this->callback(function ($options) {
                    return isset($options['temperature']) && $options['temperature'] === 0.4;
                })
            )
            ->willReturn($mockLlmResponse);

        $this->validator->validate($text, 'bank_customer');
    }

    /**
     * @test
     */
    public function it_handles_missing_fix_suggestion(): void
    {
        $text = str_repeat('Valid text. ', 20);

        $mockLlmResponse = '{"approved": false, "reasons": ["Issue"]}';

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($mockLlmResponse);

        $result = $this->validator->validate($text, 'bank_customer');

        $this->assertFalse($result['approved']);
        $this->assertNull($result['fix_suggestion']);
    }

    // ─── Context-aware signature backward-compat ─────

    public function test_validate_signature_remains_backward_compatible_with_2_args(): void
    {
        $text = str_repeat('Valid text. ', 20);
        $this->llmClient->method('chat')->willReturn('{"approved": true, "reasons": []}');

        // 2-arg call (legacy) — must still work without exception.
        $result = $this->validator->validate($text, 'bank_customer');

        $this->assertTrue($result['approved']);
    }

    public function test_validate_accepts_context_when_flag_enabled(): void
    {
        $text = str_repeat('Valid text. ', 20);
        $this->llmClient->method('chat')->willReturn('{"approved": true, "reasons": []}');

        $context = ['inbound_text' => 'scammer text', 'language' => 'en'];

        // The logger mock from setUp() is a stub (no expectations). We assert
        // that the call WITH context completes without exception, returning
        // the same legacy shape as without context.
        $result = $this->validator->validate($text, 'bank_customer', $context);

        $this->assertTrue($result['approved']);
    }

    public function test_validate_ignores_context_when_flag_disabled(): void
    {
        $text = str_repeat('Valid text. ', 20);
        $this->llmClient->method('chat')->willReturn('{"approved": true, "reasons": []}');

        $disabledValidator = new ReplyValidator(
            $this->llmClient,
            $this->promptBuilder,
            $this->logger,
            validatorContextEnabled: false,
        );

        $context = ['inbound_text' => 'scammer text', 'language' => 'en'];
        $result = $disabledValidator->validate($text, 'bank_customer', $context);

        // The flag-disabled validator must still return a well-formed result.
        $this->assertTrue($result['approved']);
    }
}

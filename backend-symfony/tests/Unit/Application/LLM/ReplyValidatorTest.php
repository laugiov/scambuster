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

        $mockLlmResponse = '{"approved": true, "reasons": [], "fix_suggestion": null}';

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->anything(),
                $this->callback(function ($options) {
                    return isset($options['temperature']) && $options['temperature'] === 0.1;
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
}

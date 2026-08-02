<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\PersonaManager;
use App\Application\LLM\ContextAnalyzer;
use App\Application\LLM\PromptBuilder;
use App\Application\LLM\ReciprocityManager;
use App\Application\LLM\VariationProvider;
use App\Domain\Communication\Persona;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Preventive no-signature instruction in the generator
 * user prompt. Gated by REPLY_GENERATOR_NO_SIGNATURE_INSTRUCTION
 * (=$generatorNoSignatureInstruction).
 */
final class PromptBuilderGeneratorNoSignatureTest extends TestCase
{
    private function newBuilder(bool $noSignatureInstruction = true): PromptBuilder
    {
        $personaManager = $this->createMock(PersonaManager::class);
        $personaManager->method('findByCode')->willReturn(new Persona(
            'bank_customer',
            'Bank customer',
            'Worried but cooperative',
            'You are a worried bank customer.',
        ));

        return new PromptBuilder(
            contextAnalyzer: new ContextAnalyzer(),
            variationProvider: new VariationProvider(),
            reciprocityManager: new ReciprocityManager(),
            personaManager: $personaManager,
            logger: new NullLogger(),
            conversationAnalyzer: null,
            validatorContextEnabled: true,
            validatorStructuredCorrection: true,
            generatorNoSignatureInstruction: $noSignatureInstruction,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function newContext(): array
    {
        return [
            'conv_id' => 'conv-1',
            'detected_language' => 'en',
            'scam_type' => ['code' => 'PHISHING', 'label_fr' => 'Phishing'],
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'Hello scammer message', 'ts_msg' => '2026-05-12T10:00:00Z', 'headers' => ['from' => 'scammer@test']],
            ],
            'policy_min_words' => 50,
            'policy_max_words' => 150,
        ];
    }

    public function test_instruction_appears_when_flag_enabled(): void
    {
        $prompts = $this->newBuilder()->buildGeneratorPrompts($this->newContext(), 'bank_customer');

        self::assertStringContainsString(
            'End your reply WITHOUT any signature',
            $prompts['user'],
        );
        self::assertStringContainsString(
            'The persona never signs replies',
            $prompts['user'],
        );
    }

    public function test_instruction_absent_when_flag_disabled(): void
    {
        $prompts = $this->newBuilder(noSignatureInstruction: false)
            ->buildGeneratorPrompts($this->newContext(), 'bank_customer');

        self::assertStringNotContainsString(
            'End your reply WITHOUT any signature',
            $prompts['user'],
        );
        // The legacy negative placeholder instruction stays.
        self::assertStringContainsString(
            'Never use placeholders like [Your Name]',
            $prompts['user'],
        );
    }
}

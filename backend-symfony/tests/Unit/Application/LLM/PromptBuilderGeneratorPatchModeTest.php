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
 * Spec 080 §4 — patch-mode retry instructions in the generator user prompt.
 */
final class PromptBuilderGeneratorPatchModeTest extends TestCase
{
    private function newBuilder(bool $patchMode = true): PromptBuilder
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
            generatorNoSignatureInstruction: true,
            generatorPatchMode: $patchMode,
        );
    }

    /**
     * @param array<string, string>|null $retryCorrection
     * @return array<string, mixed>
     */
    private function newContext(?array $retryCorrection): array
    {
        $context = [
            'conv_id' => 'conv-1',
            'detected_language' => 'en',
            'scam_type' => ['code' => 'PHISHING', 'label_fr' => 'Phishing'],
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'scammer msg', 'ts_msg' => '2026-05-12T10:00:00Z', 'headers' => ['from' => 'scammer@test']],
            ],
            'policy_min_words' => 50,
            'policy_max_words' => 150,
        ];

        if ($retryCorrection !== null) {
            $context['retry_correction'] = $retryCorrection;
        }

        return $context;
    }

    public function test_legacy_retry_prompt_preserves_dialogue_block(): void
    {
        // No retry_correction → builder must emit the legacy "Write your
        // reply now." closing instruction (no patch-mode block).
        $prompts = $this->newBuilder()->buildGeneratorPrompts($this->newContext(null), 'bank_customer');

        self::assertStringContainsString('Write your reply now.', $prompts['user']);
        self::assertStringNotContainsString('### Apply this exact correction', $prompts['user']);
    }

    public function test_patch_mode_block_appears_when_retry_correction_set_and_flag_on(): void
    {
        $correction = [
            'problem_span' => "Best,\nEta",
            'replacement' => '',
            'rationale' => 'Scammer name copy.',
        ];

        $prompts = $this->newBuilder()->buildGeneratorPrompts($this->newContext($correction), 'bank_customer');

        self::assertStringContainsString('### Apply this exact correction', $prompts['user']);
        self::assertStringContainsString("Problem to fix: Best,\nEta", $prompts['user']);
        self::assertStringContainsString('Replace with: ', $prompts['user']);
        self::assertStringContainsString('Rationale: Scammer name copy.', $prompts['user']);
        self::assertStringContainsString('Preserve all other sentences word-for-word', $prompts['user']);
        // The legacy "Write your reply now." must NOT appear (replaced).
        self::assertStringNotContainsString('Write your reply now.', $prompts['user']);
    }

    public function test_when_patch_mode_flag_disabled_uses_legacy_close(): void
    {
        $correction = [
            'problem_span' => "Best,\nEta",
            'replacement' => '',
            'rationale' => 'r',
        ];

        $prompts = $this->newBuilder(patchMode: false)
            ->buildGeneratorPrompts($this->newContext($correction), 'bank_customer');

        // Flag off → patch-mode block must NOT appear, even if retry_correction
        // is in context.
        self::assertStringNotContainsString('### Apply this exact correction', $prompts['user']);
        self::assertStringContainsString('Write your reply now.', $prompts['user']);
    }
}

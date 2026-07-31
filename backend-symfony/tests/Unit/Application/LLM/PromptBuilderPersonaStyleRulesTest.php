<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\PersonaManager;
use App\Application\LLM\ContextAnalyzer;
use App\Application\LLM\Prompt\BasePromptRules;
use App\Application\LLM\Prompt\EphemeralPromptOverride;
use App\Application\LLM\Prompt\PromptProvider;
use App\Application\LLM\PromptBuilder;
use App\Application\LLM\ReciprocityManager;
use App\Application\LLM\VariationProvider;
use App\Domain\Communication\Persona;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Locks the operator-overridable persona style rules: only the EDITABLE subset is replaceable;
 * the CORE (safety / anti-unmask / language) rules are always injected and never removable.
 */
final class PromptBuilderPersonaStyleRulesTest extends TestCase
{
    private function builder(?PromptProvider $prompts): PromptBuilder
    {
        $personaManager = $this->createMock(PersonaManager::class);
        $personaManager->method('findByCode')->willReturn(
            new Persona('generic_user', 'Generic User', 'Neutral', 'You are a friendly person who is curious about everything.'),
        );

        return new PromptBuilder(
            new ContextAnalyzer(),
            new VariationProvider(),
            new ReciprocityManager(),
            $personaManager,
            new NullLogger(),
            prompts: $prompts,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return [
            'conv_id' => 'test-conv',
            'scam_type' => ['code' => 'PHISHING', 'label_fr' => 'Phishing'],
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'Hello target', 'headers' => ['from' => 'scammer@evil.com'], 'ts_msg' => '2026-01-01T10:00:00+00:00'],
            ],
            'detected_language' => 'en',
            'extracted_iocs' => [],
            'sender_history_summary' => null,
        ];
    }

    private function providerWithOverride(string $body): PromptProvider
    {
        $ephemeral = new EphemeralPromptOverride();
        $ephemeral->set('persona_style_rules', $body);

        return new PromptProvider(sys_get_temp_dir(), new NullLogger(), $ephemeral);
    }

    public function testNoOverrideKeepsShippedEditableRulesAndCore(): void
    {
        $system = $this->builder(null)->buildGeneratorPrompts($this->context(), 'generic_user')['system'];

        // Shipped editable rule present, and a CORE rule present.
        self::assertStringContainsString('starts emails with a greeting', $system);
        self::assertStringContainsString('no knowledge of honeypots', $system);
        // The exact shipped assembly: core block then editable block (editable last).
        self::assertStringContainsString(
            BasePromptRules::getCoreRules('en') . "\n" . BasePromptRules::getEditableRules('en'),
            $system,
        );
    }

    public function testOverrideReplacesEditableRulesButKeepsCore(): void
    {
        $builder = $this->builder($this->providerWithOverride('CUSTOM STYLE: write like a terse accountant.'));
        $system = $builder->buildGeneratorPrompts($this->context(), 'generic_user')['system'];

        // Operator's editable override is used…
        self::assertStringContainsString('CUSTOM STYLE: write like a terse accountant.', $system);
        // …the shipped editable rule is gone (replaced)…
        self::assertStringNotContainsString('starts emails with a greeting', $system);
        // …but every CORE safety rule is still injected, un-removable.
        self::assertStringContainsString('no knowledge of honeypots', $system);
        self::assertStringContainsString('Keep everything on this email thread', $system);
        self::assertStringContainsString('writes entirely in en', $system);
    }
}

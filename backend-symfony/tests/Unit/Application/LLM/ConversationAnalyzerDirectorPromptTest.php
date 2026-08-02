<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\ConversationAnalyzer;
use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\Prompt\EphemeralPromptOverride;
use App\Application\LLM\Prompt\PromptProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Locks the operator-overridable Conversation Director blocks (strategy + tone): the assembled
 * analysis prompt is byte-identical when unwired, an override replaces only its own block, and the
 * JSON output contract + anti-unmask / hostile-state / multilingual CORE rules are never editable.
 */
final class ConversationAnalyzerDirectorPromptTest extends TestCase
{
    /**
     * Byte-identical anti-regression lock: the assembled prompt with no override must not drift
     * from the pre-refactor text. Captured from the shipped code before the override seam existed.
     */
    private const GOLDEN_SHA256 = '182fa6cb0bee869e48e513e2c0921303f81a78e7175e75610626f5769e3a555a';

    private function analyzer(?PromptProvider $prompts): ConversationAnalyzer
    {
        return new ConversationAnalyzer($this->createMock(LLMClientInterface::class), new NullLogger(), $prompts);
    }

    private function build(ConversationAnalyzer $analyzer): string
    {
        $context = [
            'scam_type' => 'ADVANCE_FEE',
            'persona_code' => 'cautious_retiree',
            'all_messages' => [
                ['direction' => 'in', 'body_text' => 'Hello dear friend', 'ts_msg' => '2026-01-01 10:00:00'],
                ['direction' => 'out', 'body_text' => 'Thanks for your message', 'ts_msg' => '2026-01-02 10:00:00'],
            ],
            'extracted_iocs' => [['type' => 'iban', 'value' => 'GB29NWBK60161331926819', 'category' => 'financial']],
        ];
        $prepared = [
            ['direction' => 'in', 'body_text' => 'Hello dear friend', 'ts_msg' => '2026-01-01 10:00:00', 'subject' => 'Opportunity'],
            ['direction' => 'out', 'body_text' => 'Thanks for your message', 'ts_msg' => '2026-01-02 10:00:00'],
        ];

        $method = new \ReflectionMethod($analyzer, 'buildAnalysisPrompt');
        $method->setAccessible(true);

        return (string) $method->invoke($analyzer, $context, $prepared);
    }

    private function providerWithOverride(string $key, string $body): PromptProvider
    {
        $ephemeral = new EphemeralPromptOverride();
        $ephemeral->set($key, $body);

        return new PromptProvider(sys_get_temp_dir(), new NullLogger(), $ephemeral);
    }

    public function testAssembledPromptIsByteIdenticalWithoutOverride(): void
    {
        self::assertSame(
            self::GOLDEN_SHA256,
            hash('sha256', $this->build($this->analyzer(null))),
            'Director analysis prompt drifted — if this is intentional, re-capture the golden hash and, '
            . 'because the reply canary scores this prompt, regenerate the guard baseline.',
        );
    }

    public function testStrategyOverrideReplacesOnlyItsBlockAndKeepsCore(): void
    {
        $prompt = $this->build($this->analyzer($this->providerWithOverride(
            'conversation_director_strategy',
            '- objective: CUSTOM STRATEGY, extract only the phone number.',
        )));

        // Operator override is used…
        self::assertStringContainsString('CUSTOM STRATEGY, extract only the phone number', $prompt);
        // …the shipped strategy line is gone…
        self::assertStringNotContainsString('Do not default to a bank-wire objective', $prompt);
        // …but the CORE is untouchable: JSON output contract, anti-unmask, hostile detection, language.
        self::assertStringContainsString('"director": {', $prompt);
        self::assertStringContainsString('re-asking is the #1 way our bot gets unmasked', $prompt);
        self::assertStringContainsString('threaten to block/report', $prompt);
        self::assertStringContainsString('IMPORTANT MULTILINGUAL RULE', $prompt);
        // …and the other editable block (tone) is still the shipped default.
        self::assertStringContainsString('TONE RECOMMENDATIONS', $prompt);
    }

    public function testToneOverrideReplacesOnlyItsBlockAndKeepsCore(): void
    {
        $prompt = $this->build($this->analyzer($this->providerWithOverride(
            'conversation_director_tone',
            '3. TONE: always be furious and blunt.',
        )));

        // Operator override is used, shipped tone palette gone.
        self::assertStringContainsString('always be furious and blunt', $prompt);
        self::assertStringNotContainsString('Victim discovers the message', $prompt);
        // CORE and the other editable block (strategy default) intact.
        self::assertStringContainsString('re-asking is the #1 way our bot gets unmasked', $prompt);
        self::assertStringContainsString('Do not default to a bank-wire objective', $prompt);
    }
}

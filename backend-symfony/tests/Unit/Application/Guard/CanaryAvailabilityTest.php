<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Guard;

use App\Application\Guard\CanaryAvailability;
use PHPUnit\Framework\TestCase;

final class CanaryAvailabilityTest extends TestCase
{
    // ─── mock (the demo) — never available, whatever the key strings are ───

    public function testMockProviderIsNeverAvailable(): void
    {
        // The public demo runs LLM_PROVIDER=mock with LLM_API_KEY=not-needed-in-demo-mode.
        self::assertFalse((new CanaryAvailability('mock', 'not-needed-in-demo-mode', ''))->isConfigured());
    }

    public function testMockProviderIsUnavailableEvenWithARealKey(): void
    {
        self::assertFalse((new CanaryAvailability('mock', 'sk-live-realkey', 'sk-ant-realkey'))->isConfigured());
    }

    // ─── ollama (local model) — no key needed, always available ───

    public function testOllamaIsAvailableWithoutAnyKey(): void
    {
        self::assertTrue((new CanaryAvailability('ollama', '', ''))->isConfigured());
    }

    // ─── anthropic — needs ANTHROPIC_API_KEY ───

    public function testAnthropicIsAvailableWithItsKey(): void
    {
        self::assertTrue((new CanaryAvailability('anthropic', '', 'sk-ant-realkey'))->isConfigured());
    }

    public function testAnthropicIsUnavailableWithoutItsKey(): void
    {
        // The OpenAI key being present must NOT make anthropic look configured.
        self::assertFalse((new CanaryAvailability('anthropic', 'sk-live-realkey', ''))->isConfigured());
    }

    // ─── openai (the default) — needs LLM_API_KEY ───

    public function testOpenAiIsAvailableWithItsKey(): void
    {
        self::assertTrue((new CanaryAvailability('openai', 'sk-live-realkey', ''))->isConfigured());
    }

    public function testOpenAiIsUnavailableWithoutItsKey(): void
    {
        self::assertFalse((new CanaryAvailability('openai', '', ''))->isConfigured());
    }

    public function testOpenAiIsUnavailableWithTheShippedPlaceholder(): void
    {
        self::assertFalse((new CanaryAvailability('openai', 'sk-your-api-key-here', ''))->isConfigured());
    }

    public function testRejectsProviderPrefixedPlaceholderSamples(): void
    {
        // Documented samples that carry the provider prefix must still be recognised as placeholders.
        self::assertFalse((new CanaryAvailability('openai', 'sk-proj-your-key-here', ''))->isConfigured());
        self::assertFalse((new CanaryAvailability('anthropic', '', 'sk-ant-your-key-here'))->isConfigured());
    }

    public function testRealProviderPrefixedKeysAreAccepted(): void
    {
        // The substring markers must NOT reject a genuine sk-proj-/sk-ant- key.
        self::assertTrue((new CanaryAvailability('openai', 'sk-proj-abc123realtoken', ''))->isConfigured());
        self::assertTrue((new CanaryAvailability('anthropic', '', 'sk-ant-abc123realtoken'))->isConfigured());
    }

    public function testWhitespaceOnlyKeyIsUnavailable(): void
    {
        self::assertFalse((new CanaryAvailability('openai', "  \n\t  ", ''))->isConfigured());
    }

    public function testKeyIsTrimmedBeforeJudging(): void
    {
        self::assertTrue((new CanaryAvailability('openai', '  sk-live-realkey  ', ''))->isConfigured());
    }

    // ─── unknown / empty provider falls back to openai (mirrors the compiler pass default) ───

    public function testEmptyProviderFallsBackToOpenAiAndNeedsTheKey(): void
    {
        self::assertTrue((new CanaryAvailability('', 'sk-live-realkey', ''))->isConfigured());
        self::assertFalse((new CanaryAvailability('', '', ''))->isConfigured());
    }

    public function testProviderMatchIsCaseInsensitive(): void
    {
        self::assertFalse((new CanaryAvailability('MOCK', 'sk-live-realkey', ''))->isConfigured());
        self::assertTrue((new CanaryAvailability('Ollama', '', ''))->isConfigured());
    }
}

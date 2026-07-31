<?php

declare(strict_types=1);

namespace App\Tests\Integration\Application\LLM;

use App\Application\LLM\ConversationAnalyzer;
use App\Application\LLM\Prompt\PromptProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guards the director override + canary integrity against a silent DI regression. `$prompts` is a
 * nullable ctor arg (test idiom), so if its llm.yaml wiring were removed the container would NOT
 * error — the director strategy/tone overrides AND the canary's candidate injection would silently
 * degrade to the shipped defaults. This asserts the production container actually injects a
 * PromptProvider, turning that silent failure into a loud one.
 */
final class ConversationAnalyzerWiringTest extends KernelTestCase
{
    public function testConversationAnalyzerIsWiredWithThePromptProvider(): void
    {
        self::bootKernel();
        $analyzer = static::getContainer()->get(ConversationAnalyzer::class);
        self::assertInstanceOf(ConversationAnalyzer::class, $analyzer);

        $prompts = (new \ReflectionProperty($analyzer, 'prompts'))->getValue($analyzer);
        self::assertInstanceOf(PromptProvider::class, $prompts, 'ConversationAnalyzer.$prompts must be wired (llm.yaml) — else director overrides and the canary silently no-op');
    }
}

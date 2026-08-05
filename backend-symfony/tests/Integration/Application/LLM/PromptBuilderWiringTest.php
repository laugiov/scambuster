<?php

declare(strict_types=1);

namespace App\Tests\Integration\Application\LLM;

use App\Application\LLM\Prompt\PromptProvider;
use App\Application\LLM\PromptBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guards the operator-override + canary integrity against a silent DI regression. `$prompts` is a
 * nullable ctor arg (test idiom), so if its llm.yaml wiring were removed the container would NOT
 * error — overrides AND the canary's candidate injection would silently degrade to the shipped
 * rules. This asserts the production container actually injects a PromptProvider, turning that
 * silent failure into a loud one.
 */
final class PromptBuilderWiringTest extends KernelTestCase
{
    public function testPromptBuilderIsWiredWithThePromptProvider(): void
    {
        self::bootKernel();
        $builder = static::getContainer()->get(PromptBuilder::class);
        self::assertInstanceOf(PromptBuilder::class, $builder);

        $prompts = (new \ReflectionProperty($builder, 'prompts'))->getValue($builder);
        self::assertInstanceOf(PromptProvider::class, $prompts, 'PromptBuilder.$prompts must be wired (llm.yaml) — else overrides and the canary silently no-op');
    }
}

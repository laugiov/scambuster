<?php

declare(strict_types=1);

namespace App\Tests\Integration\DependencyInjection;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verify that the 4 feature-flag parameters are defined,
 * resolved from their corresponding env vars, and bound as autowire
 * scalars in the DI container.
 *
 * This test is the Red side of a Red→Green TDD cycle: it asserts
 * a contract that doesn't yet hold. The Green commit will add the
 * 4 parameters to config/packages/llm.yaml and the 4 bind entries to
 * config/services.yaml, after which this test passes.
 *
 * The parameter names match the convention of `llm.validation_enabled`
 * (already present in llm.yaml).
 */
final class ValidatorFeatureFlagBindingTest extends KernelTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function parameterNamesProvider(): array
    {
        return [
            'signature strip enabled'           => ['reply.signature_strip.enabled'],
            'validator context enabled'         => ['reply.validator.context.enabled'],
            'validator structured correction'   => ['reply.validator.structured_correction'],
            'generator patch mode'              => ['reply.generator.patch_mode'],
        ];
    }

    /**
     * @dataProvider parameterNamesProvider
     */
    public function test_parameter_is_defined_and_resolves_to_bool(string $name): void
    {
        self::bootKernel();
        $container = static::getContainer();

        self::assertTrue(
            $container->hasParameter($name),
            "DI parameter '{$name}' must be defined in config/packages/llm.yaml",
        );

        $value = $container->getParameter($name);

        self::assertIsBool(
            $value,
            "DI parameter '{$name}' must resolve to a boolean (env(bool:...)), got " . get_debug_type($value),
        );
    }
}

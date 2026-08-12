<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM;

use App\Application\LLM\Port\LLMClientInterface;
use App\Infrastructure\LLM\Provider\AnthropicClient;
use App\Infrastructure\LLM\Provider\OllamaClient;
use App\Infrastructure\LLM\Provider\OpenAIClient;
use App\Infrastructure\LLM\Resilience\CircuitBreakerLLMClient;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Wraps the active LLM client in the circuit breaker.
 *
 * Runs AFTER {@see LLMProviderCompilerPass}, so it reads whatever concrete
 * provider the interface finally resolves to and decorates that. Only the real
 * network providers are wrapped: the deterministic test/demo doubles
 * (FakeLLMClient, MockLLMClient) never suffer transport failures, so a breaker
 * around them would add nothing and would make a shared-state (Redis) test suite
 * non-deterministic. Set LLM_CIRCUIT_BREAKER_ENABLED=0 to disable entirely.
 */
final class CircuitBreakerCompilerPass implements CompilerPassInterface
{
    /** Only genuine network providers are worth protecting. */
    private const WRAPPABLE_PROVIDERS = [
        OpenAIClient::class,
        AnthropicClient::class,
        OllamaClient::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        // Default enabled; only an explicit falsy value ("0", "false", "off")
        // disables it. An empty/unrecognised value keeps the breaker on rather than
        // silently disabling it.
        $enabled = filter_var($_ENV['LLM_CIRCUIT_BREAKER_ENABLED'] ?? true, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) ?? true;

        if (!$enabled) {
            return;
        }

        $interface = LLMClientInterface::class;

        if (!$container->hasAlias($interface)) {
            return;
        }

        $target = (string) $container->getAlias($interface);

        if ($target === CircuitBreakerLLMClient::class) {
            return; // already wrapped
        }

        $targetClass = $container->hasDefinition($target)
            ? ($container->getDefinition($target)->getClass() ?? $target)
            : $target;

        if (!\in_array($targetClass, self::WRAPPABLE_PROVIDERS, true)) {
            return; // test/demo double — nothing to protect
        }

        $container->findDefinition(CircuitBreakerLLMClient::class)
            ->setArgument('$inner', new Reference($target));

        $container->setAlias($interface, CircuitBreakerLLMClient::class)->setPublic(false);
    }
}

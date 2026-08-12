<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\LLM;

use App\Application\LLM\Port\LLMClientInterface;
use App\Infrastructure\LLM\CircuitBreakerCompilerPass;
use App\Infrastructure\LLM\Provider\AnthropicClient;
use App\Infrastructure\LLM\Provider\MockLLMClient;
use App\Infrastructure\LLM\Provider\OllamaClient;
use App\Infrastructure\LLM\Provider\OpenAIClient;
use App\Infrastructure\LLM\Resilience\CircuitBreakerLLMClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class CircuitBreakerCompilerPassTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['LLM_CIRCUIT_BREAKER_ENABLED']);
    }

    public function testWrapsARealNetworkProvider(): void
    {
        unset($_ENV['LLM_CIRCUIT_BREAKER_ENABLED']); // default: enabled
        $container = $this->containerAliasedTo(OpenAIClient::class);

        (new CircuitBreakerCompilerPass())->process($container);

        self::assertSame(
            CircuitBreakerLLMClient::class,
            (string) $container->getAlias(LLMClientInterface::class),
            'the port must resolve to the breaker',
        );

        $inner = $container->getDefinition(CircuitBreakerLLMClient::class)->getArgument('$inner');
        self::assertInstanceOf(Reference::class, $inner);
        self::assertSame(OpenAIClient::class, (string) $inner, 'the breaker must wrap the resolved provider');
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function networkProviders(): iterable
    {
        yield 'anthropic' => [AnthropicClient::class];
        yield 'ollama' => [OllamaClient::class];
    }

    /**
     * @dataProvider networkProviders
     *
     * @param class-string $providerClass
     */
    public function testWrapsEveryNetworkProvider(string $providerClass): void
    {
        unset($_ENV['LLM_CIRCUIT_BREAKER_ENABLED']);
        $container = $this->containerAliasedTo($providerClass);

        (new CircuitBreakerCompilerPass())->process($container);

        self::assertSame(CircuitBreakerLLMClient::class, (string) $container->getAlias(LLMClientInterface::class));
        self::assertSame(
            $providerClass,
            (string) $container->getDefinition(CircuitBreakerLLMClient::class)->getArgument('$inner'),
        );
    }

    public function testDoesNotWrapTheOfflineMockProvider(): void
    {
        unset($_ENV['LLM_CIRCUIT_BREAKER_ENABLED']);
        $container = $this->containerAliasedTo(MockLLMClient::class);

        (new CircuitBreakerCompilerPass())->process($container);

        self::assertSame(MockLLMClient::class, (string) $container->getAlias(LLMClientInterface::class));
    }

    public function testIsIdempotentWhenAlreadyWrapped(): void
    {
        unset($_ENV['LLM_CIRCUIT_BREAKER_ENABLED']);
        $container = $this->containerAliasedTo(OpenAIClient::class);
        $pass = new CircuitBreakerCompilerPass();

        $pass->process($container);
        $pass->process($container); // second run must be a no-op, not a self-wrap

        self::assertSame(CircuitBreakerLLMClient::class, (string) $container->getAlias(LLMClientInterface::class));
        self::assertSame(
            OpenAIClient::class,
            (string) $container->getDefinition(CircuitBreakerLLMClient::class)->getArgument('$inner'),
            'the breaker must still wrap the provider, never itself',
        );
    }

    public function testNoAliasIsANoOp(): void
    {
        unset($_ENV['LLM_CIRCUIT_BREAKER_ENABLED']);
        $container = new ContainerBuilder();
        $container->setDefinition(
            CircuitBreakerLLMClient::class,
            (new Definition(CircuitBreakerLLMClient::class))->setArgument('$inner', new Reference(OpenAIClient::class)),
        );

        (new CircuitBreakerCompilerPass())->process($container);

        self::assertFalse($container->hasAlias(LLMClientInterface::class));
    }

    public function testLeavesTestOrDemoDoublesUnwrapped(): void
    {
        unset($_ENV['LLM_CIRCUIT_BREAKER_ENABLED']);
        $container = $this->containerAliasedTo('App\\Tests\\Fake\\FakeLLMClient');

        (new CircuitBreakerCompilerPass())->process($container);

        self::assertSame(
            'App\\Tests\\Fake\\FakeLLMClient',
            (string) $container->getAlias(LLMClientInterface::class),
            'a non-network double must not be wrapped',
        );
    }

    public function testRespectsTheKillSwitch(): void
    {
        $_ENV['LLM_CIRCUIT_BREAKER_ENABLED'] = '0';
        $container = $this->containerAliasedTo(OpenAIClient::class);

        (new CircuitBreakerCompilerPass())->process($container);

        self::assertSame(
            OpenAIClient::class,
            (string) $container->getAlias(LLMClientInterface::class),
            'the disabled breaker must leave the wiring untouched',
        );
    }

    private function containerAliasedTo(string $providerClass): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition($providerClass, new Definition($providerClass));
        $container->setDefinition(
            CircuitBreakerLLMClient::class,
            (new Definition(CircuitBreakerLLMClient::class))->setArgument('$inner', new Reference($providerClass)),
        );
        $container->setAlias(LLMClientInterface::class, $providerClass);

        return $container;
    }
}

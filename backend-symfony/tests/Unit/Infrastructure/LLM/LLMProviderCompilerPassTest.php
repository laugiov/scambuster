<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\LLM;

use App\Application\LLM\Port\EmbeddingClientInterface;
use App\Application\LLM\Port\LLMClientInterface;
use App\Infrastructure\LLM\LLMProviderCompilerPass;
use App\Infrastructure\LLM\Provider\AnthropicClient;
use App\Infrastructure\LLM\Provider\MockEmbeddingClient;
use App\Infrastructure\LLM\Provider\MockLLMClient;
use App\Infrastructure\LLM\Provider\OllamaClient;
use App\Infrastructure\LLM\Provider\OllamaEmbeddingClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @group compiler-pass
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class LLMProviderCompilerPassTest extends TestCase
{
    private ?string $originalProvider = null;

    protected function setUp(): void
    {
        $this->originalProvider = $_ENV['LLM_PROVIDER'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->originalProvider !== null) {
            $_ENV['LLM_PROVIDER'] = $this->originalProvider;
        } else {
            unset($_ENV['LLM_PROVIDER']);
        }
    }

    public function testOpenAIProviderDoesNotOverrideAlias(): void
    {
        $_ENV['LLM_PROVIDER'] = 'openai';

        $container = new ContainerBuilder();
        $pass = new LLMProviderCompilerPass();
        $pass->process($container);

        // openai is the default alias set in llm.yaml, so the pass should NOT set any alias
        $this->assertFalse($container->hasAlias(LLMClientInterface::class));
    }

    public function testDefaultProviderDoesNotOverrideAlias(): void
    {
        unset($_ENV['LLM_PROVIDER']);

        $container = new ContainerBuilder();
        $pass = new LLMProviderCompilerPass();
        $pass->process($container);

        // Default is openai, so no alias override
        $this->assertFalse($container->hasAlias(LLMClientInterface::class));
    }

    public function testMockProviderSetsCorrectAlias(): void
    {
        $_ENV['LLM_PROVIDER'] = 'mock';

        $container = new ContainerBuilder();
        $container->register(MockLLMClient::class);

        $pass = new LLMProviderCompilerPass();
        $pass->process($container);

        $this->assertTrue($container->hasAlias(LLMClientInterface::class));
        $alias = $container->getAlias(LLMClientInterface::class);
        $this->assertSame(MockLLMClient::class, (string) $alias);
    }

    public function testAnthropicProviderSetsCorrectAlias(): void
    {
        $_ENV['LLM_PROVIDER'] = 'anthropic';

        $container = new ContainerBuilder();
        $container->register(AnthropicClient::class);

        $pass = new LLMProviderCompilerPass();
        $pass->process($container);

        $this->assertTrue($container->hasAlias(LLMClientInterface::class));
        $alias = $container->getAlias(LLMClientInterface::class);
        $this->assertSame(AnthropicClient::class, (string) $alias);
    }

    public function testOllamaProviderSetsCorrectAlias(): void
    {
        $_ENV['LLM_PROVIDER'] = 'ollama';

        $container = new ContainerBuilder();
        $container->register(OllamaClient::class);

        $pass = new LLMProviderCompilerPass();
        $pass->process($container);

        $this->assertTrue($container->hasAlias(LLMClientInterface::class));
        $alias = $container->getAlias(LLMClientInterface::class);
        $this->assertSame(OllamaClient::class, (string) $alias);
    }

    public function testOllamaAlsoSwapsTheEmbeddingClient(): void
    {
        $_ENV['LLM_PROVIDER'] = 'ollama';

        $container = new ContainerBuilder();
        $container->register(OllamaClient::class);
        $container->register(OllamaEmbeddingClient::class);

        (new LLMProviderCompilerPass())->process($container);

        $this->assertTrue($container->hasAlias(EmbeddingClientInterface::class));
        $this->assertSame(OllamaEmbeddingClient::class, (string) $container->getAlias(EmbeddingClientInterface::class));
    }

    public function testMockAlsoSwapsTheEmbeddingClient(): void
    {
        $_ENV['LLM_PROVIDER'] = 'mock';

        $container = new ContainerBuilder();
        $container->register(MockLLMClient::class);
        $container->register(MockEmbeddingClient::class);

        (new LLMProviderCompilerPass())->process($container);

        $this->assertTrue($container->hasAlias(EmbeddingClientInterface::class));
        $this->assertSame(MockEmbeddingClient::class, (string) $container->getAlias(EmbeddingClientInterface::class));
    }

    public function testAnthropicKeepsTheDefaultOpenAiEmbeddingClient(): void
    {
        // Anthropic has no embeddings API → the embedding alias is NOT overridden
        // (keeps the OpenAI-compatible default set in llm.yaml).
        $_ENV['LLM_PROVIDER'] = 'anthropic';

        $container = new ContainerBuilder();
        $container->register(AnthropicClient::class);

        (new LLMProviderCompilerPass())->process($container);

        $this->assertFalse($container->hasAlias(EmbeddingClientInterface::class));
    }

    public function testOpenAiDoesNotOverrideTheEmbeddingClient(): void
    {
        $_ENV['LLM_PROVIDER'] = 'openai';

        $container = new ContainerBuilder();
        (new LLMProviderCompilerPass())->process($container);

        $this->assertFalse($container->hasAlias(EmbeddingClientInterface::class));
    }

    public function testInvalidProviderThrowsInvalidArgumentException(): void
    {
        $_ENV['LLM_PROVIDER'] = 'invalid_provider';

        $container = new ContainerBuilder();
        $pass = new LLMProviderCompilerPass();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown LLM_PROVIDER "invalid_provider"/');

        $pass->process($container);
    }

    public function testInvalidProviderExceptionListsSupportedProviders(): void
    {
        $_ENV['LLM_PROVIDER'] = 'mistral';

        $container = new ContainerBuilder();
        $pass = new LLMProviderCompilerPass();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/openai, anthropic, ollama, mock/');

        $pass->process($container);
    }
}

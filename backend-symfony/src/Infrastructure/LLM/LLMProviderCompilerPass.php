<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM;

use App\Application\LLM\Port\EmbeddingClientInterface;
use App\Application\LLM\Port\LLMClientInterface;
use App\Infrastructure\LLM\Provider\AnthropicClient;
use App\Infrastructure\LLM\Provider\MockEmbeddingClient;
use App\Infrastructure\LLM\Provider\MockLLMClient;
use App\Infrastructure\LLM\Provider\OllamaClient;
use App\Infrastructure\LLM\Provider\OllamaEmbeddingClient;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Swaps the LLM client (and the embedding client) based on LLM_PROVIDER env var.
 *
 * Supported providers:
 * - openai    : OpenAI API (GPT-4o, GPT-4o-mini) -- default, alias set in llm.yaml
 * - anthropic : Anthropic Messages API (Claude Haiku, Sonnet, Opus)
 * - ollama    : Local Ollama instance (llama3, mistral, phi3, etc.)
 * - mock      : Static responses, no external API calls (demo mode)
 */
final class LLMProviderCompilerPass implements CompilerPassInterface
{
    private const PROVIDER_MAP = [
        'anthropic' => AnthropicClient::class,
        'ollama' => OllamaClient::class,
        'mock' => MockLLMClient::class,
    ];

    /**
     * Embedding client per provider. Only providers with their own embeddings
     * override the default (OpenAI): Ollama runs local, mock stays offline.
     * Anthropic has no embeddings API, so it keeps the OpenAI-compatible default.
     */
    private const EMBEDDING_PROVIDER_MAP = [
        'ollama' => OllamaEmbeddingClient::class,
        'mock' => MockEmbeddingClient::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        $provider = $_ENV['LLM_PROVIDER'] ?? 'openai';

        // OpenAI is the default alias set in llm.yaml, no override needed
        if ($provider === 'openai') {
            return;
        }

        $serviceClass = self::PROVIDER_MAP[$provider] ?? null;

        if ($serviceClass === null) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown LLM_PROVIDER "%s". Supported: openai, anthropic, ollama, mock.',
                \is_string($provider) ? $provider : 'unknown'
            ));
        }

        $container->setAlias(
            LLMClientInterface::class,
            $serviceClass
        )->setPublic(false);

        // Swap the embedding client too where the provider has its own.
        $embeddingClass = self::EMBEDDING_PROVIDER_MAP[$provider] ?? null;

        if ($embeddingClass !== null) {
            $container->setAlias(
                EmbeddingClientInterface::class,
                $embeddingClass
            )->setPublic(false);
        }
    }
}

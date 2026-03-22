<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM;

use App\Application\LLM\Port\LLMClientInterface;
use App\Infrastructure\LLM\Provider\MockLLMClient;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Swaps the LLM client implementation based on LLM_PROVIDER env var.
 *
 * When LLM_PROVIDER=mock, all LLM calls use MockLLMClient (no external API).
 * This enables demo mode without an OpenAI API key.
 */
final class LLMProviderCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $provider = $_ENV['LLM_PROVIDER'] ?? 'openai';

        if ($provider === 'mock') {
            $container->setAlias(
                LLMClientInterface::class,
                MockLLMClient::class
            )->setPublic(false);
        }
    }
}

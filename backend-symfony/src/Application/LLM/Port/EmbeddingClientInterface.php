<?php

declare(strict_types=1);

namespace App\Application\LLM\Port;

/**
 * Provider-agnostic text-embedding port.
 *
 * Swapped by LLM_PROVIDER (see LLMProviderCompilerPass) so a fully local
 * deployment (Ollama/mock) never sends message text to an external provider to
 * be vectorised. Mirrors the LLMClientInterface hexagonal port for chat.
 */
interface EmbeddingClientInterface
{
    /**
     * Embed a batch of texts. Returns one vector per input, in the same order.
     *
     * @param array<int, string> $texts
     *
     * @throws \RuntimeException on provider/transport failure
     *
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts): array;

    /**
     * The model identifier that produced the vectors (recorded per row so
     * mixed-provider corpora can be told apart and re-embedded).
     */
    public function model(): string;
}

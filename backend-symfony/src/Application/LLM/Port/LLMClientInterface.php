<?php

declare(strict_types=1);

namespace App\Application\LLM\Port;

/**
 * Port interface for LLM providers (OpenAI, Mistral, Ollama, etc.)
 *
 * Follows hexagonal architecture: Application layer defines the port,
 * Infrastructure layer provides concrete implementations.
 */
interface LLMClientInterface
{
    /**
     * Send a chat completion request to the LLM provider
     *
     * @param array<int, array{role: string, content: string}> $messages
     *                                                                   Array of messages with 'role' (system|user|assistant) and 'content'
     * @param array<string, mixed>                             $options
     *                                                                   Provider-specific options (max_tokens, temperature, etc.)
     *
     * @throws \RuntimeException If API call fails or response is invalid
     *
     * @return string The assistant's response text
     */
    public function chat(array $messages, array $options = []): string;
}

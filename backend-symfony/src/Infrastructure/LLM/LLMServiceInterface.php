<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM;

/**
 * Interface for LLM (Large Language Model) services
 */
interface LLMServiceInterface
{
    /**
     * Generates a text completion
     *
     * @param string               $prompt  The prompt to send to the LLM
     * @param array<string, mixed> $options Options additionnelles (temperature, max_tokens, etc.)
     *
     * @return string The LLM response
     */
    public function complete(string $prompt, array $options = []): string;
}

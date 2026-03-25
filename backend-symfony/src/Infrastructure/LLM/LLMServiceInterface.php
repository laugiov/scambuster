<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM;

/**
 * Interface pour les services LLM (Large Language Models)
 */
interface LLMServiceInterface
{
    /**
     * Génère une complétion de texte
     *
     * @param string               $prompt  Le prompt à envoyer au LLM
     * @param array<string, mixed> $options Options additionnelles (temperature, max_tokens, etc.)
     *
     * @return string La réponse du LLM
     */
    public function complete(string $prompt, array $options = []): string;
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service LLM utilisant l'API OpenAI
 */
class OpenAIService implements LLMServiceInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly LoggerInterface $logger,
        // Derived from %llm.api_url% so this legacy path is not hardwired to
        // OpenAI's host (an OpenAI-compatible gateway / self-hosted endpoint
        // works). Required — DI always supplies the configured base URL.
        private readonly string $apiUrl,
    ) {
    }

    /** @param array<string, mixed> $options */
    public function complete(string $prompt, array $options = []): string
    {
        $temperature = $options['temperature'] ?? 0.7;
        $maxTokens = $options['max_tokens'] ?? 1024;

        try {
            $response = $this->httpClient->request('POST', rtrim($this->apiUrl, '/') . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens,
                    'user' => $this->buildSafetyIdentifier($options),
                ],
            ]);

            $data = $response->toArray();

            if (!isset($data['choices'][0]['message']['content'])) {
                throw new \RuntimeException('Invalid response from OpenAI API');
            }

            return $data['choices'][0]['message']['content'];
        } catch (\Throwable $e) {
            $this->logger->error('OpenAI API error', [
                'error' => $e->getMessage(),
                'prompt_length' => strlen($prompt),
            ]);

            throw new \RuntimeException('Failed to generate LLM completion: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Build the OpenAI safety identifier (`user` payload field).
     *
     * Mirrors {@see \App\Infrastructure\LLM\Provider\OpenAIClient::buildSafetyIdentifier}.
     * Default purpose is `preprod_generator` since this legacy service is only
     * wired to the preprod ConversationGenerator.
     *
     * @param array<string, mixed> $options
     */
    private function buildSafetyIdentifier(array $options): string
    {
        $convId = $options['conversation_id'] ?? null;

        if (is_string($convId) && $convId !== '') {
            return 'tenant_conv_' . hash('sha256', $convId);
        }

        $purposeRaw = $options['purpose'] ?? null;
        $purpose = is_string($purposeRaw) && $purposeRaw !== '' ? $purposeRaw : 'preprod_generator';
        $sanitised = preg_replace('/[^a-z0-9_]/i', '_', $purpose) ?? 'preprod_generator';

        return 'tenant_' . $sanitised;
    }
}

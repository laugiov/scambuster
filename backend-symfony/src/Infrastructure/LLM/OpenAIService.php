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
        private readonly LoggerInterface $logger
    ) {
    }

    public function complete(string $prompt, array $options = []): string
    {
        $temperature = $options['temperature'] ?? 0.7;
        $maxTokens = $options['max_tokens'] ?? 1024;

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
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
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM\Provider;

use App\Application\LLM\Port\LLMClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Ollama API client implementation.
 *
 * Handles communication with a local Ollama instance.
 * Supports llama3, mistral, phi3, and other Ollama-hosted models.
 *
 * Ollama API docs: https://github.com/ollama/ollama/blob/main/docs/api.md
 */
final class OllamaClient implements LLMClientInterface
{
    private const API_ENDPOINT = '/api/chat';
    private const DEFAULT_TEMPERATURE = 0.6;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl,
        private readonly string $model
    ) {
    }

    public function chat(array $messages, array $options = []): string
    {
        $startTime = microtime(true);
        $model = $options['model'] ?? $this->model;

        try {
            $payload = [
                'model' => $model,
                'messages' => $messages,
                'stream' => false,
                'options' => [
                    'temperature' => $options['temperature'] ?? self::DEFAULT_TEMPERATURE,
                ],
            ];

            $response = $this->httpClient->request('POST', $this->baseUrl . self::API_ENDPOINT, [
                'json' => $payload,
                'timeout' => 120,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                throw new \RuntimeException("Ollama API returned status {$statusCode}");
            }

            $data = $response->toArray();

            if (!isset($data['message']['content'])) {
                throw new \RuntimeException('Invalid Ollama API response: missing message.content');
            }

            $assistantText = $data['message']['content'];
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->logger->info('LLM chat completion', [
                'provider' => 'ollama',
                'model' => $model,
                'latency_ms' => $latencyMs,
                'input_messages' => count($messages),
                'output_length' => strlen($assistantText),
                'eval_count' => $data['eval_count'] ?? null,
                'prompt_eval_count' => $data['prompt_eval_count'] ?? null,
            ]);

            return $assistantText;
        } catch (\Throwable $e) {
            $this->logger->error('LLM chat completion failed', [
                'provider' => 'ollama',
                'model' => $model,
                'error' => $e->getMessage(),
                'latency_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ]);

            throw new \RuntimeException(
                "Ollama API call failed: {$e->getMessage()}",
                previous: $e
            );
        }
    }
}

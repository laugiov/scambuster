<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM\Provider;

use App\Application\LLM\Port\LLMClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenAI API client implementation
 *
 * Handles communication with OpenAI's chat completion endpoint.
 * Supports GPT-4o, GPT-4o-mini, and other chat models.
 */
final class OpenAIClient implements LLMClientInterface
{
    private const API_ENDPOINT = '/chat/completions';
    private const DEFAULT_TEMPERATURE = 0.6;
    private const DEFAULT_MAX_TOKENS = 400;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $model
    ) {
    }

    public function chat(array $messages, array $options = []): string
    {
        $startTime = microtime(true);

        try {
            $payload = [
                'model' => $options['model'] ?? $this->model, // Allow runtime override, fallback to injected default
                'messages' => $messages,
                'temperature' => $options['temperature'] ?? self::DEFAULT_TEMPERATURE,
                'max_tokens' => $options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS,
            ];

            $response = $this->httpClient->request('POST', $this->apiUrl . self::API_ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                throw new \RuntimeException("OpenAI API returned status {$statusCode}");
            }

            $data = $response->toArray();

            if (!isset($data['choices'][0]['message']['content'])) {
                throw new \RuntimeException('Invalid OpenAI API response: missing content');
            }

            $assistantText = $data['choices'][0]['message']['content'];
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->logger->info('LLM chat completion', [
                'provider' => 'openai',
                'model' => $payload['model'], // Log the actual model used (not just default)
                'latency_ms' => $latencyMs,
                'input_messages' => count($messages),
                'output_length' => strlen($assistantText),
                'usage' => $data['usage'] ?? null,
            ]);

            return $assistantText;
        } catch (\Throwable $e) {
            $this->logger->error('LLM chat completion failed', [
                'provider' => 'openai',
                'model' => $options['model'] ?? $this->model, // Log the actual model attempted
                'error' => $e->getMessage(),
                'latency_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ]);

            throw new \RuntimeException(
                "OpenAI API call failed: {$e->getMessage()}",
                previous: $e
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM\Provider;

use App\Application\LLM\Port\LLMClientInterface;
use App\Domain\LLM\Event\LlmCallCompletedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Ollama API client implementation.
 *
 * Handles communication with a local Ollama instance.
 * Supports llama3, mistral, phi3, and other Ollama-hosted models.
 *
 * Ollama API docs: https://github.com/ollama/ollama/blob/main/docs/api.md
 */
final readonly class OllamaClient implements LLMClientInterface
{
    private const API_ENDPOINT = '/api/chat';
    private const DEFAULT_TEMPERATURE = 0.6;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private EventDispatcherInterface $eventDispatcher,
        private string $baseUrl,
        private string $model
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

            $promptTokens = (int) ($data['prompt_eval_count'] ?? 0);
            $completionTokens = (int) ($data['eval_count'] ?? 0);

            $this->logger->info('LLM chat completion', [
                'provider' => 'ollama',
                'model' => $model,
                'latency_ms' => $latencyMs,
                'input_messages' => count($messages),
                'output_length' => strlen((string) $assistantText),
                'eval_count' => $completionTokens,
                'prompt_eval_count' => $promptTokens,
            ]);

            $this->eventDispatcher->dispatch(new LlmCallCompletedEvent(
                provider: 'ollama',
                model: $model,
                purpose: $options['purpose'] ?? 'unknown',
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                conversationId: $options['conversation_id'] ?? null
            ));

            return $assistantText;
        } catch (\Throwable $e) {
            $this->logger->error('LLM chat completion failed', [
                'provider' => 'ollama',
                'model' => $model,
                'error' => $e->getMessage(),
                'latency_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ]);

            throw new \RuntimeException("Ollama API call failed: {$e->getMessage()}", $e->getCode(), previous: $e);
        }
    }
}

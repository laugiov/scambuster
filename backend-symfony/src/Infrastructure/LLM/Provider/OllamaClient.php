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

            // Structured output: Ollama takes `format: json` at the TOP LEVEL (not
            // inside `options`). Honored only for the json_object mode; the prompts
            // themselves already instruct JSON, which Ollama requires to avoid
            // runaway whitespace generation.
            if (($options['response_format'] ?? null) === ['type' => 'json_object']) {
                $payload['format'] = 'json';
            }

            // Deterministic sampling (opt-in) goes inside `options`.
            if (isset($options['seed']) && is_int($options['seed'])) {
                $payload['options']['seed'] = $options['seed'];
            }

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

            /** @var string $eventModel */
            $eventModel = $model;
            /** @var string $eventPurpose */
            $eventPurpose = $options['purpose'] ?? 'unknown';
            /** @var string|null $eventConvId */
            $eventConvId = $options['conversation_id'] ?? null;
            $this->eventDispatcher->dispatch(new LlmCallCompletedEvent(
                provider: 'ollama',
                model: $eventModel,
                purpose: $eventPurpose,
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                conversationId: $eventConvId
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

<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM\Provider;

use App\Application\LLM\Port\LLMClientInterface;
use App\Domain\LLM\Event\LlmCallCompletedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenAI API client implementation.
 *
 * Handles communication with OpenAI's chat completion endpoint.
 * Supports GPT-4o, GPT-4o-mini, and other chat models.
 */
final readonly class OpenAIClient implements LLMClientInterface
{
    private const API_ENDPOINT = '/chat/completions';
    private const DEFAULT_TEMPERATURE = 0.6;
    private const DEFAULT_MAX_TOKENS = 400;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private EventDispatcherInterface $eventDispatcher,
        private string $apiUrl,
        private string $apiKey,
        private string $model
    ) {
    }

    public function chat(array $messages, array $options = []): string
    {
        $startTime = microtime(true);

        try {
            $payload = [
                'model' => $options['model'] ?? $this->model,
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
            $usage = $data['usage'] ?? [];

            $this->logger->info('LLM chat completion', [
                'provider' => 'openai',
                'model' => $payload['model'],
                'latency_ms' => $latencyMs,
                'input_messages' => count($messages),
                'output_length' => strlen((string) $assistantText),
                'usage' => $usage,
            ]);

            /** @var string $eventModel */
            $eventModel = $payload['model'];
            /** @var string $eventPurpose */
            $eventPurpose = $options['purpose'] ?? 'unknown';
            /** @var string|null $eventConvId */
            $eventConvId = $options['conversation_id'] ?? null;
            $this->eventDispatcher->dispatch(new LlmCallCompletedEvent(
                provider: 'openai',
                model: $eventModel,
                purpose: $eventPurpose,
                promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
                completionTokens: (int) ($usage['completion_tokens'] ?? 0),
                conversationId: $eventConvId
            ));

            return $assistantText;
        } catch (\Throwable $e) {
            $this->logger->error('LLM chat completion failed', [
                'provider' => 'openai',
                'model' => $options['model'] ?? $this->model,
                'error' => $e->getMessage(),
                'latency_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ]);

            throw new \RuntimeException("OpenAI API call failed: {$e->getMessage()}", $e->getCode(), previous: $e);
        }
    }
}

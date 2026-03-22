<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM\Provider;

use App\Application\LLM\Port\LLMClientInterface;
use App\Domain\LLM\Event\LlmCallCompletedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Anthropic Messages API client implementation.
 *
 * Handles communication with Anthropic's Messages endpoint.
 * Supports Claude Haiku, Sonnet, and Opus models.
 *
 * Key difference from OpenAI: system message is a separate parameter,
 * not part of the messages array.
 *
 * API docs: https://docs.anthropic.com/en/api/messages
 */
final class AnthropicClient implements LLMClientInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_TEMPERATURE = 0.6;
    private const DEFAULT_MAX_TOKENS = 1024;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly string $apiKey,
        private readonly string $model
    ) {
    }

    public function chat(array $messages, array $options = []): string
    {
        $startTime = microtime(true);
        $model = $options['model'] ?? $this->model;

        try {
            // Extract system message (Anthropic requires it as a separate parameter)
            $systemContent = null;
            $apiMessages = [];

            foreach ($messages as $msg) {
                if (($msg['role'] ?? '') === 'system') {
                    $systemContent = $msg['content'];
                } else {
                    $apiMessages[] = [
                        'role' => $msg['role'] ?? 'user',
                        'content' => $msg['content'] ?? '',
                    ];
                }
            }

            // Anthropic requires at least one user message
            if (empty($apiMessages)) {
                $apiMessages[] = ['role' => 'user', 'content' => $systemContent ?? ''];
                $systemContent = null;
            }

            $payload = [
                'model' => $model,
                'messages' => $apiMessages,
                'max_tokens' => $options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS,
                'temperature' => $options['temperature'] ?? self::DEFAULT_TEMPERATURE,
            ];

            if ($systemContent !== null) {
                $payload['system'] = $systemContent;
            }

            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 60,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                throw new \RuntimeException("Anthropic API returned status {$statusCode}");
            }

            $data = $response->toArray();

            if (!isset($data['content'][0]['text'])) {
                throw new \RuntimeException('Invalid Anthropic API response: missing content[0].text');
            }

            $assistantText = $data['content'][0]['text'];
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $usage = $data['usage'] ?? [];

            $this->logger->info('LLM chat completion', [
                'provider' => 'anthropic',
                'model' => $model,
                'latency_ms' => $latencyMs,
                'input_messages' => count($messages),
                'output_length' => strlen($assistantText),
                'input_tokens' => $usage['input_tokens'] ?? null,
                'output_tokens' => $usage['output_tokens'] ?? null,
            ]);

            $this->eventDispatcher->dispatch(new LlmCallCompletedEvent(
                provider: 'anthropic',
                model: $model,
                purpose: $options['purpose'] ?? 'unknown',
                promptTokens: (int) ($usage['input_tokens'] ?? 0),
                completionTokens: (int) ($usage['output_tokens'] ?? 0),
                conversationId: $options['conversation_id'] ?? null
            ));

            return $assistantText;
        } catch (\Throwable $e) {
            $this->logger->error('LLM chat completion failed', [
                'provider' => 'anthropic',
                'model' => $model,
                'error' => $e->getMessage(),
                'latency_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ]);

            throw new \RuntimeException(
                "Anthropic API call failed: {$e->getMessage()}",
                previous: $e
            );
        }
    }
}

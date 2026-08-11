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
            $model = $options['model'] ?? $this->model;
            $payload = [
                'model' => $model,
                'messages' => $messages,
                'temperature' => $options['temperature'] ?? self::DEFAULT_TEMPERATURE,
                'max_tokens' => $options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS,
                'user' => $this->buildSafetyIdentifier($options),
            ];

            // Structured output (opt-in via $options['response_format']). Only the
            // json_object mode is honored in v1. OpenAI rejects json_object unless
            // BOTH (a) the model supports it and (b) at least one message literally
            // contains the word "json". We guard both; otherwise we omit it and the
            // callers' regex JSON parsing stays the safety net. This keeps the
            // feature from ever regressing an operator-overridden prompt or a
            // custom LLM_MODEL into a 400. Best-effort, not a universal guarantee.
            if (
                ($options['response_format'] ?? null) === ['type' => 'json_object']
                && self::modelSupportsJsonObject(is_string($model) ? $model : '')
                && self::messagesMentionJson($messages)
            ) {
                $payload['response_format'] = ['type' => 'json_object'];
            }

            // Deterministic sampling (opt-in). Extension point for reproducible
            // evaluation runs; must never be set on the reply-generation path,
            // which has to stay diverse. No caller sets it today.
            if (isset($options['seed']) && is_int($options['seed'])) {
                $payload['seed'] = $options['seed'];
            }

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

    /**
     * Whether `response_format=json_object` can be applied to this model. Best-effort
     * and deliberately conservative: unknown/custom model names (e.g. self-hosted
     * OpenAI-compatible backends) and future model families fall through to plain
     * text rather than risking a 400. Bare `gpt-4` / `gpt-4-0613` do NOT support
     * json_object and are excluded.
     *
     * The reasoning (o-series) models are intentionally NOT listed: this client
     * always sends `temperature` and `max_tokens`, which those models reject
     * outright, so a structured call could never succeed through this client anyway.
     */
    private static function modelSupportsJsonObject(string $model): bool
    {
        return (bool) preg_match(
            '/^(gpt-4o|gpt-4\.1|gpt-4-turbo|gpt-4-(1106|0125)|gpt-3\.5-turbo)/i',
            $model
        );
    }

    /**
     * OpenAI returns 400 for `response_format=json_object` unless at least one
     * message literally contains "json" (case-insensitive). Guarding this keeps an
     * operator-overridden prompt (e.g. a custom reward_judge rubric) from
     * regressing to an API error — it simply keeps using text + regex parsing.
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    private static function messagesMentionJson(array $messages): bool
    {
        foreach ($messages as $message) {
            if (stripos($message['content'], 'json') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the OpenAI safety identifier (`user` payload field).
     *
     * Per OpenAI Usage Policies, every API call must include an opaque end-user
     * identifier so safety incidents are scoped to a single tenant rather than
     * the whole account. We derive it from $options:
     *   - `conversation_id` present → `tenant_conv_<sha256(conv_id)>`
     *   - else `purpose` present    → `tenant_<purpose>` (sanitised)
     *   - else                      → `tenant_unknown`
     *
     * The prefix is intentionally generic (no product name) since OpenAI
     * abuse-triage staff can read this value.
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
        $purpose = is_string($purposeRaw) && $purposeRaw !== '' ? $purposeRaw : 'unknown';
        $sanitised = preg_replace('/[^a-z0-9_]/i', '_', $purpose) ?? 'unknown';

        return 'tenant_' . $sanitised;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\LLM\Provider;

use App\Infrastructure\LLM\Provider\AnthropicClient;
use App\Infrastructure\LLM\Provider\OllamaClient;
use App\Infrastructure\LLM\Provider\OpenAIClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * WS3 — structured outputs (response_format / seed) passthrough, with the two
 * guards OpenAI actually requires: a json_object-capable model AND a message that
 * literally contains "json". When either guard fails the option is omitted and the
 * callers' regex JSON parsing stays the safety net (no API 400 regression).
 */
final class StructuredOutputTest extends TestCase
{
    private const JSON_OBJECT = ['type' => 'json_object'];

    // ---- OpenAI ----

    public function testOpenAiHonorsJsonObjectWhenModelCapableAndPromptMentionsJson(): void
    {
        $mock = $this->okOpenAi();
        $this->openAi($mock, 'gpt-4o-mini')->chat(
            [['role' => 'user', 'content' => 'Reply with json please']],
            ['response_format' => self::JSON_OBJECT],
        );

        self::assertSame(self::JSON_OBJECT, $this->body($mock)['response_format']);
    }

    public function testOpenAiOmitsJsonObjectWhenNoMessageMentionsJson(): void
    {
        $mock = $this->okOpenAi();
        $this->openAi($mock, 'gpt-4o-mini')->chat(
            [['role' => 'user', 'content' => 'Reply with a structured object']],
            ['response_format' => self::JSON_OBJECT],
        );

        self::assertArrayNotHasKey('response_format', $this->body($mock));
    }

    public function testOpenAiOmitsJsonObjectWhenModelIncapable(): void
    {
        $mock = $this->okOpenAi();
        // Custom/self-hosted model name → not on the allowlist → graceful skip.
        $this->openAi($mock, 'gpt-4o-mini')->chat(
            [['role' => 'user', 'content' => 'give me json']],
            ['model' => 'my-local-llm', 'response_format' => self::JSON_OBJECT],
        );

        self::assertArrayNotHasKey('response_format', $this->body($mock));
    }

    public function testOpenAiOmitsResponseFormatWhenNotJsonObject(): void
    {
        $mock = $this->okOpenAi();
        $this->openAi($mock, 'gpt-4o-mini')->chat(
            [['role' => 'user', 'content' => 'json please']],
            ['response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'x']]],
        );

        self::assertArrayNotHasKey('response_format', $this->body($mock));
    }

    public function testOpenAiExcludesBareGpt4Models(): void
    {
        foreach (['gpt-4', 'gpt-4-0613'] as $model) {
            $mock = $this->okOpenAi();
            $this->openAi($mock, $model)->chat(
                [['role' => 'user', 'content' => 'give me json']],
                ['response_format' => self::JSON_OBJECT],
            );

            self::assertArrayNotHasKey('response_format', $this->body($mock), $model);
        }
    }

    public function testOpenAiHonorsJsonObjectOnNonGpt4oCapableModel(): void
    {
        $mock = $this->okOpenAi();
        $this->openAi($mock, 'gpt-4-turbo')->chat(
            [['role' => 'user', 'content' => 'json']],
            ['response_format' => self::JSON_OBJECT],
        );

        self::assertSame(self::JSON_OBJECT, $this->body($mock)['response_format']);
    }

    public function testOpenAiDropsNonIntSeed(): void
    {
        $mock = $this->okOpenAi();
        $this->openAi($mock, 'gpt-4o-mini')->chat(
            [['role' => 'user', 'content' => 'hi']],
            ['seed' => '42'],
        );

        self::assertArrayNotHasKey('seed', $this->body($mock));
    }

    public function testOpenAiForwardsIntSeed(): void
    {
        $mock = $this->okOpenAi();
        $this->openAi($mock, 'gpt-4o-mini')->chat(
            [['role' => 'user', 'content' => 'hi']],
            ['seed' => 42],
        );

        self::assertSame(42, $this->body($mock)['seed']);
    }

    public function testOpenAiOmitsStructuredOptionsByDefault(): void
    {
        $mock = $this->okOpenAi();
        $this->openAi($mock, 'gpt-4o-mini')->chat([['role' => 'user', 'content' => 'json here']]);

        $body = $this->body($mock);
        self::assertArrayNotHasKey('response_format', $body);
        self::assertArrayNotHasKey('seed', $body);
    }

    // ---- Ollama ----

    public function testOllamaSetsTopLevelFormatJson(): void
    {
        $mock = $this->okOllama();
        $this->ollama($mock)->chat(
            [['role' => 'user', 'content' => 'json']],
            ['response_format' => self::JSON_OBJECT, 'temperature' => 0.3],
        );

        $body = $this->body($mock);
        self::assertSame('json', $body['format']);           // top-level, NOT in options
        self::assertSame(0.3, $body['options']['temperature']);
        self::assertArrayNotHasKey('format', $body['options']);
    }

    public function testOllamaOmitsFormatByDefaultAndForwardsSeed(): void
    {
        $mock = $this->okOllama();
        $this->ollama($mock)->chat([['role' => 'user', 'content' => 'hi']], ['seed' => 7]);

        $body = $this->body($mock);
        self::assertArrayNotHasKey('format', $body);
        self::assertSame(7, $body['options']['seed']);
    }

    // ---- Anthropic (no response_format support: must ignore gracefully) ----

    public function testAnthropicIgnoresStructuredOptionsWithoutCrashing(): void
    {
        $mock = new MockResponse((string) json_encode([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], JSON_THROW_ON_ERROR));

        $client = new AnthropicClient(new MockHttpClient($mock), new NullLogger(), new EventDispatcher(), 'sk-test', 'claude-sonnet-4-5');
        $result = $client->chat(
            [['role' => 'user', 'content' => 'json please']],
            ['response_format' => self::JSON_OBJECT, 'seed' => 1],
        );

        self::assertSame('ok', $result);
        $body = $this->body($mock);
        self::assertArrayNotHasKey('response_format', $body);
        self::assertArrayNotHasKey('seed', $body);
        self::assertArrayNotHasKey('format', $body);
    }

    // ---- helpers ----

    private function okOpenAi(): MockResponse
    {
        return new MockResponse((string) json_encode([
            'choices' => [['message' => ['content' => 'ok']]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ], JSON_THROW_ON_ERROR));
    }

    private function okOllama(): MockResponse
    {
        return new MockResponse((string) json_encode([
            'message' => ['role' => 'assistant', 'content' => 'ok'],
        ], JSON_THROW_ON_ERROR));
    }

    private function openAi(MockResponse $mock, string $model): OpenAIClient
    {
        return new OpenAIClient(new MockHttpClient($mock), new NullLogger(), new EventDispatcher(), 'https://api.openai.com/v1', 'sk-test', $model);
    }

    private function ollama(MockResponse $mock): OllamaClient
    {
        return new OllamaClient(new MockHttpClient($mock), new NullLogger(), new EventDispatcher(), 'http://localhost:11434', 'llama3');
    }

    /** @return array<string, mixed> */
    private function body(MockResponse $mock): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $mock->getRequestOptions()['body'], true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

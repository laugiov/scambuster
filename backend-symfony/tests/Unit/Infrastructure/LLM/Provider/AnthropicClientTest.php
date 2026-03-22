<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\LLM\Provider;

use App\Infrastructure\LLM\Provider\AnthropicClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AnthropicClientTest extends TestCase
{
    private const API_KEY = 'sk-ant-test-key';
    private const MODEL = 'claude-haiku-4-5-20251001';

    public function testChatReturnsAssistantContent(): void
    {
        $responseBody = json_encode([
            'content' => [
                ['type' => 'text', 'text' => 'Hello from Claude.'],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], JSON_THROW_ON_ERROR);

        $client = $this->createClient(new MockResponse($responseBody));

        $result = $client->chat([
            ['role' => 'user', 'content' => 'Hi'],
        ]);

        $this->assertSame('Hello from Claude.', $result);
    }

    public function testChatExtractsSystemMessageAsSeparateParameter(): void
    {
        $responseBody = json_encode([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $client = $this->createClient($mockResponse);

        $client->chat([
            ['role' => 'system', 'content' => 'You are a scambaiter.'],
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        $requestData = json_decode($mockResponse->getRequestOptions()['body'], true);

        // System message should be a separate parameter, not in messages array
        $this->assertSame('You are a scambaiter.', $requestData['system']);

        // Messages array should only contain user messages
        $this->assertCount(1, $requestData['messages']);
        $this->assertSame('user', $requestData['messages'][0]['role']);
        $this->assertSame('Hello', $requestData['messages'][0]['content']);
    }

    public function testChatSendsCorrectHeaders(): void
    {
        $responseBody = json_encode([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $client = $this->createClient($mockResponse);

        $client->chat([['role' => 'user', 'content' => 'Hi']]);

        $headers = $mockResponse->getRequestOptions()['headers'] ?? [];
        $headerMap = [];
        foreach ($headers as $header) {
            [$key, $value] = explode(': ', $header, 2);
            $headerMap[strtolower($key)] = $value;
        }

        $this->assertSame(self::API_KEY, $headerMap['x-api-key'] ?? '');
        $this->assertSame('2023-06-01', $headerMap['anthropic-version'] ?? '');
    }

    public function testChatWithoutSystemMessage(): void
    {
        $responseBody = json_encode([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $client = $this->createClient($mockResponse);

        $client->chat([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there'],
            ['role' => 'user', 'content' => 'How are you?'],
        ]);

        $requestData = json_decode($mockResponse->getRequestOptions()['body'], true);

        // No system key when no system message provided
        $this->assertArrayNotHasKey('system', $requestData);
        $this->assertCount(3, $requestData['messages']);
    }

    public function testChatAllowsModelOverride(): void
    {
        $responseBody = json_encode([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $client = $this->createClient($mockResponse);

        $client->chat(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'claude-sonnet-4-6-20250514']
        );

        $requestData = json_decode($mockResponse->getRequestOptions()['body'], true);
        $this->assertSame('claude-sonnet-4-6-20250514', $requestData['model']);
    }

    public function testChatThrowsOnHttpError(): void
    {
        $mockResponse = new MockResponse('Unauthorized', ['http_code' => 401]);

        $client = $this->createClient($mockResponse);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Anthropic API/');

        $client->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function testChatThrowsOnMissingContent(): void
    {
        $responseBody = json_encode([
            'content' => [],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
        ], JSON_THROW_ON_ERROR);

        $client = $this->createClient(new MockResponse($responseBody));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing content/');

        $client->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function testChatUsesCorrectApiEndpoint(): void
    {
        $responseBody = json_encode([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $client = $this->createClient($mockResponse);

        $client->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('POST', $mockResponse->getRequestMethod());
        $this->assertStringContainsString('api.anthropic.com/v1/messages', $mockResponse->getRequestUrl());
    }

    private function createClient(MockResponse $mockResponse): AnthropicClient
    {
        return new AnthropicClient(
            new MockHttpClient($mockResponse),
            new NullLogger(),
            new EventDispatcher(),
            self::API_KEY,
            self::MODEL
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\LLM\Provider;

use App\Infrastructure\LLM\Provider\OllamaClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OllamaClientTest extends TestCase
{
    private const BASE_URL = 'http://localhost:11434';
    private const MODEL = 'llama3';

    public function testChatReturnsAssistantContent(): void
    {
        $responseBody = json_encode([
            'message' => [
                'role' => 'assistant',
                'content' => 'Hello, I am Llama.',
            ],
            'eval_count' => 42,
            'prompt_eval_count' => 10,
        ], JSON_THROW_ON_ERROR);

        $client = $this->createClient(new MockResponse($responseBody));

        $result = $client->chat([
            ['role' => 'user', 'content' => 'Hi'],
        ]);

        $this->assertSame('Hello, I am Llama.', $result);
    }

    public function testChatSendsCorrectPayload(): void
    {
        $responseBody = json_encode([
            'message' => ['role' => 'assistant', 'content' => 'ok'],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $client = $this->createClient($mockResponse);

        $messages = [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $client->chat($messages, ['temperature' => 0.3]);

        $requestData = json_decode($mockResponse->getRequestOptions()['body'], true);

        $this->assertSame(self::MODEL, $requestData['model']);
        $this->assertSame($messages, $requestData['messages']);
        $this->assertFalse($requestData['stream']);
        $this->assertSame(0.3, $requestData['options']['temperature']);
    }

    public function testChatAllowsModelOverride(): void
    {
        $responseBody = json_encode([
            'message' => ['role' => 'assistant', 'content' => 'ok'],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $client = $this->createClient($mockResponse);

        $client->chat(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'mistral']
        );

        $requestData = json_decode($mockResponse->getRequestOptions()['body'], true);
        $this->assertSame('mistral', $requestData['model']);
    }

    public function testChatThrowsOnHttpError(): void
    {
        $mockResponse = new MockResponse('Internal Server Error', [
            'http_code' => 500,
        ]);

        $client = $this->createClient($mockResponse);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Ollama API/');

        $client->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function testChatThrowsOnMissingContent(): void
    {
        $responseBody = json_encode([
            'message' => ['role' => 'assistant'],
        ], JSON_THROW_ON_ERROR);

        $client = $this->createClient(new MockResponse($responseBody));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing message\.content/');

        $client->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function testChatSendsToCorrectEndpoint(): void
    {
        $responseBody = json_encode([
            'message' => ['role' => 'assistant', 'content' => 'ok'],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $client = $this->createClient($mockResponse);

        $client->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('POST', $mockResponse->getRequestMethod());
        $this->assertStringContainsString('/api/chat', $mockResponse->getRequestUrl());
        $this->assertStringContainsString(self::BASE_URL, $mockResponse->getRequestUrl());
    }

    private function createClient(MockResponse $mockResponse): OllamaClient
    {
        return new OllamaClient(
            new MockHttpClient($mockResponse),
            new NullLogger(),
            self::BASE_URL,
            self::MODEL
        );
    }
}

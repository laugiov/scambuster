<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\LLM\Provider;

use App\Infrastructure\LLM\Provider\OpenAIClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OpenAIClientTest extends TestCase
{
    private const API_URL = 'https://api.openai.com/v1';
    private const API_KEY = 'sk-test-key-12345';
    private const MODEL = 'gpt-4o-mini';

    public function testChatReturnsAssistantContentOn200(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'Hello from GPT!']],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ], JSON_THROW_ON_ERROR);

        $client = $this->createClient(new MockResponse($responseBody));

        $result = $client->chat([
            ['role' => 'user', 'content' => 'Hi'],
        ]);

        $this->assertSame('Hello from GPT!', $result);
    }

    public function testChatThrowsOnHttpError500(): void
    {
        $mockResponse = new MockResponse('Internal Server Error', [
            'http_code' => 500,
        ]);

        $client = $this->createClient($mockResponse);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/OpenAI API/');

        $client->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function testChatThrowsOnMissingContent(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['role' => 'assistant']],
            ],
        ], JSON_THROW_ON_ERROR);

        $client = $this->createClient(new MockResponse($responseBody));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing content|Invalid OpenAI API response/');

        $client->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function testChatSendsCorrectHeaders(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'ok']],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $client = $this->createClient($mockResponse);

        $client->chat([['role' => 'user', 'content' => 'Hi']]);

        $requestHeaders = $mockResponse->getRequestOptions()['headers'] ?? [];
        // Symfony HttpClient normalises headers as an array of strings
        $headerString = implode("\n", $requestHeaders);

        $this->assertStringContainsString('Bearer ' . self::API_KEY, $headerString);
        $this->assertStringContainsString('application/json', $headerString);
    }

    public function testChatSendsToCorrectEndpoint(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'ok']],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $client = $this->createClient($mockResponse);

        $client->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('POST', $mockResponse->getRequestMethod());
        $this->assertStringContainsString('/chat/completions', $mockResponse->getRequestUrl());
        $this->assertStringContainsString(self::API_URL, $mockResponse->getRequestUrl());
    }

    public function testChatUsesDefaultModel(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'ok']],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $client = $this->createClient($mockResponse);

        $client->chat([['role' => 'user', 'content' => 'Hi']]);

        $requestData = json_decode($mockResponse->getRequestOptions()['body'], true);
        $this->assertSame(self::MODEL, $requestData['model']);
    }

    public function testChatAllowsModelOverride(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'ok']],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $client = $this->createClient($mockResponse);

        $client->chat(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'gpt-4o']
        );

        $requestData = json_decode($mockResponse->getRequestOptions()['body'], true);
        $this->assertSame('gpt-4o', $requestData['model']);
    }

    public function testChatSendsCorrectPayload(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'ok']],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $client = $this->createClient($mockResponse);

        $messages = [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $client->chat($messages, ['temperature' => 0.3, 'max_tokens' => 200]);

        $requestData = json_decode($mockResponse->getRequestOptions()['body'], true);

        $this->assertSame(self::MODEL, $requestData['model']);
        $this->assertSame($messages, $requestData['messages']);
        $this->assertSame(0.3, $requestData['temperature']);
        $this->assertSame(200, $requestData['max_tokens']);
    }

    public function testChatUsesDefaultTemperatureAndMaxTokens(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'ok']],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $client = $this->createClient($mockResponse);

        $client->chat([['role' => 'user', 'content' => 'Hi']]);

        $requestData = json_decode($mockResponse->getRequestOptions()['body'], true);

        $this->assertSame(0.6, $requestData['temperature']);
        $this->assertSame(400, $requestData['max_tokens']);
    }

    public function testChatDispatchesLlmCallCompletedEvent(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'ok']],
            ],
            'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 8],
        ], JSON_THROW_ON_ERROR);

        $dispatcher = new EventDispatcher();
        $dispatched = false;
        $dispatcher->addListener(
            \App\Domain\LLM\Event\LlmCallCompletedEvent::class,
            function (\App\Domain\LLM\Event\LlmCallCompletedEvent $event) use (&$dispatched) {
                $dispatched = true;
                $this->assertSame('openai', $event->getProvider());
                $this->assertSame(self::MODEL, $event->getModel());
                $this->assertSame(15, $event->getPromptTokens());
                $this->assertSame(8, $event->getCompletionTokens());
            }
        );

        $client = new OpenAIClient(
            new MockHttpClient(new MockResponse($responseBody)),
            new NullLogger(),
            $dispatcher,
            self::API_URL,
            self::API_KEY,
            self::MODEL
        );

        $client->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertTrue($dispatched, 'LlmCallCompletedEvent should have been dispatched');
    }

    public function testChatIncludesConversationScopedSafetyIdentifier(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'ok']],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $client = $this->createClient($mockResponse);

        $convId = 'b1f2c3d4-5678-90ab-cdef-1234567890ab';
        $client->chat(
            [['role' => 'user', 'content' => 'Hi']],
            ['conversation_id' => $convId, 'purpose' => 'reply_generation'],
        );

        $requestData = json_decode($mockResponse->getRequestOptions()['body'], true);

        $expected = 'tenant_conv_' . hash('sha256', $convId);
        $this->assertSame($expected, $requestData['user']);
        $this->assertSame(64 + \strlen('tenant_conv_'), \strlen((string) $requestData['user']));
    }

    public function testChatFallsBackToPurposeWhenNoConversationId(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'ok']],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $client = $this->createClient($mockResponse);

        $client->chat(
            [['role' => 'user', 'content' => 'Hi']],
            ['purpose' => 'classification'],
        );

        $requestData = json_decode($mockResponse->getRequestOptions()['body'], true);
        $this->assertSame('tenant_classification', $requestData['user']);
    }

    public function testChatFallsBackToUnknownWhenNoIdentifierProvided(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'ok']],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $client = $this->createClient($mockResponse);

        $client->chat([['role' => 'user', 'content' => 'Hi']]);

        $requestData = json_decode($mockResponse->getRequestOptions()['body'], true);
        $this->assertSame('tenant_unknown', $requestData['user']);
    }

    public function testChatSanitisesPurposeSpecialCharacters(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'ok']],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $client = $this->createClient($mockResponse);

        $client->chat(
            [['role' => 'user', 'content' => 'Hi']],
            ['purpose' => 'campaign/profile.v2'],
        );

        $requestData = json_decode($mockResponse->getRequestOptions()['body'], true);
        $this->assertSame('tenant_campaign_profile_v2', $requestData['user']);
    }

    private function createClient(MockResponse $mockResponse): OpenAIClient
    {
        return new OpenAIClient(
            new MockHttpClient($mockResponse),
            new NullLogger(),
            new EventDispatcher(),
            self::API_URL,
            self::API_KEY,
            self::MODEL
        );
    }
}

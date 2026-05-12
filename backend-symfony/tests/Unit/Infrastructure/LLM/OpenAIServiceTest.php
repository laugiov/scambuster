<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\LLM;

use App\Infrastructure\LLM\OpenAIService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit tests for OpenAIService.
 *
 * Covers:
 * - Successful completion with correct response extraction
 * - Correct headers (Authorization Bearer)
 * - Correct request body (model, messages, temperature, max_tokens)
 * - HTTP error handling (throws RuntimeException)
 * - Missing content in response
 * - Custom options (temperature, max_tokens)
 * - API endpoint URL
 */
final class OpenAIServiceTest extends TestCase
{
    private const API_KEY = 'sk-test-key-12345';
    private const MODEL = 'gpt-4o-mini';

    public function testCompleteReturnsAssistantContent(): void
    {
        $responseBody = json_encode([
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Hello! I am a helpful assistant.',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $service = $this->createService(new MockResponse($responseBody));

        $result = $service->complete('Say hello');

        $this->assertSame('Hello! I am a helpful assistant.', $result);
    }

    public function testCompleteSendsCorrectAuthorizationHeader(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'ok']],
            ],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $service = $this->createService($mockResponse);

        $service->complete('Test prompt');

        $headers = $mockResponse->getRequestOptions()['headers'] ?? [];
        $headerMap = [];

        foreach ($headers as $header) {
            if (str_contains($header, ': ')) {
                [$key, $value] = explode(': ', $header, 2);
                $headerMap[strtolower($key)] = $value;
            }
        }

        $this->assertSame('Bearer ' . self::API_KEY, $headerMap['authorization'] ?? '');
    }

    public function testCompleteSendsCorrectRequestBody(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'ok']],
            ],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $service = $this->createService($mockResponse);

        $service->complete('Test prompt', ['temperature' => 0.5, 'max_tokens' => 2048]);

        $requestBody = json_decode($mockResponse->getRequestOptions()['body'], true);

        $this->assertSame(self::MODEL, $requestBody['model']);
        $this->assertSame('user', $requestBody['messages'][0]['role']);
        $this->assertSame('Test prompt', $requestBody['messages'][0]['content']);
        $this->assertSame(0.5, $requestBody['temperature']);
        $this->assertSame(2048, $requestBody['max_tokens']);
    }

    public function testCompleteUsesDefaultOptions(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'ok']],
            ],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $service = $this->createService($mockResponse);

        $service->complete('Test');

        $requestBody = json_decode($mockResponse->getRequestOptions()['body'], true);

        $this->assertSame(0.7, $requestBody['temperature']);
        $this->assertSame(1024, $requestBody['max_tokens']);
    }

    public function testCompleteThrowsOnHttpError(): void
    {
        $mockResponse = new MockResponse('Internal Server Error', ['http_code' => 500]);
        $service = $this->createService($mockResponse);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to generate LLM completion/');

        $service->complete('Test');
    }

    public function testCompleteThrowsOnMissingContent(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['role' => 'assistant']],
            ],
        ], JSON_THROW_ON_ERROR);

        $service = $this->createService(new MockResponse($responseBody));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid response from OpenAI API|Failed to generate/');

        $service->complete('Test');
    }

    public function testCompleteThrowsOnEmptyChoices(): void
    {
        $responseBody = json_encode([
            'choices' => [],
        ], JSON_THROW_ON_ERROR);

        $service = $this->createService(new MockResponse($responseBody));

        $this->expectException(\RuntimeException::class);

        $service->complete('Test');
    }

    public function testCompleteUsesCorrectEndpoint(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'ok']],
            ],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $service = $this->createService($mockResponse);

        $service->complete('Test');

        $this->assertSame('POST', $mockResponse->getRequestMethod());
        $this->assertStringContainsString('api.openai.com/v1/chat/completions', $mockResponse->getRequestUrl());
    }

    public function testCompleteThrowsOnNetworkError(): void
    {
        $mockResponse = new MockResponse('', ['error' => 'Connection refused']);
        $service = $this->createService($mockResponse);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to generate LLM completion/');

        $service->complete('Test');
    }

    public function testCompleteIncludesConversationScopedSafetyIdentifier(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'ok']],
            ],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $service = $this->createService($mockResponse);

        $convId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $service->complete('Test', ['conversation_id' => $convId]);

        $requestBody = json_decode($mockResponse->getRequestOptions()['body'], true);
        $this->assertSame('tenant_conv_' . hash('sha256', $convId), $requestBody['user']);
    }

    public function testCompleteFallsBackToDefaultPurpose(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => 'ok']],
            ],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody);
        $service = $this->createService($mockResponse);

        $service->complete('Test');

        $requestBody = json_decode($mockResponse->getRequestOptions()['body'], true);
        $this->assertSame('tenant_preprod_generator', $requestBody['user']);
    }

    private function createService(MockResponse $mockResponse): OpenAIService
    {
        return new OpenAIService(
            new MockHttpClient($mockResponse),
            self::API_KEY,
            self::MODEL,
            new NullLogger()
        );
    }
}

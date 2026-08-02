<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\EmbeddingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Unit tests for EmbeddingService
 *
 * Tests embedding generation, batch processing, error handling,
 * and text truncation via mocked HttpClient.
 */
class EmbeddingServiceTest extends TestCase
{
    private function createService(HttpClientInterface $httpClient): EmbeddingService
    {
        return new EmbeddingService(
            $httpClient,
            'test-api-key',
            new NullLogger(),
        );
    }

    private function createMockResponse(array $data): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn($data);

        return $response;
    }

    // ------------------------------------------------------------------ //
    //  Model & Dimensions
    // ------------------------------------------------------------------ //

    public function testGetModelReturnsExpectedModel(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $service = $this->createService($httpClient);

        $this->assertSame('text-embedding-3-small', $service->getModel());
    }

    public function testGetDimensionsReturns1536(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $service = $this->createService($httpClient);

        $this->assertSame(1536, $service->getDimensions());
    }

    // ------------------------------------------------------------------ //
    //  generate (single text)
    // ------------------------------------------------------------------ //

    public function testGenerateReturnsSingleEmbedding(): void
    {
        $embedding = array_fill(0, 10, 0.1);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->willReturn($this->createMockResponse([
                'data' => [
                    ['embedding' => $embedding, 'index' => 0],
                ],
            ]));

        $service = $this->createService($httpClient);
        $result = $service->generate('Test text');

        $this->assertSame($embedding, $result);
    }

    public function testGenerateReturnsNullOnEmptyResponse(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturn($this->createMockResponse(['data' => []]));

        $service = $this->createService($httpClient);
        $result = $service->generate('Test text');

        $this->assertNull($result);
    }

    public function testGenerateReturnsNullOnApiError(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willThrowException(new \RuntimeException('API error'));

        $service = $this->createService($httpClient);
        $result = $service->generate('Test text');

        $this->assertNull($result);
    }

    // ------------------------------------------------------------------ //
    //  generateBatch
    // ------------------------------------------------------------------ //

    public function testGenerateBatchReturnsEmptyForEmptyInput(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $service = $this->createService($httpClient);
        $result = $service->generateBatch([]);

        $this->assertSame([], $result);
    }

    public function testGenerateBatchReturnsMultipleEmbeddings(): void
    {
        $embedding1 = array_fill(0, 5, 0.1);
        $embedding2 = array_fill(0, 5, 0.2);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->willReturn($this->createMockResponse([
                'data' => [
                    ['embedding' => $embedding2, 'index' => 1],
                    ['embedding' => $embedding1, 'index' => 0],
                ],
            ]));

        $service = $this->createService($httpClient);
        $result = $service->generateBatch(['text1', 'text2']);

        $this->assertCount(2, $result);
        // Should be sorted by index
        $this->assertSame($embedding1, $result[0]);
        $this->assertSame($embedding2, $result[1]);
    }

    public function testGenerateBatchReturnsEmptyOnException(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willThrowException(new \RuntimeException('Network error'));

        $service = $this->createService($httpClient);
        $result = $service->generateBatch(['text1']);

        $this->assertSame([], $result);
    }

    public function testGenerateBatchTruncatesLongText(): void
    {
        $longText = str_repeat('a', 50000);
        $embedding = array_fill(0, 5, 0.5);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function (array $options) {
                    $input = $options['json']['input'][0] ?? '';
                    // Should be truncated to 30000 chars
                    return mb_strlen($input) === 30000;
                })
            )
            ->willReturn($this->createMockResponse([
                'data' => [
                    ['embedding' => $embedding, 'index' => 0],
                ],
            ]));

        $service = $this->createService($httpClient);
        $result = $service->generateBatch([$longText]);

        $this->assertCount(1, $result);
    }

    public function testGenerateBatchPassesCorrectAuthHeader(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://api.openai.com/v1/embeddings',
                $this->callback(function (array $options) {
                    return ($options['headers']['Authorization'] ?? '') === 'Bearer test-api-key';
                })
            )
            ->willReturn($this->createMockResponse([
                'data' => [['embedding' => [0.1], 'index' => 0]],
            ]));

        $service = $this->createService($httpClient);
        $service->generateBatch(['test']);
    }
}

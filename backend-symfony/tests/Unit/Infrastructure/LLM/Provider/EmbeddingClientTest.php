<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\LLM\Provider;

use App\Infrastructure\LLM\Provider\MockEmbeddingClient;
use App\Infrastructure\LLM\Provider\OllamaEmbeddingClient;
use App\Infrastructure\LLM\Provider\OpenAIEmbeddingClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EmbeddingClientTest extends TestCase
{
    public function testOpenAiClientSendsBatchAndRestoresOrder(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'body' => json_decode($options['body'], true)];

            // Return rows out of order to prove index-based reordering.
            return new MockResponse((string) json_encode([
                'data' => [
                    ['index' => 1, 'embedding' => [0.4, 0.5]],
                    ['index' => 0, 'embedding' => [0.1, 0.2]],
                ],
            ]));
        });

        $client = new OpenAIEmbeddingClient($http, 'sk-test', 'https://gw.local/v1', 'text-embedding-3-small', 1536, new NullLogger());
        $vectors = $client->embed(['first', 'second']);

        self::assertSame('https://gw.local/v1/embeddings', $captured['url']);
        self::assertSame(['first', 'second'], $captured['body']['input']);
        self::assertSame('text-embedding-3-small', $captured['body']['model']);
        self::assertSame(1536, $captured['body']['dimensions']);
        self::assertSame([[0.1, 0.2], [0.4, 0.5]], $vectors, 'vectors must follow input order, not response order');
        self::assertSame('text-embedding-3-small', $client->model());
    }

    public function testOpenAiClientOmitsDimensionsWhenZero(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $m, string $u, array $o) use (&$captured): MockResponse {
            $captured = json_decode($o['body'], true);

            return new MockResponse((string) json_encode(['data' => [['index' => 0, 'embedding' => [1.0]]]]));
        });

        (new OpenAIEmbeddingClient($http, 'k', 'https://api.local/v1', 'model-x', 0, new NullLogger()))->embed(['t']);

        self::assertArrayNotHasKey('dimensions', $captured, 'a non-positive dimension must not force the field');
    }

    public function testOllamaClientLoopsPerTextAgainstLocalEndpoint(): void
    {
        $urls = [];
        $prompts = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$urls, &$prompts): MockResponse {
            $urls[] = $url;
            $prompts[] = json_decode($options['body'], true)['prompt'];

            return new MockResponse((string) json_encode(['embedding' => [0.9, 0.8, 0.7]]));
        });

        $client = new OllamaEmbeddingClient($http, 'http://ollama.local:11434', 'nomic-embed-text', new NullLogger());
        $vectors = $client->embed(['a', 'b']);

        self::assertCount(2, $vectors);
        self::assertSame([0.9, 0.8, 0.7], $vectors[0]);
        self::assertSame(['http://ollama.local:11434/api/embeddings', 'http://ollama.local:11434/api/embeddings'], $urls);
        self::assertSame(['a', 'b'], $prompts, 'one local call per text (no batch endpoint)');
        self::assertSame('nomic-embed-text', $client->model());
    }

    public function testOllamaClientThrowsOnMissingEmbedding(): void
    {
        // Error payload / model not pulled → no `embedding` key. Must fail the
        // batch (port contract) rather than yield an empty vector.
        $http = new MockHttpClient(fn (): MockResponse => new MockResponse((string) json_encode(['error' => 'model not found'])));
        $client = new OllamaEmbeddingClient($http, 'http://ollama.local:11434', 'nomic-embed-text', new NullLogger());

        $this->expectException(\RuntimeException::class);
        $client->embed(['text']);
    }

    public function testMockClientIsDeterministicAndOffline(): void
    {
        $client = new MockEmbeddingClient();

        $v1 = $client->embed(['hello']);
        $v2 = $client->embed(['hello']);
        $v3 = $client->embed(['world']);

        self::assertSame($v1, $v2, 'identical text embeds identically');
        self::assertNotSame($v1[0], $v3[0], 'different text embeds differently');
        self::assertCount(32, $v1[0]);
        foreach ($v1[0] as $component) {
            self::assertGreaterThanOrEqual(-1.0, $component);
            self::assertLessThanOrEqual(1.0, $component);
        }
    }

    public function testEmptyBatchShortCircuits(): void
    {
        $http = new MockHttpClient(fn (): MockResponse => throw new \LogicException('must not call HTTP for empty batch'));
        self::assertSame([], (new OpenAIEmbeddingClient($http, 'k', 'u', 'm', 1, new NullLogger()))->embed([]));
        self::assertSame([], (new OllamaEmbeddingClient($http, 'u', 'm', new NullLogger()))->embed([]));
    }
}

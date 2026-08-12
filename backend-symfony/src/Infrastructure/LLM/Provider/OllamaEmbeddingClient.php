<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM\Provider;

use App\Application\LLM\Port\EmbeddingClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Local embeddings via Ollama.
 *
 * Ollama has no batch embeddings endpoint: POST {baseUrl}/api/embeddings with
 * `{model, prompt}` returns `{embedding: [...]}` for a single text, so this loops
 * over the batch. The embedding dimension is whatever the local model emits
 * (recorded per row by EmbeddingService); no `dimensions` override exists.
 */
final readonly class OllamaEmbeddingClient implements EmbeddingClientInterface
{
    private const ENDPOINT = '/api/embeddings';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private string $model,
        private LoggerInterface $logger,
    ) {
    }

    public function model(): string
    {
        return $this->model;
    }

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $out = [];

        foreach (array_values($texts) as $text) {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/') . self::ENDPOINT, [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => ['model' => $this->model, 'prompt' => $text],
            ]);

            $data = $response->toArray();
            /** @var array<int, float> $vector */
            $vector = $data['embedding'] ?? [];
            $out[] = $vector;
        }

        $this->logger->debug('[OllamaEmbeddingClient] embedded batch', [
            'count' => \count($out),
            'model' => $this->model,
        ]);

        return $out;
    }
}

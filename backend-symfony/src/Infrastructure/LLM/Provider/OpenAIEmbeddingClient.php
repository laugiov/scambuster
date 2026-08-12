<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM\Provider;

use App\Application\LLM\Port\EmbeddingClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenAI (and OpenAI-compatible) embeddings.
 *
 * Batch endpoint: POST {apiUrl}/embeddings with `input` (array), `model`, and an
 * optional `dimensions`. Endpoint/model/dimensions come from config
 * (%llm.api_url%), so this works against any OpenAI-compatible host.
 */
final readonly class OpenAIEmbeddingClient implements EmbeddingClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,
        private string $apiUrl,
        private string $model,
        private int $dimensions,
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

        $payload = [
            'input' => array_values($texts),
            'model' => $this->model,
            'user' => 'tenant_embeddings',
        ];

        // OpenAI's v3 models accept a dimensions override; a value <= 0 means
        // "use the model default" (older/compatible endpoints reject the field).
        if ($this->dimensions > 0) {
            $payload['dimensions'] = $this->dimensions;
        }

        $response = $this->httpClient->request('POST', rtrim($this->apiUrl, '/') . '/embeddings', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $data = $response->toArray();

        /** @var array<int, array{embedding: array<int, float>, index?: int}> $rows */
        $rows = $data['data'] ?? [];

        // Restore input order (OpenAI returns an `index` per row).
        usort($rows, static fn (array $a, array $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        $out = [];

        foreach ($rows as $row) {
            $out[] = $row['embedding'];
        }

        $this->logger->debug('[OpenAIEmbeddingClient] embedded batch', [
            'count' => \count($out),
            'model' => $this->model,
        ]);

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Application\LLM;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Generates text embeddings via OpenAI embeddings API.
 *
 * Uses text-embedding-3-small (1536 dimensions, $0.02/1M tokens).
 * Called by the batch command app:generate-embeddings, not during ingestion.
 */
final class EmbeddingService
{
    private const MODEL = 'text-embedding-3-small';
    private const DIMENSIONS = 1536;
    private const API_URL = 'https://api.openai.com/v1/embeddings';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getModel(): string
    {
        return self::MODEL;
    }

    public function getDimensions(): int
    {
        return self::DIMENSIONS;
    }

    /**
     * Generate embedding for a single text.
     *
     * @return array<int, float>|null Embedding vector or null on failure
     */
    public function generate(string $text): ?array
    {
        $results = $this->generateBatch([$text]);

        return $results[0] ?? null;
    }

    /**
     * Generate embeddings for a batch of texts.
     *
     * @param array<int, string> $texts
     *
     * @return array<int, array<int, float>> Array of embedding vectors (same order as input)
     */
    public function generateBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        // Truncate very long texts to avoid token limits (8191 tokens ~ 32K chars)
        $truncated = array_map(fn (string $t): string => mb_substr($t, 0, 30000), $texts);

        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'input' => $truncated,
                    'model' => self::MODEL,
                    'dimensions' => self::DIMENSIONS,
                ],
            ]);

            $data = $response->toArray();

            /** @var array<int, array{embedding: array<int, float>, index: int}> $embeddings */
            $embeddings = $data['data'] ?? [];

            // Sort by index to maintain input order
            usort($embeddings, fn (array $a, array $b): int => $a['index'] <=> $b['index']);

            $result = [];

            foreach ($embeddings as $item) {
                $result[] = $item['embedding'];
            }

            $this->logger->debug('[EmbeddingService] Batch generated', [
                'count' => count($result),
                'model' => self::MODEL,
                'dimensions' => self::DIMENSIONS,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('[EmbeddingService] Failed to generate embeddings', [
                'error' => $e->getMessage(),
                'batch_size' => count($texts),
            ]);

            return [];
        }
    }
}

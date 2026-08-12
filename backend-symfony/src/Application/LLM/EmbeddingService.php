<?php

declare(strict_types=1);

namespace App\Application\LLM;

use App\Application\LLM\Port\EmbeddingClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates text embeddings through the provider-agnostic embedding port.
 *
 * Owns truncation, batching and fail-safe semantics; the actual HTTP is done by
 * the injected EmbeddingClientInterface, which LLM_PROVIDER swaps (OpenAI /
 * Ollama / mock) so a local deployment never ships text to an external provider.
 * Called by the batch command app:generate-embeddings, not during ingestion.
 */
final readonly class EmbeddingService
{
    // Longest text to embed; ~8191 tokens ≈ 32K chars for OpenAI's models.
    private const MAX_CHARS = 30000;

    public function __construct(
        private EmbeddingClientInterface $client,
        private LoggerInterface $logger,
        // Informational: the expected dimension for the configured model (OpenAI).
        // The actually-stored `dim` is the real returned vector length.
        private int $dimensions = 1536,
    ) {
    }

    public function getModel(): string
    {
        return $this->client->model();
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
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
        if ($texts === []) {
            return [];
        }

        $truncated = array_map(fn (string $t): string => mb_substr($t, 0, self::MAX_CHARS), $texts);

        try {
            $result = $this->client->embed($truncated);

            $this->logger->debug('[EmbeddingService] Batch generated', [
                'count' => \count($result),
                'model' => $this->client->model(),
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('[EmbeddingService] Failed to generate embeddings', [
                'error' => $e->getMessage(),
                'batch_size' => \count($texts),
            ]);

            return [];
        }
    }
}

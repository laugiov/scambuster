<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM\Provider;

use App\Application\LLM\Port\EmbeddingClientInterface;

/**
 * Deterministic offline embeddings for demo mode (LLM_PROVIDER=mock) and tests.
 *
 * No network. Produces a stable pseudo-vector per text (hash-seeded) so identical
 * texts embed identically and similarity search is exercisable without a key.
 * Not semantically meaningful — demo/eval plumbing only.
 */
final readonly class MockEmbeddingClient implements EmbeddingClientInterface
{
    private const DIMENSIONS = 32;

    public function model(): string
    {
        return 'mock-embedding';
    }

    public function embed(array $texts): array
    {
        $out = [];

        foreach (array_values($texts) as $text) {
            $out[] = $this->pseudoVector($text);
        }

        return $out;
    }

    /**
     * @return array<int, float>
     */
    private function pseudoVector(string $text): array
    {
        // Seed a small vector from the text hash; deterministic and in [-1, 1].
        $seed = crc32($text);
        $vector = [];

        for ($i = 0; $i < self::DIMENSIONS; ++$i) {
            $h = crc32($seed . ':' . $i);
            $vector[] = ($h % 2000) / 1000.0 - 1.0;
        }

        return $vector;
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Evaluation\Metric;

/**
 * Measures persona distinctness via TF-IDF cosine similarity variance.
 *
 * Groups replies by persona, builds TF-IDF vectors, computes pairwise
 * cosine similarity. Returns 1 - mean_similarity as distinctness.
 * Target: > 0.15.
 */
final class PersonaDistinctnessMetric implements MetricInterface
{
    public function compute(array $corpus): MetricResult
    {
        $byPersona = [];

        foreach ($corpus as $entry) {
            $persona = $entry['persona_code'] ?? 'unknown';
            $text = $entry['text'] ?? '';

            if ($text === '' || ($entry['fallback_used'] ?? false)) {
                continue;
            }

            $byPersona[$persona][] = $text;
        }

        if (count($byPersona) < 2) {
            return new MetricResult(
                'persona_distinctness',
                'persona',
                0.0,
                0.15,
                'gt',
                count($byPersona),
                'Need at least 2 personas with replies',
            );
        }

        $personaTexts = [];

        foreach ($byPersona as $persona => $texts) {
            $personaTexts[$persona] = implode(' ', $texts);
        }

        $tfidf = $this->buildTfIdf($personaTexts);
        $personas = array_keys($tfidf);
        $similarities = [];

        for ($i = 0, $n = count($personas); $i < $n; ++$i) {
            for ($j = $i + 1; $j < $n; ++$j) {
                $similarities[] = $this->cosineSimilarity($tfidf[$personas[$i]], $tfidf[$personas[$j]]);
            }
        }

        $meanSimilarity = !empty($similarities) ? array_sum($similarities) / count($similarities) : 0.0;
        $distinctness = 1.0 - $meanSimilarity;

        return new MetricResult(
            'persona_distinctness',
            'persona',
            round($distinctness, 4),
            0.15,
            'gt',
            count($personas),
            sprintf('%.2f mean cosine similarity across %d persona pairs', $meanSimilarity, count($similarities)),
            minSampleSize: 2,
        );
    }

    /**
     * Build a simple TF-IDF matrix where keys are personas and values are word-score maps.
     *
     * @param array<string, string> $documents persona => concatenated text
     *
     * @return array<string, array<string, float>>
     */
    private function buildTfIdf(array $documents): array
    {
        $docCount = count($documents);
        $allTerms = [];
        $termFreqs = [];

        foreach ($documents as $key => $text) {
            $words = $this->tokenize($text);
            $total = count($words);

            if ($total === 0) {
                $termFreqs[$key] = [];

                continue;
            }

            $counts = array_count_values($words);
            $termFreqs[$key] = [];

            foreach ($counts as $word => $count) {
                $termFreqs[$key][$word] = $count / $total;
                $allTerms[$word] = ($allTerms[$word] ?? 0) + 1;
            }
        }

        $result = [];

        foreach ($termFreqs as $key => $tf) {
            $result[$key] = [];

            foreach ($tf as $word => $freq) {
                $df = $allTerms[$word] ?? 1;
                $idf = log(($docCount + 1) / ($df + 1)) + 1;
                $result[$key][$word] = $freq * $idf;
            }
        }

        return $result;
    }

    /**
     * @param array<string, float> $a
     * @param array<string, float> $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $allKeys = array_unique(array_merge(array_keys($a), array_keys($b)));
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($allKeys as $key) {
            $va = $a[$key] ?? 0.0;
            $vb = $b[$key] ?? 0.0;
            $dot += $va * $vb;
            $normA += $va * $va;
            $normB += $vb * $vb;
        }

        $denom = sqrt($normA) * sqrt($normB);

        return $denom > 0 ? $dot / $denom : 0.0;
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text) ?? '';
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $words !== false ? array_values(array_filter($words, fn (string $w): bool => mb_strlen($w) > 2)) : [];
    }

    /**
     * Get the persona similarity matrix (for the markdown report).
     *
     * @param array<int, array<string, mixed>> $corpus
     *
     * @return array<string, array<string, float>>
     */
    public function getSimilarityMatrix(array $corpus): array
    {
        $byPersona = [];

        foreach ($corpus as $entry) {
            $persona = $entry['persona_code'] ?? 'unknown';
            $text = $entry['text'] ?? '';

            if ($text === '' || ($entry['fallback_used'] ?? false)) {
                continue;
            }

            $byPersona[$persona][] = $text;
        }

        if (count($byPersona) < 2) {
            return [];
        }

        $personaTexts = [];

        foreach ($byPersona as $persona => $texts) {
            $personaTexts[$persona] = implode(' ', $texts);
        }

        $tfidf = $this->buildTfIdf($personaTexts);
        $personas = array_keys($tfidf);
        $matrix = [];

        foreach ($personas as $p1) {
            foreach ($personas as $p2) {
                $matrix[$p1][$p2] = round($this->cosineSimilarity($tfidf[$p1], $tfidf[$p2]), 3);
            }
        }

        return $matrix;
    }
}

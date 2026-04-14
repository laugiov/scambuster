<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use App\Domain\Communication\Message;

final class FeatureExtractor
{
    /**
     * Extrait toutes les features d'un message.
     *
     * @return array{
     *   text: array{subject: string, body_normalized: string, simhash: string, ngrams: array<string>},
     *   infra: array{url_domains: array<string>, domain_ages: array<int>, dkim: bool, spf: bool, mx_provider: ?string},
     *   style: array{punct_ratio: float, avg_sentence_len: float, formality_score: float}
     * }
     */
    public function extract(Message $message): array
    {
        return [
            'text' => $this->extractTextFeatures($message),
            'infra' => $this->extractInfraFeatures($message),
            'style' => $this->extractStyleFeatures($message),
        ];
    }

    /**
     * Features textuelles : simhash + ngrams.
     *
     * @return array{subject: string, body_normalized: string, simhash: string, ngrams: array<string>}
     */
    private function extractTextFeatures(Message $message): array
    {
        $subject = $message->getSubject();
        $bodyHtml = $message->getBodyHtml();
        $bodyText = $message->getBodyText();

        // Utiliser HTML si disponible, sinon texte brut
        $body = ($bodyHtml !== null && $bodyHtml !== '') ? $this->stripHtml($bodyHtml) : ($bodyText ?: '');
        $bodyNormalized = $this->defangUrls($body);

        $fullText = $subject . ' ' . $bodyNormalized;

        return [
            'subject' => $subject ?? '',
            'body_normalized' => $bodyNormalized,
            'simhash' => $this->computeSimhash($fullText),
            'ngrams' => $this->extractNgrams($fullText, 3),
        ];
    }

    /**
     * Features infrastructures : âge domaine, DKIM, SPF, MX provider.
     *
     * @return array{url_domains: array<string>, domain_ages: array<int>, dkim: bool, spf: bool, mx_provider: ?string}
     */
    private function extractInfraFeatures(Message $message): array
    {
        $bodyText = $message->getBodyText();
        $urls = $this->extractUrls($bodyText);
        $domains = array_filter(array_map(fn ($url): ?string => parse_url((string) $url, PHP_URL_HOST) ?: null, $urls));

        // Retrieve metadata from headers JSONB
        $headers = $message->getHeaders();
        /** @var array<string, mixed> $auth */
        $auth = $headers['auth'] ?? [];
        $dkim = $auth['dkim'] ?? false;
        $spf = $auth['spf'] ?? false;

        return [
            'url_domains' => array_values($domains),
            'domain_ages' => $this->getDomainAges($domains), // Stub pour MVP
            'dkim' => (bool) $dkim,
            'spf' => (bool) $spf,
            'mx_provider' => $this->getMxProvider(),
        ];
    }

    /**
     * Style features: punctuation, sentence length, formality.
     *
     * @return array{punct_ratio: float, avg_sentence_len: float, formality_score: float}
     */
    private function extractStyleFeatures(Message $message): array
    {
        $text = $message->getBodyText();

        return [
            'punct_ratio' => $this->calculatePunctuationRatio($text),
            'avg_sentence_len' => $this->calculateAvgSentenceLength($text),
            'formality_score' => $this->calculateFormalityScore($text),
        ];
    }

    // === Private Helpers ===

    /**
     * Simhash (simplified version: MD5 hash of normalized tokens).
     */
    private function computeSimhash(string $text): string
    {
        $tokens = preg_split('/\s+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            $tokens = [];
        }
        $normalized = implode(' ', $tokens);

        return md5($normalized);
    }

    /**
     * Extraction de n-grammes (sliding window).
     *
     * @return array<string>
     */
    private function extractNgrams(string $text, int $n): array
    {
        $normalized = mb_strtolower(preg_replace('/\s+/', ' ', $text) ?? '');
        $len = mb_strlen($normalized);
        $ngrams = [];

        for ($i = 0; $i < $len - $n + 1; $i++) {
            $ngrams[] = mb_substr($normalized, $i, $n);
        }

        return array_unique($ngrams);
    }

    /**
     * Extrait les URLs depuis le texte.
     *
     * @return array<string>
     */
    private function extractUrls(string $text): array
    {
        $pattern = '/https?:\/\/[^\s<>"]+/i';
        preg_match_all($pattern, $text, $matches);

        return $matches[0];
    }

    /**
     * Strip HTML tags et scripts.
     */
    private function stripHtml(string $html): string
    {
        // Supprimer scripts et styles
        $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/i', '', $html) ?? $html;

        // Strip tags
        return strip_tags($html);
    }

    /**
     * Defang URLs (remplacer http:// par hxxp://).
     */
    private function defangUrls(string $text): string
    {
        $text = str_replace('http://', 'hxxp://', $text);

        return str_replace('https://', 'hxxps://', $text);
    }

    /**
     * Calcule les âges des domaines (stub pour MVP).
     * In production: integrate WHOIS API or Redis cache.
     *
     * @param array<string> $domains
     *
     * @return array<int> Âges en jours
     */
    private function getDomainAges(array $domains): array
    {
        // MVP : retourner âge fictif
        // TODO: Integrate WHOIS lookup or external cache
        return array_fill(0, count($domains), 365); // 1 year default
    }

    /** @phpstan-ignore return.unusedType */
    private function getMxProvider(): ?string
    {
        // MVP : stub
        // TODO: Parse Received headers for provider detection (Gmail, Office365, OVH, etc.)
        return null;
    }

    /**
     * Punctuation / total characters ratio.
     */
    private function calculatePunctuationRatio(string $text): float
    {
        if ($text === '') {
            return 0.0;
        }

        $punctCount = preg_match_all('/[!?.,;:]/', $text);

        return $punctCount / mb_strlen($text);
    }

    /**
     * Longueur moyenne des phrases.
     */
    private function calculateAvgSentenceLength(string $text): float
    {
        $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($sentences === false) {
            return 0.0;
        }

        if ($sentences === []) {
            return 0.0;
        }

        $totalChars = array_sum(array_map('mb_strlen', $sentences));

        return $totalChars / count($sentences);
    }

    /**
     * Formality score (ratio of long words >6 letters).
     */
    private function calculateFormalityScore(string $text): float
    {
        $words = preg_split('/\s+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false) {
            return 0.0;
        }

        if ($words === []) {
            return 0.0;
        }

        $longWords = array_filter($words, fn ($w): bool => mb_strlen((string) $w) > 6);

        return count($longWords) / count($words);
    }
}

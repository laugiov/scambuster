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
        $subject = $message->getSubject() ?? '';
        $bodyHtml = $message->getBodyHtml() ?? '';
        $bodyText = $message->getBodyText() ?? '';

        // Utiliser HTML si disponible, sinon texte brut
        $body = $bodyHtml !== '' ? $this->stripHtml($bodyHtml) : $bodyText;
        $bodyNormalized = $this->defangUrls($body);

        $fullText = $subject . ' ' . $bodyNormalized;

        return [
            'subject' => $subject,
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
        $bodyText = $message->getBodyText() ?? '';
        $urls = $this->extractUrls($bodyText);
        $domains = array_filter(array_map(fn ($url) => parse_url($url, PHP_URL_HOST), $urls));

        // Récupérer métadonnées depuis headers JSONB
        $headers = $message->getHeaders() ?? [];
        $dkim = $headers['auth']['dkim'] ?? false;
        $spf = $headers['auth']['spf'] ?? false;

        return [
            'url_domains' => array_values($domains),
            'domain_ages' => $this->getDomainAges($domains), // Stub pour MVP
            'dkim' => (bool) $dkim,
            'spf' => (bool) $spf,
            'mx_provider' => $this->getMxProvider($message),
        ];
    }

    /**
     * Features de style : ponctuation, longueur phrases, formalité.
     *
     * @return array{punct_ratio: float, avg_sentence_len: float, formality_score: float}
     */
    private function extractStyleFeatures(Message $message): array
    {
        $text = $message->getBodyText() ?? '';

        return [
            'punct_ratio' => $this->calculatePunctuationRatio($text),
            'avg_sentence_len' => $this->calculateAvgSentenceLength($text),
            'formality_score' => $this->calculateFormalityScore($text),
        ];
    }

    // === Helpers Privés ===

    /**
     * Simhash (version simplifiée : hash MD5 des tokens normalisés).
     */
    private function computeSimhash(string $text): string
    {
        $tokens = preg_split('/\s+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $normalized = implode(' ', $tokens ?? []);

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

        return $matches[0] ?? [];
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
        $text = str_replace('https://', 'hxxps://', $text);

        return $text;
    }

    /**
     * Calcule les âges des domaines (stub pour MVP).
     * En production : intégrer WHOIS API ou cache Redis.
     *
     * @param array<string> $domains
     *
     * @return array<int> Âges en jours
     */
    private function getDomainAges(array $domains): array
    {
        // MVP : retourner âge fictif
        // TODO : Intégrer WHOIS lookup ou cache externe
        return array_fill(0, count($domains), 365); // 1 an par défaut
    }

    /**
     * Détecte le provider MX (stub pour MVP).
     */
    private function getMxProvider(Message $message): ?string
    {
        // MVP : stub
        // TODO : Parser headers Received, détecter provider (Gmail, Office365, OVH, etc.)
        return null;
    }

    /**
     * Ratio ponctuation / caractères totaux.
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

        if (count($sentences ?? []) === 0) {
            return 0.0;
        }

        $totalChars = array_sum(array_map('mb_strlen', $sentences ?? []));

        return $totalChars / count($sentences);
    }

    /**
     * Score de formalité (ratio mots longs >6 lettres).
     */
    private function calculateFormalityScore(string $text): float
    {
        $words = preg_split('/\s+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        if (count($words ?? []) === 0) {
            return 0.0;
        }

        $longWords = array_filter($words ?? [], fn ($w) => mb_strlen($w) > 6);

        return count($longWords) / count($words);
    }
}

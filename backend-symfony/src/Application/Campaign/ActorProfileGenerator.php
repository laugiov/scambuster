<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Generates threat actor fingerprints from campaign message corpus.
 *
 * Extracts style_dna (writing patterns) and infra_dna (technical indicators)
 * using deterministic statistical analysis — no LLM call needed.
 */
final readonly class ActorProfileGenerator
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Generate actor profile for a campaign with sufficient data.
     *
     * @return array{style_dna: array<string, mixed>, infra_dna: array<string, mixed>}|null
     */
    public function generateForCampaign(string $campaignId): ?array
    {
        // Get all inbound messages from the campaign's conversations
        $messages = $this->connection->fetchAllAssociative('
            SELECT m.body_text, m.lang_detect, m.headers
            FROM message m
            JOIN message_campaign mc ON mc.msg_id = m.msg_id
            WHERE mc.campaign_id = :campaignId AND m.direction = (SELECT dir_id FROM lkp_direction WHERE code = \'in\') AND m.body_text IS NOT NULL
        ', ['campaignId' => $campaignId]);

        if (count($messages) < 3) {
            $this->logger->debug('[ActorProfileGenerator] Insufficient messages for campaign', [
                'campaign_id' => $campaignId,
                'message_count' => count($messages),
            ]);

            return null;
        }

        /** @var array<int, array{body_text: string, lang_detect: string|null, headers: string|null}> $messages */
        $bodies = array_map(fn (array $m): string => $m['body_text'], $messages);
        $allText = implode(' ', $bodies);

        $styleDna = $this->computeStyleDna($bodies, $allText, $messages);
        $infraDna = $this->computeInfraDna($campaignId);

        $this->logger->info('[ActorProfileGenerator] Profile generated', [
            'campaign_id' => $campaignId,
            'messages_analyzed' => count($messages),
            'vocabulary_size' => $styleDna['vocabulary_size'],
        ]);

        return [
            'style_dna' => $styleDna,
            'infra_dna' => $infraDna,
        ];
    }

    /**
     * @param array<int, string>               $bodies
     * @param array<int, array<string, mixed>> $messages
     *
     * @return array<string, mixed>
     */
    private function computeStyleDna(array $bodies, string $allText, array $messages): array
    {
        // Sentence analysis
        $sentences = preg_split('/[.!?]+/', $allText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sentenceLengths = array_map(fn (string $s): int => str_word_count(trim($s)), $sentences);
        $avgSentenceLength = $sentenceLengths === [] ? 0 : round(array_sum($sentenceLengths) / count($sentenceLengths), 1);

        // Vocabulary analysis
        $words = preg_split('/\s+/', mb_strtolower($allText), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $wordFreq = array_count_values($words);
        arsort($wordFreq);
        $vocabularySize = count($wordFreq);
        $top20Words = array_slice(array_keys($wordFreq), 0, 20);

        // Word length
        $wordLengths = array_map(fn (string $w): int => mb_strlen($w), $words);
        $avgWordLength = $wordLengths === [] ? 0 : round(array_sum($wordLengths) / count($wordLengths), 1);

        // Language distribution
        $languages = [];

        foreach ($messages as $m) {
            $lang = \is_string($m['lang_detect']) ? $m['lang_detect'] : 'unknown';
            $languages[$lang] = ($languages[$lang] ?? 0) + 1;
        }

        return [
            'avg_sentence_length' => $avgSentenceLength,
            'vocabulary_size' => $vocabularySize,
            'avg_word_length' => $avgWordLength,
            'top_20_words' => $top20Words,
            'total_messages' => count($bodies),
            'total_words' => count($words),
            'language_distribution' => $languages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function computeInfraDna(string $campaignId): array
    {
        // Extract IOC-based infrastructure from campaign's indicators
        $iocs = $this->connection->fetchAllAssociative('
            SELECT i.type, i.value_norm
            FROM indicator i
            JOIN observed_ioc oi ON oi.indicator_id = i.indicator_id
            JOIN message m ON oi.msg_id = m.msg_id
            JOIN message_campaign mc ON mc.msg_id = m.msg_id
            WHERE mc.campaign_id = :campaignId
        ', ['campaignId' => $campaignId]);

        $domains = [];
        $emailProviders = [];
        $paymentMethods = [];
        $tlds = [];

        foreach ($iocs as $ioc) {
            /** @var array{type: string, value_norm: string} $ioc */
            $type = $ioc['type'];
            $value = $ioc['value_norm'];

            switch ($type) {
                case 'domain':
                case 'url':
                    $domains[] = $value;
                    $host = parse_url($value, PHP_URL_HOST);
                    $hostStr = \is_string($host) ? $host : $value;
                    $parts = explode('.', $hostStr);
                    $tld = end($parts);
                    $tlds[] = $tld;

                    break;
                case 'email':
                    $parts = explode('@', $value);

                    if (isset($parts[1])) {
                        $emailProviders[] = $parts[1];
                    }

                    break;
                case 'iban':
                case 'bic':
                case 'crypto_wallet':
                    $paymentMethods[] = $type;

                    break;
            }
        }

        return [
            'unique_domains' => array_values(array_unique($domains)),
            'email_providers' => array_values(array_unique($emailProviders)),
            'payment_methods' => array_values(array_unique($paymentMethods)),
            'tlds' => array_values(array_unique($tlds)),
            'ioc_count' => count($iocs),
        ];
    }
}

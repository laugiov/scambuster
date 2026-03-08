<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use App\Domain\CampaignRadar\Campaign;
use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class ClusteringService
{
    private const SIMILARITY_THRESHOLD = 0.75;
    private const MIN_CLUSTER_SIZE = 3;

    public function __construct(
        private readonly FeatureExtractor $featureExtractor,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Assigne un message à une campagne existante ou crée une nouvelle campagne.
     *
     * @param Message         $message           Message à clusteriser
     * @param array<Campaign> $existingCampaigns Campagnes existantes (status=shadow ou promoted)
     *
     * @return array{campaign_id: string|null, confidence: float, features: array<string, mixed>}
     */
    public function assignCampaign(Message $message, array $existingCampaigns): array
    {
        // 1. Extraire features du message
        $features = $this->featureExtractor->extract($message);
        $embedding = $this->computeEmbedding($features);

        // 2. Trouver la campagne la plus proche
        $bestMatch = $this->findBestMatch($embedding, $existingCampaigns);

        // 3. Si similarity ≥ threshold → assigner à campagne existante
        if ($bestMatch !== null && $bestMatch['similarity'] >= self::SIMILARITY_THRESHOLD) {
            $this->logger->info('Message assigned to existing campaign', [
                'campaign_id' => $bestMatch['campaign_id'],
                'similarity' => $bestMatch['similarity'],
            ]);

            return [
                'campaign_id' => $bestMatch['campaign_id'],
                'confidence' => $bestMatch['similarity'],
                'features' => $features,
            ];
        }

        // 4. Sinon → créer nouvelle campagne (sera en attente jusqu'à MIN_CLUSTER_SIZE)
        $this->logger->info('Creating new campaign for message');

        // Retourner campaign_id null pour signaler "nouvelle campagne à créer"
        return [
            'campaign_id' => null,
            'confidence' => 1.0,
            'features' => $features,
        ];
    }

    /**
     * Calcule un embedding (vecteur de features normalisé).
     *
     * @param array{text: array, infra: array, style: array} $features
     *
     * @return array<string, mixed> Embedding avec hashes text/infra/style
     */
    private function computeEmbedding(array $features): array
    {
        // Embedding simple : hashes de chaque catégorie de features
        $textHash = $features['text']['simhash'];
        $infraHash = md5(json_encode($features['infra']['url_domains']));
        $styleHash = md5(json_encode([
            $features['style']['punct_ratio'],
            $features['style']['formality_score'],
        ]));

        return [
            'text' => $textHash,
            'infra' => $infraHash,
            'style' => $styleHash,
            'features' => $features, // Garder features complètes pour calcul similarité
        ];
    }

    /**
     * Trouve la campagne la plus similaire.
     *
     * @param array<string, mixed> $embedding
     * @param array<Campaign>      $campaigns
     *
     * @return array{campaign_id: string, similarity: float}|null
     */
    private function findBestMatch(array $embedding, array $campaigns): ?array
    {
        if (count($campaigns) === 0) {
            return null;
        }

        $bestSimilarity = 0.0;
        $bestCampaignId = null;

        foreach ($campaigns as $campaign) {
            $similarity = $this->computeSimilarity($embedding, $campaign);

            if ($similarity > $bestSimilarity) {
                $bestSimilarity = $similarity;
                $bestCampaignId = $campaign->getCampaignId()->toRfc4122();
            }
        }

        if ($bestCampaignId === null) {
            return null;
        }

        return [
            'campaign_id' => $bestCampaignId,
            'similarity' => $bestSimilarity,
        ];
    }

    /**
     * Calcule similarité entre embedding message et campagne.
     * Utilise Jaccard similarity sur les tokens de mots (MVP approach).
     *
     * @param array<string, mixed> $embedding
     *
     * @return float Similarité [0,1], 1 = identique
     */
    private function computeSimilarity(array $embedding, Campaign $campaign): float
    {
        // MVP: Use Jaccard similarity on word tokens from subject + body
        // More robust than MD5 hash which has avalanche effect

        if (!isset($embedding['features']['text'])) {
            return 0.0;
        }

        $messageText = $embedding['features']['text']['subject'] . ' ' . $embedding['features']['text']['body_normalized'];
        $messageTokens = $this->tokenize($messageText);

        // Get a representative message from the campaign to compare against
        $campaignText = $this->getCampaignRepresentativeText($campaign);

        if ($campaignText === null) {
            return 0.0;
        }

        $campaignTokens = $this->tokenize($campaignText);

        // Compute Jaccard similarity
        return $this->jaccardSimilarity($messageTokens, $campaignTokens);
    }

    /**
     * Récupère un texte représentatif de la campagne (premier message ou centroid).
     */
    private function getCampaignRepresentativeText(Campaign $campaign): ?string
    {
        // Query the first message in the campaign to get its text features
        $sql = <<<SQL
            SELECT (features->'text'->>'subject') as subject,
                   (features->'text'->>'body_normalized') as body
            FROM message_campaign
            WHERE campaign_id = :campaign_id
              AND features IS NOT NULL
            LIMIT 1
        SQL;

        $result = $this->em->getConnection()->fetchAssociative($sql, [
            'campaign_id' => $campaign->getCampaignId()->toRfc4122(),
        ]);

        if (!$result || !isset($result['subject']) || !isset($result['body'])) {
            return null;
        }

        return ($result['subject'] ?? '') . ' ' . ($result['body'] ?? '');
    }

    /**
     * Tokenize text into normalized word tokens.
     *
     * @return array<string>
     */
    private function tokenize(string $text): array
    {
        $tokens = preg_split('/\s+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_unique($tokens ?? []);
    }

    /**
     * Compute Jaccard similarity between two token sets.
     *
     * @param array<string> $set1
     * @param array<string> $set2
     *
     * @return float Similarity [0, 1]
     */
    private function jaccardSimilarity(array $set1, array $set2): float
    {
        if (count($set1) === 0 && count($set2) === 0) {
            return 1.0;
        }

        if (count($set1) === 0 || count($set2) === 0) {
            return 0.0;
        }

        $intersection = count(array_intersect($set1, $set2));
        $union = count(array_unique(array_merge($set1, $set2)));

        if ($union === 0) {
            return 0.0;
        }

        return round($intersection / $union, 4);
    }

    /**
     * Calcule Hamming distance entre deux hashes hexadécimaux.
     *
     * @param string $hash1 Hash MD5 (32 chars hex)
     * @param string $hash2 Hash MD5 (32 chars hex)
     *
     * @return int Distance [0, 128]
     */
    private function hammingDistance(string $hash1, string $hash2): int
    {
        if (strlen($hash1) !== 32 || strlen($hash2) !== 32) {
            $this->logger->warning('Invalid hash length for Hamming distance', [
                'hash1_len' => strlen($hash1),
                'hash2_len' => strlen($hash2),
            ]);

            return 128; // Max distance = totalement différent
        }

        $distance = 0;

        // Comparer bit par bit
        for ($i = 0; $i < 32; $i++) {
            $nibble1 = hexdec($hash1[$i]);
            $nibble2 = hexdec($hash2[$i]);

            // XOR + popcount (nombre de bits à 1)
            $xor = $nibble1 ^ $nibble2;
            $distance += $this->popcount($xor);
        }

        return $distance;
    }

    /**
     * Population count (nombre de bits à 1).
     *
     * @param int $n Nombre [0, 15] (nibble 4 bits)
     *
     * @return int Nombre de bits à 1
     */
    private function popcount(int $n): int
    {
        static $lookup = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];

        return $lookup[$n & 0xF];
    }
}

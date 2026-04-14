<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use App\Domain\CampaignRadar\Campaign;
use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class ClusteringService
{
    private const SIMILARITY_THRESHOLD = 0.75;

    public function __construct(
        private FeatureExtractor $featureExtractor,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Assigns a message to an existing campaign or creates a new campaign.
     *
     * @param Message         $message           Message to cluster
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

        // 3. If similarity >= threshold -> assign to existing campaign
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

        // 4. Otherwise -> create new campaign (will be pending until MIN_CLUSTER_SIZE)
        $this->logger->info('Creating new campaign for message');

        // Return null campaign_id to signal "new campaign to create"
        return [
            'campaign_id' => null,
            'confidence' => 1.0,
            'features' => $features,
        ];
    }

    /**
     * Computes an embedding (normalized feature vector).
     *
     * @param array{text: array<string, mixed>, infra: array<string, mixed>, style: array<string, mixed>} $features
     *
     * @return array<string, mixed> Embedding avec hashes text/infra/style
     */
    private function computeEmbedding(array $features): array
    {
        // Simple embedding: hashes of each feature category
        $textHash = $features['text']['simhash'];
        $infraJson = json_encode($features['infra']['url_domains']);

        if ($infraJson === false) {
            $infraJson = '';
        }
        $infraHash = md5($infraJson);
        $styleJson = json_encode([
            $features['style']['punct_ratio'],
            $features['style']['formality_score'],
        ]);

        if ($styleJson === false) {
            $styleJson = '';
        }
        $styleHash = md5($styleJson);

        return [
            'text' => $textHash,
            'infra' => $infraHash,
            'style' => $styleHash,
            'features' => $features, // Keep complete features for similarity computation
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
        if ($campaigns === []) {
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
     * Computes similarity between message and campaign embedding.
     * Utilise Jaccard similarity sur les tokens de mots (MVP approach).
     *
     * @param array<string, mixed> $embedding
     *
     * @return float Similarity [0,1], 1 = identical
     */
    private function computeSimilarity(array $embedding, Campaign $campaign): float
    {
        // MVP: Use Jaccard similarity on word tokens from subject + body
        // More robust than MD5 hash which has avalanche effect

        /** @var array<string, array<string, mixed>>|null $features */
        $features = $embedding['features'] ?? null;

        if (!is_array($features) || !isset($features['text'])) {
            return 0.0;
        }

        /** @var array{subject: string, body_normalized: string} $textFeatures */
        $textFeatures = $features['text'];
        $messageText = $textFeatures['subject'] . ' ' . $textFeatures['body_normalized'];
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
     * Retrieves a representative text for the campaign (first message or centroid).
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

        /** @var string $subject */
        $subject = $result['subject'];
        /** @var string $body */
        $body = $result['body'];

        return $subject . ' ' . $body;
    }

    /**
     * Tokenize text into normalized word tokens.
     *
     * @return array<string>
     */
    private function tokenize(string $text): array
    {
        $tokens = preg_split('/\s+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            return [];
        }

        return array_values(array_unique($tokens));
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
        if ($set1 === [] && $set2 === []) {
            return 1.0;
        }

        if ($set1 === [] || $set2 === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($set1, $set2));
        $union = count(array_unique(array_merge($set1, $set2)));

        return round($intersection / $union, 4);
    }
}

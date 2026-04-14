<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use App\Domain\CampaignRadar\Campaign;
use App\Domain\CampaignRadar\MessageCampaign;
use App\Domain\Communication\Message;
use App\Infrastructure\Campaign\Doctrine\CampaignRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ClusterAssignHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClusteringService $clusteringService,
        private CampaignRepository $campaignRepository,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Assigne un message à une campagne (existante ou nouvelle).
     *
     * @return array{campaign_id: string, is_new: bool, confidence: float}
     */
    public function handle(Uuid $messageId): array
    {
        // 1. Récupérer le message
        $message = $this->em->find(Message::class, $messageId);

        if ($message === null) {
            throw new \RuntimeException("Message not found: {$messageId->toRfc4122()}");
        }

        // 2. Récupérer les campagnes actives
        $activeCampaigns = $this->campaignRepository->findActive();

        // 3. Clustering
        $result = $this->clusteringService->assignCampaign($message, $activeCampaigns);

        // 4. Si nouvelle campagne, la créer
        if ($result['campaign_id'] === null) {
            $campaign = new Campaign('clustering-service');
            $this->em->persist($campaign);
            $campaignId = $campaign->getCampaignId()->toRfc4122();
            $isNew = true;

            // Initialiser centroid avec simhash du premier message
            /** @var array<string, array<string, mixed>> $features */
            $features = $result['features'];
            /** @var string $simhash */
            $simhash = $features['text']['simhash'];
            $campaign->setCentroidSimhash($simhash);
        } else {
            $campaignId = $result['campaign_id'];
            $isNew = false;
        }

        // 5. Créer association message ↔ campagne
        $messageCampaign = new MessageCampaign(
            $messageId,
            Uuid::fromString($campaignId),
            $result['confidence'],
            'clustering'
        );

        // Stocker les features pour analyse ultérieure
        $messageCampaign->setFeatures($result['features']);

        $this->em->persist($messageCampaign);

        // Mettre à jour le centroid de la campagne existante
        if (!$isNew) {
            $this->updateCampaignCentroid(Uuid::fromString($campaignId));
        }

        $this->em->flush();

        $this->logger->info('Message assigned to campaign', [
            'message_id' => $messageId->toRfc4122(),
            'campaign_id' => $campaignId,
            'is_new' => $isNew,
            'confidence' => $result['confidence'],
        ]);

        return [
            'campaign_id' => $campaignId,
            'is_new' => $isNew,
            'confidence' => $result['confidence'],
        ];
    }

    /**
     * Recalcule le centroid simhash d'une campagne.
     * Stratégie : sélectionner le simhash le plus fréquent parmi les messages.
     */
    private function updateCampaignCentroid(Uuid $campaignId): void
    {
        $sql = <<<SQL
            SELECT (features->'text'->>'simhash') as simhash, COUNT(*) as cnt
            FROM message_campaign
            WHERE campaign_id = :campaign_id
              AND features IS NOT NULL
            GROUP BY simhash
            ORDER BY cnt DESC
            LIMIT 1
        SQL;

        $result = $this->em->getConnection()->fetchAssociative($sql, [
            'campaign_id' => $campaignId->toRfc4122(),
        ]);

        if ($result && isset($result['simhash'])) {
            $campaign = $this->em->find(Campaign::class, $campaignId);

            if ($campaign !== null) {
                /** @var string $centroidHash */
                $centroidHash = $result['simhash'];
                $campaign->setCentroidSimhash($centroidHash);
            }
        }
    }
}

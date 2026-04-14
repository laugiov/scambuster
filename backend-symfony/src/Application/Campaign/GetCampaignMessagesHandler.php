<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use App\Infrastructure\Campaign\Doctrine\CampaignRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Handler pour récupérer les messages d'une campagne.
 *
 * Retourne un échantillon de messages pour inspection/analyse.
 */
final readonly class GetCampaignMessagesHandler
{
    public function __construct(
        private CampaignRepository $campaignRepository,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Récupère les messages d'une campagne.
     *
     * @param Uuid $campaignId ID de la campagne
     * @param int  $limit      Nombre max de messages (1-100)
     *
     * @throws \RuntimeException Si la campagne n'existe pas
     *
     * @return array{campaign_id: string, messages_count: int, messages: array<array{msg_id: string, subject: string|null, from: mixed, received_at: string, body_preview: string}>}
     */
    public function handle(Uuid $campaignId, int $limit): array
    {
        $this->logger->info('Fetching campaign messages', [
            'campaign_id' => $campaignId->toRfc4122(),
            'limit' => $limit,
        ]);

        // 1. Vérifier que la campagne existe
        $campaign = $this->campaignRepository->findById($campaignId);

        if (!$campaign instanceof \App\Domain\CampaignRadar\Campaign) {
            throw new \RuntimeException("Campaign not found: {$campaignId->toRfc4122()}");
        }

        // 2. Récupérer les messages
        $messages = $this->campaignRepository->findMessagesByCampaign($campaignId, $limit);

        // 3. Mapper vers DTOs
        $messagesData = array_map(fn ($message): array => [
            'msg_id' => $message->getMsgId(),
            'subject' => $message->getSubject(),
            'from' => $message->getHeaders()['from'] ?? null,
            'received_at' => $message->getTsMsg()->format(\DateTimeInterface::ATOM),
            'body_preview' => mb_substr((string) $message->getBodyText(), 0, 200),
        ], $messages);

        $this->logger->info('Campaign messages fetched successfully', [
            'campaign_id' => $campaignId->toRfc4122(),
            'messages_count' => count($messagesData),
        ]);

        return [
            'campaign_id' => $campaignId->toRfc4122(),
            'messages_count' => count($messagesData),
            'messages' => $messagesData,
        ];
    }
}

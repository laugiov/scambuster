<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use App\Domain\CampaignRadar\CampaignRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Handler to retrieve messages for a campaign.
 *
 * Returns a message sample for inspection/analysis.
 */
final readonly class GetCampaignMessagesHandler
{
    public function __construct(
        private CampaignRepositoryInterface $campaignRepository,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Retrieves messages for a campaign.
     *
     * @param Uuid $campaignId ID of the campaign
     * @param int  $limit      Max number of messages (1-100)
     *
     * @throws \RuntimeException If the campaign does not exist
     *
     * @return array{campaign_id: string, messages_count: int, messages: array<array{msg_id: string, subject: string|null, from: mixed, received_at: string, body_preview: string}>}
     */
    public function handle(Uuid $campaignId, int $limit): array
    {
        $this->logger->info('Fetching campaign messages', [
            'campaign_id' => $campaignId->toRfc4122(),
            'limit' => $limit,
        ]);

        // 1. Verify campaign exists
        $campaign = $this->campaignRepository->findById($campaignId);

        if (!$campaign instanceof \App\Domain\CampaignRadar\Campaign) {
            throw new \RuntimeException("Campaign not found: {$campaignId->toRfc4122()}");
        }

        // 2. Retrieve messages
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

<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use App\Infrastructure\Campaign\Doctrine\CampaignRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Handler pour profiler une campagne via LLM.
 *
 * Retrieves a message sample from the campaign and generates a YAML profile
 * describing tactics, targets, and likely variants.
 */
final readonly class ProfileCampaignHandler
{
    private const MIN_MESSAGES_FOR_PROFILING = 3;
    private const DEFAULT_SAMPLE_SIZE = 10;

    public function __construct(
        private EntityManagerInterface $em,
        private CampaignRepository $campaignRepository,
        private CampaignProfiler $profiler,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Profiles a campaign and stores the result in the database.
     *
     * @param Uuid $campaignId ID of the campaign to profile
     * @param int  $sampleSize Number of messages to analyze (3-10, default 10)
     *
     * @throws \RuntimeException Si la campagne n'existe pas ou n'a pas assez de messages
     *
     * @return array{profile_yaml: string, cache_hit: bool, attempts: int}
     */
    public function handle(Uuid $campaignId, int $sampleSize = self::DEFAULT_SAMPLE_SIZE): array
    {
        $startTime = microtime(true);

        $this->logger->info('Starting campaign profiling', [
            'campaign_id' => $campaignId->toRfc4122(),
            'sample_size' => $sampleSize,
        ]);

        // 1. Verify campaign exists
        $campaign = $this->campaignRepository->findById($campaignId);

        if (!$campaign instanceof \App\Domain\CampaignRadar\Campaign) {
            throw new \RuntimeException("Campaign not found: {$campaignId->toRfc4122()}");
        }

        // 2. Retrieve campaign messages
        $messages = $this->campaignRepository->findMessagesByCampaign($campaignId, $sampleSize);

        if (count($messages) < self::MIN_MESSAGES_FOR_PROFILING) {
            throw new \RuntimeException(
                sprintf(
                    'Campaign has only %d messages, minimum %d required for profiling',
                    count($messages),
                    self::MIN_MESSAGES_FOR_PROFILING
                )
            );
        }

        // 3. Appeler le profiler LLM
        $result = $this->profiler->profile($messages);

        // 4. Stocker le profil dans la campagne
        $campaign->setProfileYaml($result['profile_yaml']);
        $this->em->flush();

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->logger->info('Campaign profiling completed', [
            'campaign_id' => $campaignId->toRfc4122(),
            'cache_hit' => $result['cache_hit'],
            'attempts' => $result['attempts'],
            'latency_ms' => $latencyMs,
        ]);

        return [
            'profile_yaml' => $result['profile_yaml'],
            'cache_hit' => $result['cache_hit'],
            'attempts' => $result['attempts'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use App\Domain\CampaignRadar\Campaign;
use App\Domain\CampaignRadar\CampaignRule;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class StoreRuleHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Creates and stores a compiled DSL rule.
     *
     * @param array{sql: string, params: array<string, mixed>} $compiledData
     *
     * @throws \RuntimeException if the campaign is not found
     *
     * @return array{rule_id: string, campaign_id: string, status: string, enabled: bool}
     */
    public function handle(Uuid $campaignId, string $dsl, array $compiledData): array
    {
        $this->logger->info('Storing campaign rule', [
            'campaign_id' => $campaignId->toRfc4122(),
            'dsl_length' => mb_strlen($dsl),
        ]);

        // Verify campaign exists
        $campaign = $this->em->find(Campaign::class, $campaignId);

        if ($campaign === null) {
            throw new \RuntimeException('Campaign not found');
        }

        // Create rule
        $rule = new CampaignRule($campaignId, $dsl);
        $rule->setCompiledData($compiledData); // {sql, params}
        $rule->enable();

        $this->em->persist($rule);
        $this->em->flush();

        $this->logger->info('Campaign rule stored successfully', [
            'rule_id' => $rule->getRuleId()->toRfc4122(),
            'campaign_id' => $campaignId->toRfc4122(),
        ]);

        return [
            'rule_id' => $rule->getRuleId()->toRfc4122(),
            'campaign_id' => $campaignId->toRfc4122(),
            'status' => 'shadow',
            'enabled' => true,
        ];
    }
}

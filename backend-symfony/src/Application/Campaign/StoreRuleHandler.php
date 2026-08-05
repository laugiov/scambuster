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
        private LoggerInterface $logger,
        private DSLTranspiler $transpiler,
    ) {
    }

    /**
     * Creates and stores a rule, transpiling its DSL to SQL SERVER-SIDE.
     *
     * The compiled SQL is NEVER taken from the client. The endpoint used to
     * accept a client-supplied `compiled_sql` and store it verbatim; CampaignHunter
     * then executed that string on the hourly cron, giving an attacker full control
     * of the executed SQL. The only trusted source of the SQL is the server
     * transpiler, fed the rule's own DSL.
     *
     * @throws \RuntimeException         if the campaign is not found
     * @throws \InvalidArgumentException if the DSL cannot be transpiled
     *
     * @return array{rule_id: string, campaign_id: string, status: string, enabled: bool}
     */
    public function handle(Uuid $campaignId, string $dsl): array
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

        // Transpile the DSL server-side. A malformed DSL is a client error.
        try {
            $compiled = $this->transpiler->transpile($dsl);
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException('Rule DSL could not be transpiled: ' . $e->getMessage(), 0, $e);
        }

        // Create rule with the SERVER-generated SQL only.
        $rule = new CampaignRule($campaignId, $dsl);
        $rule->setCompiledData(['sql' => $compiled['sql'], 'params' => $compiled['params']]);
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

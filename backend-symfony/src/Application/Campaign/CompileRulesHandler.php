<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use App\Domain\CampaignRadar\Campaign;
use App\Domain\CampaignRadar\CampaignRule;
use App\Infrastructure\Campaign\Doctrine\CampaignRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Handler pour compiler des règles DSL MailGuard depuis un profil de campagne.
 *
 * Utilise le profil YAML généré par ProfileCampaignHandler pour générer
 * des règles DSL exécutables et les stocker dans campaign_rule.
 */
final class CompileRulesHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CampaignRepository $campaignRepository,
        private readonly RuleCompiler $compiler,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Compile des règles DSL pour une campagne et les stocke en BDD.
     *
     * @param Uuid                                                  $campaignId ID de la campagne
     * @param array{pos: array<int, mixed>, neg: array<int, mixed>} $examples   Exemples optionnels pour affiner les règles
     *
     * @throws \RuntimeException Si la campagne n'existe pas ou n'a pas de profil
     *
     * @return array{rules_dsl: string, rules_count: int, attempts: int, rule_ids: array<string>}
     */
    public function handle(Uuid $campaignId, array $examples = ['pos' => [], 'neg' => []]): array
    {
        $startTime = microtime(true);

        $this->logger->info('Starting DSL rule compilation for campaign', [
            'campaign_id' => $campaignId->toRfc4122(),
            'examples_pos' => count($examples['pos']),
            'examples_neg' => count($examples['neg']),
        ]);

        // 1. Vérifier que la campagne existe
        $campaign = $this->campaignRepository->findById($campaignId);

        if ($campaign === null) {
            throw new \RuntimeException("Campaign not found: {$campaignId->toRfc4122()}");
        }

        // 2. Vérifier que le profil existe
        $profileYaml = $campaign->getProfileYaml();

        if ($profileYaml === null) {
            throw new \RuntimeException(
                "Campaign has no profile. Run profiling first for campaign: {$campaignId->toRfc4122()}"
            );
        }

        // 3. Compiler les règles DSL
        $result = $this->compiler->compile($profileYaml, $examples);

        // 4. Extraire les règles individuelles et les stocker
        $ruleIds = $this->storeRules($campaign, $result['rules_dsl']);

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->logger->info('DSL rule compilation completed', [
            'campaign_id' => $campaignId->toRfc4122(),
            'rules_count' => $result['rules_count'],
            'attempts' => $result['attempts'],
            'latency_ms' => $latencyMs,
        ]);

        return [
            'rules_dsl' => $result['rules_dsl'],
            'rules_count' => $result['rules_count'],
            'attempts' => $result['attempts'],
            'rule_ids' => $ruleIds,
        ];
    }

    /**
     * Stocke les règles DSL compilées dans campaign_rule.
     *
     * @param string $rulesDsl Texte DSL contenant 1 à 3 règles
     *
     * @return array<string> IDs des règles créées
     */
    private function storeRules(Campaign $campaign, string $rulesDsl): array
    {
        $ruleIds = [];

        // Parser les règles individuelles (format: RULE name { ... })
        preg_match_all('/RULE\s+([\w.]+)\s*\{(.*?)\}/s', $rulesDsl, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $ruleName = $match[1];
            $ruleBody = $match[0]; // Règle complète avec RULE ... { ... }

            // Créer CampaignRule entity
            $rule = new CampaignRule(
                $campaign->getCampaignId(),
                $ruleBody
            );

            $this->em->persist($rule);
            $ruleIds[] = $rule->getRuleId()->toRfc4122();

            $this->logger->debug('Created campaign rule', [
                'rule_id' => $rule->getRuleId()->toRfc4122(),
                'rule_name' => $ruleName,
                'campaign_id' => $campaign->getCampaignId()->toRfc4122(),
            ]);
        }

        $this->em->flush();

        return $ruleIds;
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use App\Domain\CampaignRadar\CampaignRule;
use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class CampaignHunter
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Exécute toutes les règles actives en shadow mode.
     *
     * @return array{total_rules: int, total_hits: int, results: array<int, array<string, mixed>>}
     */
    public function hunt(): array
    {
        $this->logger->info('Starting campaign hunter');

        // Récupérer toutes les règles actives
        $rules = $this->em->getRepository(CampaignRule::class)
            ->findBy(['enabled' => true]);

        $results = [];
        $totalHits = 0;

        foreach ($rules as $rule) {
            $ruleResult = $this->huntRule($rule);
            $results[] = $ruleResult;
            $totalHits += $ruleResult['hits_count'];

            // Update rule metrics
            if ($ruleResult['status'] === 'ok') {
                $this->updateRuleMetrics($rule, $ruleResult);
            }
        }

        $this->em->flush();

        $this->logger->info('Campaign hunter completed', [
            'total_rules' => count($rules),
            'total_hits' => $totalHits,
        ]);

        return [
            'total_rules' => count($rules),
            'total_hits' => $totalHits,
            'results' => $results,
        ];
    }

    /**
     * Exécute une règle individuelle.
     *
     * @return array{rule_id: string, status: string, hits_count: int, ppv: float, lead_time_sec: ?int, latency_ms: int}
     */
    private function huntRule(CampaignRule $rule): array
    {
        $startTime = microtime(true);
        $ruleId = $rule->getRuleId()->toRfc4122();

        // Vérifier que SQL compilé existe
        $compiledData = $rule->getCompiledData();

        if ($compiledData === null) {
            $this->logger->warning('Rule has no compiled SQL', ['rule_id' => $ruleId]);

            return [
                'rule_id' => $ruleId,
                'status' => 'error',
                'error' => 'No compiled SQL',
                'hits_count' => 0,
                'ppv' => 0.0,
                'lead_time_sec' => null,
                'latency_ms' => 0,
            ];
        }

        // Valider la structure des données compilées
        if (!isset($compiledData['sql']) || !is_string($compiledData['sql']) || !isset($compiledData['params']) || !is_array($compiledData['params'])) {
            $this->logger->warning('Compiled data has invalid structure', [
                'rule_id' => $ruleId,
                'keys' => array_keys($compiledData),
            ]);

            return [
                'rule_id' => $ruleId,
                'status' => 'error',
                'error' => 'No compiled SQL',
                'hits_count' => 0,
                'ppv' => 0.0,
                'lead_time_sec' => null,
                'latency_ms' => 0,
            ];
        }

        $sql = $compiledData['sql'];
        $params = $compiledData['params'];

        // Exécuter SQL avec prepared statement
        try {
            $conn = $this->em->getConnection();
            $stmt = $conn->prepare($sql);
            $resultSet = $stmt->executeQuery($params);
            $hits = $resultSet->fetchAllAssociative();
        } catch (\Throwable $e) {
            $this->logger->error('Hunter SQL execution failed', [
                'rule_id' => $ruleId,
                'error' => $e->getMessage(),
                'sql_preview' => mb_substr($sql, 0, 100),
            ]);

            return [
                'rule_id' => $ruleId,
                'status' => 'error',
                'error' => $e->getMessage(),
                'hits_count' => 0,
                'ppv' => 0.0,
                'lead_time_sec' => null,
                'latency_ms' => 0,
            ];
        }

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        // Valider hits (échantillon de 10 max)
        $hitsToValidate = array_slice($hits, 0, 10);
        $validation = $this->validateHits($hitsToValidate);

        // Calculer PPV
        $ppv = $this->calculatePPV($validation);

        // Calculer lead-time
        $leadTime = $this->calculateLeadTime($hits);

        // Le hits_count doit correspondre au nombre de hits validés
        // pour respecter la contrainte: true_pos + false_pos = hits_count
        $validatedHitsCount = $validation['true_pos'] + $validation['false_pos'];

        $this->logger->info('Hunter rule executed', [
            'rule_id' => $ruleId,
            'total_sql_hits' => count($hits),
            'validated_hits' => $validatedHitsCount,
            'ppv' => $ppv,
            'lead_time_sec' => $leadTime,
            'latency_ms' => $latencyMs,
        ]);

        return [
            'rule_id' => $ruleId,
            'status' => 'ok',
            'hits_count' => $validatedHitsCount,
            'ppv' => $ppv,
            'lead_time_sec' => $leadTime,
            'latency_ms' => $latencyMs,
            'validation' => $validation,
        ];
    }

    /**
     * Valide les hits (vrais positifs vs faux positifs).
     *
     * @param array<int, array<string, mixed>> $hits
     *
     * @return array{true_pos: int, false_pos: int}
     */
    private function validateHits(array $hits): array
    {
        $truePos = 0;
        $falsePos = 0;

        foreach ($hits as $hit) {
            $messageId = $hit['msg_id'] ?? null;

            if (!$messageId) {
                // Message ID manquant = faux positif
                $falsePos++;

                continue;
            }

            $message = $this->em->find(Message::class, $messageId);

            if ($message === null) {
                // Message introuvable = faux positif
                $falsePos++;

                continue;
            }

            $conversation = $message->getConversation();

            // Heuristique : score_risk >= 30 = vrai positif
            if ($conversation->getScoreRisk() >= 30) {
                $truePos++;
            } else {
                $falsePos++;
            }
        }

        return [
            'true_pos' => $truePos,
            'false_pos' => $falsePos,
        ];
    }

    /**
     * Calcule Positive Predictive Value (PPV).
     *
     * @param array{true_pos: int, false_pos: int} $validation
     */
    private function calculatePPV(array $validation): float
    {
        $total = $validation['true_pos'] + $validation['false_pos'];

        if ($total === 0) {
            return 0.0;
        }

        return round($validation['true_pos'] / $total, 4);
    }

    /**
     * Calcule le lead-time (premier hit → pic campagne).
     *
     * @param array<int, array<string, mixed>> $hits Résultats SQL triés par ts_msg
     *
     * @return int|null Lead-time en secondes, null si insuffisant
     */
    private function calculateLeadTime(array $hits): ?int
    {
        if (count($hits) < 5) {
            return null; // Pas assez de hits pour calculer un pic
        }

        // Trier hits par timestamp
        usort(
            $hits,
            fn ($a, $b): int =>
            strtotime(is_string($a['ts_msg']) ? $a['ts_msg'] : '') <=> strtotime(is_string($b['ts_msg']) ? $b['ts_msg'] : '')
        );

        $firstHit = new \DateTimeImmutable($hits[0]['ts_msg']);

        // Trouver le pic (fenêtre avec le plus de hits)
        $peakTime = $this->findPeakTime($hits);

        if (!$peakTime instanceof \DateTimeImmutable) {
            // Fallback : utiliser dernier hit comme pic
            $peakTime = new \DateTimeImmutable($hits[count($hits) - 1]['ts_msg']);
        }

        $leadTimeSec = $peakTime->getTimestamp() - $firstHit->getTimestamp();

        return max(0, $leadTimeSec);
    }

    /**
     * Trouve le moment du pic de la campagne (fenêtre glissante 1h).
     *
     * @param array<int, array<string, mixed>> $hits Hits triés par ts_msg
     *
     * @return \DateTimeImmutable|null Timestamp du pic
     */
    private function findPeakTime(array $hits): ?\DateTimeImmutable
    {
        if (count($hits) < 2) {
            return null;
        }

        $maxHitsInWindow = 0;
        $peakTime = null;

        foreach ($hits as $hit) {
            $windowStart = new \DateTimeImmutable($hit['ts_msg']);
            $windowEnd = $windowStart->modify('+1 hour');

            // Compter hits dans cette fenêtre
            $hitsInWindow = array_filter($hits, function (array $h) use ($windowStart, $windowEnd): bool {
                $time = new \DateTimeImmutable($h['ts_msg']);

                return $time >= $windowStart && $time <= $windowEnd;
            });

            $count = count($hitsInWindow);

            if ($count > $maxHitsInWindow) {
                $maxHitsInWindow = $count;
                $peakTime = $windowStart;
            }
        }

        return $peakTime;
    }

    /**
     * Met à jour les métriques d'une règle.
     *
     * @param array<string, mixed> $result
     */
    private function updateRuleMetrics(CampaignRule $rule, array $result): void
    {
        // Récupérer validation pour true_pos et false_pos
        /** @var array{true_pos: int, false_pos: int} $validation */
        $validation = $result['validation'];

        $rule->updateMetrics(
            $result['hits_count'],
            $validation['true_pos'],
            $validation['false_pos']
        );

        // Update PPV directement depuis résultat
        $rule->setPpv($result['ppv']);

        if ($result['lead_time_sec'] !== null) {
            $rule->setLeadTimeSec($result['lead_time_sec']);
        }
    }
}

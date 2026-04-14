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
     * Executes all active rules in shadow mode.
     *
     * @return array{total_rules: int, total_hits: int, results: array<int, array<string, mixed>>}
     */
    public function hunt(): array
    {
        $this->logger->info('Starting campaign hunter');

        // Retrieve all active rules
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
     * Executes an individual rule.
     *
     * @return array{rule_id: string, status: string, hits_count: int, ppv: float, lead_time_sec: ?int, latency_ms: int}
     */
    private function huntRule(CampaignRule $rule): array
    {
        $startTime = microtime(true);
        $ruleId = $rule->getRuleId()->toRfc4122();

        // Verify compiled SQL exists
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

        // Validate compiled data structure
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

        // Execute SQL with prepared statement
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

        // Validate hits (sample of max 10)
        $hitsToValidate = array_slice($hits, 0, 10);
        $validation = $this->validateHits($hitsToValidate);

        // Calculate PPV
        $ppv = $this->calculatePPV($validation);

        // Calculate lead-time
        $leadTime = $this->calculateLeadTime($hits);

        // hits_count must match the number of validated hits
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
     * @param array<int, array<string, mixed>> $hits SQL results sorted by ts_msg
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

        /** @var string $firstHitTs */
        $firstHitTs = $hits[0]['ts_msg'];
        $firstHit = new \DateTimeImmutable($firstHitTs);

        // Find the peak (window with the most hits)
        $peakTime = $this->findPeakTime($hits);

        if (!$peakTime instanceof \DateTimeImmutable) {
            // Fallback : utiliser dernier hit comme pic
            /** @var string $lastHitTs */
            $lastHitTs = $hits[count($hits) - 1]['ts_msg'];
            $peakTime = new \DateTimeImmutable($lastHitTs);
        }

        $leadTimeSec = $peakTime->getTimestamp() - $firstHit->getTimestamp();

        return max(0, $leadTimeSec);
    }

    /**
     * Finds the campaign peak time (1h sliding window).
     *
     * @param array<int, array<string, mixed>> $hits Hits sorted by ts_msg
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
            /** @var string $hitTs */
            $hitTs = $hit['ts_msg'];
            $windowStart = new \DateTimeImmutable($hitTs);
            $windowEnd = $windowStart->modify('+1 hour');

            // Count hits in this window
            $hitsInWindow = array_filter($hits, function (array $h) use ($windowStart, $windowEnd): bool {
                /** @var string $hTs */
                $hTs = $h['ts_msg'];
                $time = new \DateTimeImmutable($hTs);

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
     * Updates a rule's metrics.
     *
     * @param array<string, mixed> $result
     */
    private function updateRuleMetrics(CampaignRule $rule, array $result): void
    {
        // Retrieve validation for true_pos and false_pos
        /** @var array{true_pos: int, false_pos: int} $validation */
        $validation = $result['validation'];

        /** @var int $hitsCount */
        $hitsCount = $result['hits_count'];
        $rule->updateMetrics(
            $hitsCount,
            $validation['true_pos'],
            $validation['false_pos']
        );

        // Update PPV directly from result
        /** @var float $ppv */
        $ppv = $result['ppv'];
        $rule->setPpv($ppv);

        if ($result['lead_time_sec'] !== null) {
            /** @var int $leadTimeSec */
            $leadTimeSec = $result['lead_time_sec'];
            $rule->setLeadTimeSec($leadTimeSec);
        }
    }
}

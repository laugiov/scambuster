<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Aggregates system health and autonomy metrics.
 *
 * Provides a unified view of:
 * - Pipeline activity (conversations, messages, IOCs)
 * - Kill switch status
 * - Bandit convergence state
 * - System readiness for autonomous operation
 */
final readonly class AutonomyMonitoringHandler
{
    public function __construct(
        private EntityManagerInterface $em
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getAutonomyStatus(): array
    {
        $conversations = $this->getConversationMetrics();
        $messages = $this->getMessageMetrics();
        $iocs = $this->getIocMetrics();
        $killSwitch = $this->getKillSwitchStatus();
        $convergence = $this->getConvergenceStatus();
        $lastActivity = $this->getLastActivity();

        $healthy = !$killSwitch
            && $conversations['open'] >= 0
            && $lastActivity['last_inbound'] !== null;

        return [
            'status' => $healthy ? 'operational' : 'degraded',
            'kill_switch_active' => $killSwitch,
            'conversations' => $conversations,
            'messages' => $messages,
            'iocs' => $iocs,
            'convergence' => $convergence,
            'last_activity' => $lastActivity,
            'checked_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function fetchInt(string $sql, array $params = []): int
    {
        /** @var int|string|false $result */
        $result = $this->em->getConnection()->fetchOne($sql, $params);

        return (int) $result;
    }

    /**
     * @return array{total: int, open: int, closed: int, abandoned: int}
     */
    private function getConversationMetrics(): array
    {
        $total = $this->fetchInt('SELECT COUNT(*) FROM conversation WHERE deleted_at IS NULL');
        $open = $this->fetchInt("SELECT COUNT(*) FROM conversation WHERE status = 'open' AND deleted_at IS NULL");
        $closed = $this->fetchInt("SELECT COUNT(*) FROM conversation WHERE status = 'closed' AND deleted_at IS NULL");
        $abandoned = $this->fetchInt("SELECT COUNT(*) FROM conversation WHERE status = 'abandoned' AND deleted_at IS NULL");

        return [
            'total' => $total,
            'open' => $open,
            'closed' => $closed,
            'abandoned' => $abandoned,
        ];
    }

    /**
     * @return array{total: int, inbound: int, outbound: int}
     */
    private function getMessageMetrics(): array
    {
        $total = $this->fetchInt('SELECT COUNT(*) FROM message');
        $inbound = $this->fetchInt("SELECT COUNT(*) FROM message m JOIN lkp_direction d ON m.direction = d.dir_id WHERE d.code = 'in'");
        $outbound = $this->fetchInt("SELECT COUNT(*) FROM message m JOIN lkp_direction d ON m.direction = d.dir_id WHERE d.code = 'out'");

        return [
            'total' => $total,
            'inbound' => $inbound,
            'outbound' => $outbound,
        ];
    }

    /**
     * @return array{total: int, unique_indicators: int, unique_types: int, last_24h: int}
     */
    private function getIocMetrics(): array
    {
        $total = $this->fetchInt('SELECT COUNT(*) FROM observed_ioc');
        $uniqueIndicators = $this->fetchInt('SELECT COUNT(*) FROM indicator');
        $uniqueTypes = $this->fetchInt('SELECT COUNT(DISTINCT type) FROM indicator');
        $last24h = $this->fetchInt(
            'SELECT COUNT(*) FROM observed_ioc WHERE ts_observed > :threshold',
            ['threshold' => (new \DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s')]
        );

        return [
            'total' => $total,
            'unique_indicators' => $uniqueIndicators,
            'unique_types' => $uniqueTypes,
            'last_24h' => $last24h,
        ];
    }

    private function getKillSwitchStatus(): bool
    {
        $value = $_ENV['SCAMBUSTER_KILL_SWITCH'] ?? $_SERVER['SCAMBUSTER_KILL_SWITCH'] ?? '0';

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array{converged_types: int, total_types: int, details: array<string, bool>, exploration_rate: float, best_persona: ?string}
     */
    private function getConvergenceStatus(): array
    {
        $conn = $this->em->getConnection();

        $scamTypes = $conn->fetchAllAssociative(
            "SELECT scam_type_id, code FROM lkp_scam_type WHERE active = true AND code != 'UNKNOWN'"
        );

        $details = [];
        $convergedCount = 0;

        foreach ($scamTypes as $row) {
            /** @var string $code */
            $code = $row['code'];
            $scamTypeId = $row['scam_type_id'];

            $stats = $conn->fetchAllAssociative(
                'SELECT persona_id, sessions_count, reward_avg FROM persona_performance_stats WHERE scam_type_id = :id',
                ['id' => $scamTypeId]
            );

            $totalSessions = 0;

            foreach ($stats as $stat) {
                /** @var int|string $sc */
                $sc = $stat['sessions_count'];
                $totalSessions += (int) $sc;
            }

            if ($totalSessions < 10) {
                $details[$code] = false;

                continue;
            }

            $maxShare = 0.0;

            foreach ($stats as $stat) {
                /** @var int|string $sc */
                $sc = $stat['sessions_count'];
                $share = (int) $sc / $totalSessions;
                $maxShare = max($maxShare, $share);
            }

            $converged = $maxShare >= 0.60;
            $details[$code] = $converged;

            if ($converged) {
                $convergedCount++;
            }
        }

        // BUG-2: Compute effective exploration rate (epsilon)
        // If any type has converged, epsilon drops to 0.05; otherwise 0.20
        $explorationRate = $convergedCount > 0 ? 0.05 : 0.20;

        // BUG-3: Find best persona (highest avg reward across all scam types)
        /** @var array{persona_code: string, avg_reward: string}|false $bestRow */
        $bestRow = $conn->fetchAssociative(
            'SELECT p.persona_code, AVG(pps.reward_avg) as avg_reward
             FROM persona_performance_stats pps
             JOIN persona p ON pps.persona_id = p.persona_id
             WHERE pps.sessions_count > 0
             GROUP BY p.persona_code
             ORDER BY avg_reward DESC
             LIMIT 1'
        );

        return [
            'converged_types' => $convergedCount,
            'total_types' => count($scamTypes),
            'details' => $details,
            'exploration_rate' => $explorationRate,
            'best_persona' => \is_array($bestRow) ? $bestRow['persona_code'] : null,
        ];
    }

    /**
     * @return array{last_inbound: ?string, last_outbound: ?string, last_ioc: ?string}
     */
    private function getLastActivity(): array
    {
        $conn = $this->em->getConnection();

        $lastInbound = $conn->fetchOne(
            "SELECT MAX(m.ts_msg) FROM message m JOIN lkp_direction d ON m.direction = d.dir_id WHERE d.code = 'in'"
        );
        $lastOutbound = $conn->fetchOne(
            "SELECT MAX(m.ts_msg) FROM message m JOIN lkp_direction d ON m.direction = d.dir_id WHERE d.code = 'out'"
        );
        $lastIoc = $conn->fetchOne('SELECT MAX(ts_observed) FROM observed_ioc');

        return [
            'last_inbound' => $lastInbound ?: null,
            'last_outbound' => $lastOutbound ?: null,
            'last_ioc' => $lastIoc ?: null,
        ];
    }
}

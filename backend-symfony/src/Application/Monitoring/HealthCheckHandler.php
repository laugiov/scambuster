<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Checks connectivity to infrastructure dependencies.
 *
 * Returns status for each service: ok, error, or degraded.
 * Used by the /api/health endpoint.
 */
final class HealthCheckHandler
{
    private float $startTime;

    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        $this->startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
    }

    /**
     * @return array<string, mixed>
     */
    public function check(): array
    {
        $checks = [];
        $checks['database'] = $this->checkDatabase();
        $checks['redis'] = $this->checkRedis();

        $hasError = false;
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                $hasError = true;
                break;
            }
        }

        return [
            'status' => $hasError ? 'error' : 'ok',
            'version' => $_ENV['APP_VERSION'] ?? '1.3.0',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'uptime_seconds' => (int) (microtime(true) - ($this->startTime)),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{status: string, latency_ms?: int, error?: string}
     */
    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            $this->em->getConnection()->fetchOne('SELECT 1');
            $latency = (int) ((microtime(true) - $start) * 1000);

            return ['status' => 'ok', 'latency_ms' => $latency];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{status: string, latency_ms?: int, error?: string}
     */
    private function checkRedis(): array
    {
        $redisUrl = $_ENV['REDIS_URL'] ?? '';
        if (empty($redisUrl)) {
            return ['status' => 'error', 'error' => 'REDIS_URL not configured'];
        }

        try {
            $start = microtime(true);
            $parsed = parse_url($redisUrl);
            $host = $parsed['host'] ?? 'redis';
            $port = $parsed['port'] ?? 6379;

            $redis = new \Redis();
            $redis->connect($host, (int) $port, 2.0);
            $pong = $redis->ping();
            $redis->close();
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($pong === true || $pong === '+PONG') {
                return ['status' => 'ok', 'latency_ms' => $latency];
            }

            return ['status' => 'error', 'error' => 'Unexpected PING response'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\AutonomyMonitoringHandler;
use App\Application\Monitoring\HealthCheckHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Prometheus-compatible metrics endpoint.
 *
 * Returns metrics in Prometheus text exposition format,
 * scrapable by any Prometheus instance.
 */
final class MetricsController
{
    public function __construct(
        private readonly AutonomyMonitoringHandler $autonomyHandler,
        private readonly HealthCheckHandler $healthHandler
    ) {
    }

    #[Route('/api/metrics', methods: ['GET'])]
    public function __invoke(): Response
    {
        /** @var array<string, mixed> $autonomy */
        $autonomy = $this->autonomyHandler->getAutonomyStatus();
        /** @var array<string, mixed> $health */
        $health = $this->healthHandler->check();

        $lines = [];

        // Info
        /** @var string $version */
        $version = $health['version'] ?? '0.0.0';
        $lines[] = '# HELP scambuster_info ScamBuster instance information';
        $lines[] = '# TYPE scambuster_info gauge';
        $lines[] = "scambuster_info{version=\"{$version}\"} 1";
        $lines[] = '';

        // Conversations
        $lines[] = '# HELP scambuster_conversations_total Conversations by status';
        $lines[] = '# TYPE scambuster_conversations_total gauge';
        /** @var array<string, int> $convs */
        $convs = $autonomy['conversations'] ?? [];

        foreach (['open', 'closed', 'abandoned'] as $status) {
            $val = $convs[$status] ?? 0;
            $lines[] = "scambuster_conversations_total{status=\"{$status}\"} {$val}";
        }
        $lines[] = '';

        // Messages
        $lines[] = '# HELP scambuster_messages_total Messages by direction';
        $lines[] = '# TYPE scambuster_messages_total gauge';
        /** @var array<string, int> $msgs */
        $msgs = $autonomy['messages'] ?? [];
        $lines[] = 'scambuster_messages_total{direction="inbound"} ' . ($msgs['inbound'] ?? 0);
        $lines[] = 'scambuster_messages_total{direction="outbound"} ' . ($msgs['outbound'] ?? 0);
        $lines[] = '';

        // IOCs
        $lines[] = '# HELP scambuster_iocs_total Total IOCs extracted';
        $lines[] = '# TYPE scambuster_iocs_total gauge';
        /** @var array<string, int> $iocsData */
        $iocsData = $autonomy['iocs'] ?? [];
        $lines[] = 'scambuster_iocs_total ' . ($iocsData['total'] ?? 0);
        $lines[] = '';

        $lines[] = '# HELP scambuster_iocs_unique Unique indicators';
        $lines[] = '# TYPE scambuster_iocs_unique gauge';
        $lines[] = 'scambuster_iocs_unique ' . ($iocsData['unique_indicators'] ?? 0);
        $lines[] = '';

        // Kill switch
        $lines[] = '# HELP scambuster_kill_switch Kill switch status (1=active, 0=inactive)';
        $lines[] = '# TYPE scambuster_kill_switch gauge';
        $ks = ($autonomy['kill_switch_active'] ?? false) ? 1 : 0;
        $lines[] = "scambuster_kill_switch {$ks}";
        $lines[] = '';

        // Health checks
        $lines[] = '# HELP scambuster_health_check Dependency health (1=ok, 0=error)';
        $lines[] = '# TYPE scambuster_health_check gauge';
        /** @var array<string, array<string, mixed>> $checks */
        $checks = $health['checks'] ?? [];

        foreach ($checks as $service => $check) {
            $val = ($check['status'] ?? 'error') === 'ok' ? 1 : 0;
            $lines[] = "scambuster_health_check{service=\"{$service}\"} {$val}";
        }
        $lines[] = '';

        // Convergence
        /** @var array<string, int> $convergence */
        $convergence = $autonomy['convergence'] ?? [];
        $lines[] = '# HELP scambuster_convergence_ratio Converged scam types ratio';
        $lines[] = '# TYPE scambuster_convergence_ratio gauge';
        $total = $convergence['total_types'] ?? 1;
        $converged = $convergence['converged_types'] ?? 0;
        $ratio = $total > 0 ? round($converged / $total, 4) : 0;
        $lines[] = "scambuster_convergence_ratio {$ratio}";

        return new Response(
            implode("\n", $lines) . "\n",
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']
        );
    }
}

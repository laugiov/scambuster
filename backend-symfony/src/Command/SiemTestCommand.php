<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Audit\Port\SiemExporterInterface;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\SiemEvent;
use App\Domain\Audit\SiemSeverityMap;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Test SIEM connectivity and format output.
 *
 * Sends a test event to the configured SIEM provider and reports the result.
 */
#[AsCommand(
    name: 'app:siem:test',
    description: 'Test SIEM connector — sends a test event and reports connectivity',
)]
final class SiemTestCommand extends Command
{
    public function __construct(
        private readonly SiemExporterInterface $exporter,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $provider = $this->exporter->getProviderName();
        $io->title('SIEM Connector Test');
        $io->text('Provider: ' . $provider);

        if ($provider === 'none') {
            $io->warning('SIEM export is disabled (SIEM_PROVIDER=none). Set SIEM_PROVIDER to enable.');

            return Command::SUCCESS;
        }

        // Health check
        $healthy = $this->exporter->isHealthy();
        $io->text('Health check: ' . ($healthy ? 'OK' : 'FAILED'));

        if (!$healthy) {
            $io->error('SIEM target is not reachable. Check SIEM_ENDPOINT.');

            return Command::FAILURE;
        }

        // Send test event
        $testEvent = new SiemEvent(
            timestamp: new \DateTimeImmutable(),
            eventType: AuditEventType::CONFIG_CHANGED,
            severity: SiemSeverityMap::getSeverity(AuditEventType::CONFIG_CHANGED),
            actorType: 'system',
            actorId: 'siem-test-command',
            action: 'siem_test',
            outcome: 'success',
            details: ['test' => true, 'message' => 'SIEM connectivity test from ScamBuster CLI'],
            ipAddress: '127.0.0.1',
            traceId: 'siem-test-' . bin2hex(random_bytes(8)),
        );

        $this->exporter->export($testEvent);

        $io->success('Test event sent successfully to ' . $provider . ' provider.');

        return Command::SUCCESS;
    }
}

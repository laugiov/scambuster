<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Audit\Port\SiemExporterInterface;
use App\Domain\Audit\SiemEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Batch export historical audit events to SIEM.
 *
 * Use for initial SIEM seeding or backfilling after outages.
 */
#[AsCommand(
    name: 'app:siem:export',
    description: 'Export historical audit events to SIEM (batch mode)',
)]
final class SiemExportCommand extends Command
{
    public function __construct(
        private readonly SiemExporterInterface $exporter,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Export events since (e.g. "24h", "7d", "2026-03-01")', '24h')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Events per batch', '100')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count events without exporting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('SIEM Batch Export');

        $provider = $this->exporter->getProviderName();

        if ($provider === 'none') {
            $io->error('SIEM export is disabled. Set SIEM_PROVIDER environment variable.');

            return Command::FAILURE;
        }

        /** @var string $sinceRaw */
        $sinceRaw = $input->getOption('since');
        /** @var string $batchRaw */
        $batchRaw = $input->getOption('batch-size');
        $since = $this->parseSince($sinceRaw);
        $batchSize = (int) $batchRaw;
        $dryRun = (bool) $input->getOption('dry-run');

        $io->text([
            'Provider: ' . $provider,
            'Since: ' . $since->format('Y-m-d H:i:s'),
            'Batch size: ' . $batchSize,
            'Dry run: ' . ($dryRun ? 'yes' : 'no'),
        ]);

        // Fetch events from audit_log
        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT * FROM audit_log WHERE created_at >= :since ORDER BY created_at ASC',
            ['since' => $since->format('Y-m-d H:i:s')],
        );

        $total = \count($rows);
        $io->text('Events found: ' . $total);

        if ($total === 0) {
            $io->success('No events to export.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->success(sprintf('Dry run: %d events would be exported.', $total));

            return Command::SUCCESS;
        }

        // Export in batches
        $batch = [];
        $exported = 0;

        foreach ($rows as $row) {
            /** @var array{event_type: string, created_at: string, actor_type: string, actor_id: string, action: string, outcome: string, details: string, resource_type: ?string, resource_id: ?string, ip_address: ?string, trace_id: ?string} $row */
            $batch[] = $this->rowToSiemEvent($row);

            if (\count($batch) >= $batchSize) {
                $this->exporter->exportBatch($batch);
                $exported += \count($batch);
                $batch = [];
                $io->text(sprintf('  Exported %d / %d', $exported, $total));
            }
        }

        if ($batch !== []) {
            $this->exporter->exportBatch($batch);
            $exported += \count($batch);
        }

        $io->success(sprintf('Exported %d events to %s provider.', $exported, $provider));

        return Command::SUCCESS;
    }

    private function parseSince(string $value): \DateTimeImmutable
    {
        // Relative: "24h", "7d", "30m"
        if (preg_match('/^(\d+)([hdm])$/', $value, $m)) {
            $amount = (int) $m[1];
            $unit = match ($m[2]) {
                'h' => 'hours',
                'd' => 'days',
                'm' => 'minutes',
            };

            return new \DateTimeImmutable("-{$amount} {$unit}");
        }

        // Absolute date
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if ($date !== false) {
            return $date->setTime(0, 0);
        }

        return new \DateTimeImmutable('-24 hours');
    }

    /**
     * @param array{event_type: string, created_at: string, actor_type: string, actor_id: string, action: string, outcome: string, details: string, resource_type: ?string, resource_id: ?string, ip_address: ?string, trace_id: ?string} $row
     */
    private function rowToSiemEvent(array $row): SiemEvent
    {
        $eventType = \App\Domain\Audit\AuditEventType::from($row['event_type']);

        /** @var array<string, mixed> $details */
        $details = json_decode($row['details'], true) ?: [];

        return new SiemEvent(
            timestamp: new \DateTimeImmutable($row['created_at']),
            eventType: $eventType,
            severity: \App\Domain\Audit\SiemSeverityMap::getSeverity($eventType),
            actorType: $row['actor_type'],
            actorId: $row['actor_id'],
            action: $row['action'],
            outcome: $row['outcome'],
            details: $details,
            resourceType: $row['resource_type'],
            resourceId: $row['resource_id'],
            ipAddress: $row['ip_address'],
            traceId: $row['trace_id'],
        );
    }
}

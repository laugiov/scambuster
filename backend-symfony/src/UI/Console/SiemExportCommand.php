<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Audit\AuditEventQueryService;
use App\Application\Audit\Port\SiemExporterInterface;
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
        private readonly AuditEventQueryService $auditEventQueryService,
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
        $since = $this->auditEventQueryService->parseSince($sinceRaw);
        $batchSize = (int) $batchRaw;
        $dryRun = (bool) $input->getOption('dry-run');

        $io->text([
            'Provider: ' . $provider,
            'Since: ' . $since->format('Y-m-d H:i:s'),
            'Batch size: ' . $batchSize,
            'Dry run: ' . ($dryRun ? 'yes' : 'no'),
        ]);

        $events = $this->auditEventQueryService->fetchEventsSince($since);
        $total = \count($events);
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

        foreach ($events as $event) {
            $batch[] = $event;

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
}

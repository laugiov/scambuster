<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Communication\IocExportMapper;
use App\Domain\Communication\ObservedIoc;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migrate existing IOCs to add MISP/STIX export metadata.
 *
 * This command enriches all existing observed_ioc records with:
 * - context.misp: {category, type, to_ids}
 * - context.stix: {sco_type, pattern}
 *
 * Safe to run multiple times (idempotent).
 *
 * Usage: php bin/console app:migrate-iocs-export-metadata
 */
#[AsCommand(
    name: 'app:migrate-iocs-export-metadata',
    description: 'Enrich existing IOCs with MISP/STIX export metadata'
)]
final class MigrateIocsExportMetadataCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IocExportMapper $exportMapper
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Migrating IOCs to add MISP/STIX export metadata');

        // Fetch all IOCs
        $iocs = $this->em->getRepository(ObservedIoc::class)->findAll();

        $total = count($iocs);
        $io->info(sprintf('Found %d IOCs to migrate', $total));

        if ($total === 0) {
            $io->warning('No IOCs found in database');

            return Command::SUCCESS;
        }

        // Progress tracking
        $migrated = 0;
        $alreadyEnriched = 0;
        $errors = [];

        $io->progressStart($total);

        foreach ($iocs as $ioc) {
            try {
                $context = $ioc->getContext();

                // Check if already enriched (idempotency)
                if (isset($context['misp']) && isset($context['stix'])) {
                    ++$alreadyEnriched;
                    $io->progressAdvance();

                    continue;
                }

                // Enrich with MISP/STIX metadata
                $enrichedContext = $this->exportMapper->enrichWithExportMetadata($context);

                // Update IOC
                $ioc->updateContext($enrichedContext);
                ++$migrated;

                $io->progressAdvance();
            } catch (\Throwable $e) {
                $errors[] = sprintf(
                    'Failed to migrate IOC %s: %s',
                    $ioc->getObsId(),
                    $e->getMessage()
                );
                $io->progressAdvance();
            }
        }

        $io->progressFinish();

        // Flush all changes
        $this->em->flush();

        // Summary
        $io->success('Migration completed');

        $io->table(
            ['Metric', 'Count'],
            [
                ['Total IOCs', $total],
                ['Migrated', $migrated],
                ['Already enriched', $alreadyEnriched],
                ['Errors', count($errors)],
            ]
        );

        if (count($errors) > 0) {
            $io->warning('Errors encountered:');

            foreach ($errors as $error) {
                $io->writeln('  - ' . $error);
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

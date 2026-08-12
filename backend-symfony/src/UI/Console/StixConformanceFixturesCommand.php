<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Stix\ConformanceFixtureBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Write the three exported STIX bundle types, built from synthetic fixtures, so an
 * external validator can check them.
 *
 * Split out from the validation script because the two halves run in different
 * places: the bundles are built inside the PHP container, and the validator is a
 * Python tool that runs on the host. The project directory is bind-mounted, so a
 * file written here is immediately visible there.
 *
 * The bundles contain no production data: the fixtures are invented, precisely
 * because verbatim scammer evidence must never leave the database. That is what
 * makes it safe for CI to keep them as a build artifact.
 */
#[AsCommand(
    name: 'scambuster:stix:conformance-fixtures',
    description: 'Write the three STIX bundle types from synthetic fixtures, for external conformance validation.',
)]
final class StixConformanceFixturesCommand extends Command
{
    public function __construct(
        private readonly ConformanceFixtureBuilder $builder,
        private readonly string $defaultOutputDirectory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Directory to write the bundles into.', null)
            ->setHelp(
                "Writes ioc-bundle.json, cluster-bundle.json and conversation-ttp-bundle.json.\n\n".
                "Run scripts/standards/validate-stix-bundles.sh to build and validate them in one step.\n".
                'The bundles are built from synthetic fixtures and contain no production data.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $outputRaw = $input->getOption('output');
        $directory = \is_string($outputRaw) && $outputRaw !== '' ? $outputRaw : $this->defaultOutputDirectory;

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $io->error(sprintf('Unable to create the output directory: %s', $directory));

            return Command::FAILURE;
        }

        $rows = [];

        foreach ($this->builder->buildAll() as $name => $bundle) {
            $json = json_encode(
                $bundle,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . "\n";

            $path = rtrim($directory, '/') . '/' . $name;

            if (file_put_contents($path, $json) === false) {
                $io->error(sprintf('Unable to write %s', $path));

                return Command::FAILURE;
            }

            /** @var list<array<string, mixed>> $objects */
            $objects = $bundle['objects'];
            $rows[] = [$name, (string) \count($objects)];
        }

        $io->table(['Bundle', 'Objects'], $rows);
        $io->success(sprintf('Wrote %d bundle(s) to %s', \count($rows), $directory));

        return Command::SUCCESS;
    }
}

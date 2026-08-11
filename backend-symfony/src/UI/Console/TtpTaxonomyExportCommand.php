<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Standards\TaxonomyArtifactGenerator;
use App\Domain\Communication\Ttp;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generate the machine-readable taxonomy artifact from the canonical seed (Spec 003).
 *
 * The artifact is committed, so this command is the only writer: nobody hand-edits
 * the JSON. --check regenerates in memory and diffs against what is on disk, which
 * is what CI runs — a taxonomy change that did not regenerate the artifact fails the
 * build rather than shipping an artifact that disagrees with the database.
 *
 * Generating and using the artifact internally is not gated. Publishing it as a
 * standalone public file positioned as a standard — repo root, release asset,
 * external registry — waits for the container decision (Constitution IV,
 * Spec 003 FR-007).
 */
#[AsCommand(
    name: 'scambuster:ttp:taxonomy-export',
    description: 'Generate the versioned, schema-validated TTP taxonomy artifact from the canonical seed.',
)]
final class TtpTaxonomyExportCommand extends Command
{
    public function __construct(
        private readonly TaxonomyArtifactGenerator $generator,
        private readonly string $artifactDirectory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'Regenerate in memory and fail if the committed artifact differs. Writes nothing.')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to this path instead of the canonical location.', null)
            ->setHelp(
                "Generates config/standards/taxonomy-v<version>.json from TtpTaxonomySeed::ENTRIES.\n\n".
                "The output is deterministic: no timestamps, fixed ordering, byte-identical across runs.\n".
                "That is what lets --check diff it in CI and what lets a third party regenerate it and\n".
                "get the same bytes.\n\n".
                'Publishing the file as a public standard is gated on the container decision.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $outputRaw = $input->getOption('output');
        $path = \is_string($outputRaw) && $outputRaw !== ''
            ? $outputRaw
            : rtrim($this->artifactDirectory, '/') . '/' . TaxonomyArtifactGenerator::fileName();

        $json = $this->generator->generateJson();
        $check = $input->getOption('check') === true;

        if ($check) {
            if (!is_file($path)) {
                $io->error(sprintf('Artifact is missing: %s. Run scambuster:ttp:taxonomy-export and commit it.', $path));

                return Command::FAILURE;
            }

            $current = file_get_contents($path);

            if ($current === false) {
                $io->error(sprintf('Unable to read the committed artifact: %s', $path));

                return Command::FAILURE;
            }

            if ($current !== $json) {
                $io->error(sprintf(
                    'The committed artifact does not match the taxonomy seed. Run scambuster:ttp:taxonomy-export and commit %s.',
                    basename($path)
                ));

                return Command::FAILURE;
            }

            $io->success(sprintf('Artifact is up to date (taxonomy v%s).', Ttp::TAXONOMY_VERSION));

            return Command::SUCCESS;
        }

        $directory = \dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $io->error(sprintf('Unable to create the artifact directory: %s', $directory));

            return Command::FAILURE;
        }

        if (file_put_contents($path, $json) === false) {
            $io->error(sprintf('Unable to write the artifact: %s', $path));

            return Command::FAILURE;
        }

        $artifact = $this->generator->generate();
        $io->success(sprintf(
            'Wrote %d taxonomy entries (v%s) to %s',
            \is_int($artifact['entry_count']) ? $artifact['entry_count'] : 0,
            Ttp::TAXONOMY_VERSION,
            $path,
        ));
        $io->note('Internal artifact. Publishing it as a standalone public standard is gated on the container decision (Constitution IV).');

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Standards\F3MappingLoader;
use App\Application\Standards\F3MappingRenderer;
use App\Domain\Communication\TtpTaxonomySeed;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Validate the ScamBuster -> MITRE F3 mapping and regenerate its document table.
 *
 * The mapping decisions live in config/standards/f3-mapping.json. This command is
 * the only writer of the generated block in docs/standards/f3-mapping.md, so the
 * document and the data can never disagree about what was decided.
 *
 * --check validates and diffs without writing, which is what CI runs: it fails when
 * the mapping file is internally inconsistent, or when someone hand-edited the
 * generated table instead of the source of truth.
 */
#[AsCommand(
    name: 'scambuster:ttp:f3-mapping',
    description: 'Validate the MITRE F3 mapping and regenerate its table in docs/standards/f3-mapping.md.',
)]
final class TtpF3MappingCommand extends Command
{
    public function __construct(
        private readonly F3MappingLoader $loader,
        private readonly F3MappingRenderer $renderer,
        private readonly string $mappingDocumentPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'Validate and diff without writing. Fails when the document is stale or the mapping is inconsistent.')
            ->setHelp(
                "Source of truth: config/standards/f3-mapping.json.\n".
                "Generated block: docs/standards/f3-mapping.md, between the generated-table markers.\n\n".
                'Run with --check in CI; run without it after editing the mapping JSON.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $check = $input->getOption('check') === true;

        try {
            $problems = $this->loader->validate(TtpTaxonomySeed::codes());
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($problems !== []) {
            $io->error(sprintf('The F3 mapping file has %d problem(s):', \count($problems)));
            $io->listing($problems);

            return Command::FAILURE;
        }

        $io->success(sprintf('Mapping file is consistent: %d taxonomy code(s) covered.', \count(TtpTaxonomySeed::codes())));

        $confirmed = $this->loader->confirmedReferences();
        $io->text(sprintf(
            'Confirmed mappings that must appear in external_refs: %d.',
            \count($confirmed)
        ));

        if (!is_file($this->mappingDocumentPath)) {
            $io->error(sprintf('Mapping document not found: %s', $this->mappingDocumentPath));

            return Command::FAILURE;
        }

        $current = file_get_contents($this->mappingDocumentPath);

        if ($current === false) {
            $io->error(sprintf('Unable to read the mapping document: %s', $this->mappingDocumentPath));

            return Command::FAILURE;
        }

        try {
            $regenerated = $this->renderer->replaceBlock($current);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($regenerated === $current) {
            $io->success('The mapping document is up to date.');

            return Command::SUCCESS;
        }

        if ($check) {
            $io->error('The mapping document is stale. Run scambuster:ttp:f3-mapping (without --check) and commit the result.');

            return Command::FAILURE;
        }

        if (file_put_contents($this->mappingDocumentPath, $regenerated) === false) {
            $io->error(sprintf('Unable to write the mapping document: %s', $this->mappingDocumentPath));

            return Command::FAILURE;
        }

        $io->success(sprintf('Regenerated the mapping table in %s', $this->mappingDocumentPath));

        if ($confirmed === []) {
            $io->note(sprintf(
                'No entry carries a confirmed mapping yet, so no %s reference is emitted. That is the correct behaviour while the mapping is pending: nothing fabricated reaches an export.',
                F3MappingLoader::SOURCE_NAME
            ));
        }

        return Command::SUCCESS;
    }
}

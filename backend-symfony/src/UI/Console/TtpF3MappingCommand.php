<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Standards\F3MappingLoader;
use App\Domain\Communication\TtpTaxonomySeed;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Validate the ScamBuster -> MITRE F3 mapping file.
 *
 * Checks that every taxonomy code carries exactly one decision from the closed
 * relation vocabulary, that a decision citing a technique names one and a `none`
 * decision names none, and that recorded decisions say which F3 version they were
 * checked against.
 *
 * Rendering the decisions into docs/standards/f3-mapping.md is a separate step,
 * `scripts/standards/render-f3-mapping.py`, and deliberately not this command's
 * job: the container bind-mounts backend-symfony/ as its project root, so docs/ —
 * one level above it at the repository root — does not exist inside it. A console
 * command cannot write a file it cannot see.
 */
#[AsCommand(
    name: 'scambuster:ttp:f3-mapping',
    description: 'Validate the MITRE F3 mapping decisions against the taxonomy.',
)]
final class TtpF3MappingCommand extends Command
{
    public function __construct(
        private readonly F3MappingLoader $loader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            "Source of truth: config/standards/f3-mapping.json.\n\n".
            "Validates the mapping decisions. To regenerate the document table from them, run\n".
            'scripts/standards/render-f3-mapping.py on the host — docs/ is outside this container.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

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

        if ($confirmed === []) {
            $io->note(sprintf(
                'No entry carries a confirmed mapping yet, so no %s reference is emitted. That is the correct behaviour while the mapping is pending: nothing fabricated reaches an export.',
                F3MappingLoader::SOURCE_NAME
            ));
        }

        return Command::SUCCESS;
    }
}

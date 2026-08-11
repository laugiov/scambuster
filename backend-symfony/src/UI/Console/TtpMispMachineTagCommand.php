<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Standards\MispMachineTagGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generate the MISP taxonomy file for the `scambuster` namespace (Spec 006).
 *
 * The file is generated from the canonical taxonomy seed and never hand-edited: a
 * hand-maintained copy of 27 definitions drifts from the taxonomy within a release,
 * and the drift stays invisible until a consumer notices their tag means something
 * else.
 *
 * GATED. Filing this file with the MISP taxonomies repository is blocked on the
 * container decision (Constitution IV) — a registered public taxonomy is a
 * normative artifact and a merged PR is hard to retract. This command only writes
 * bytes into the repository; a human decides whether they ever leave it. The
 * command says so on every run rather than leaving the reader to remember.
 */
#[AsCommand(
    name: 'scambuster:ttp:misp-machinetag',
    description: 'Generate the MISP taxonomy file for the scambuster namespace (internal; filing is gated).',
)]
final class TtpMispMachineTagCommand extends Command
{
    public function __construct(
        private readonly MispMachineTagGenerator $generator,
        private readonly string $artifactDirectory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'Regenerate in memory and fail if the committed file differs. Writes nothing.')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to this path instead of the canonical location.', null)
            ->setHelp(
                "Generates machinetag.json from TtpTaxonomySeed::ENTRIES.\n\n".
                "Registering the namespace makes scambuster:ttp tags resolve in every MISP instance\n".
                "that syncs the taxonomies repository. That is a normative, hard-to-retract public\n".
                "artifact, so filing it waits for the container decision. See docs/standards-track.md."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $outputRaw = $input->getOption('output');
        $path = \is_string($outputRaw) && $outputRaw !== ''
            ? $outputRaw
            : rtrim($this->artifactDirectory, '/') . '/machinetag.json';

        $json = $this->generator->generateJson();

        if ($input->getOption('check') === true) {
            $current = is_file($path) ? file_get_contents($path) : false;

            if ($current === false) {
                $io->error(sprintf('machinetag.json is missing or unreadable: %s', $path));

                return Command::FAILURE;
            }

            if ($current !== $json) {
                $io->error('The committed machinetag.json does not match the taxonomy seed. Run scambuster:ttp:misp-machinetag and commit it.');

                return Command::FAILURE;
            }

            $io->success('machinetag.json is up to date.');

            return Command::SUCCESS;
        }

        if (file_put_contents($path, $json) === false) {
            $io->error(sprintf('Unable to write %s', $path));

            return Command::FAILURE;
        }

        $document = $this->generator->generate();
        /** @var list<array<string, mixed>> $entries */
        $entries = $document['values'][0]['entry'];

        $io->success(sprintf(
            'Wrote %d taxonomy value(s) for %s:%s (version %s) to %s',
            \count($entries),
            MispMachineTagGenerator::NAMESPACE_NAME,
            MispMachineTagGenerator::PREDICATE,
            (string) $document['version'],
            $path,
        ));
        $io->warning('GATED: do not file this with the MISP taxonomies repository. Registration is blocked on the container decision (Constitution IV) — see docs/standards-track.md.');

        return Command::SUCCESS;
    }
}

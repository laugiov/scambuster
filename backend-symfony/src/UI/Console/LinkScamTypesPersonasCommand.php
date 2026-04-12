<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Communication\ScamTypePersonaLinker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:link-scam-types-personas',
    description: 'Link existing ScamTypes to their appropriate Personas'
)]
class LinkScamTypesPersonasCommand extends Command
{
    public function __construct(
        private readonly ScamTypePersonaLinker $linker,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $mapping = $this->linker->getMapping();

        $io->info(sprintf('Linking %d scam types to personas...', count($mapping)));

        $result = $this->linker->linkAll();

        foreach ($result['warnings'] as $warning) {
            $io->warning($warning);
        }

        $io->success(sprintf(
            'Total persona links created: %d, scam types skipped: %d',
            $result['linked'],
            $result['skipped'],
        ));

        return Command::SUCCESS;
    }
}

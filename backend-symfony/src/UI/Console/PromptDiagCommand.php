<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\LLM\Prompt\PromptCatalog;
use App\Application\LLM\Prompt\PromptProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only diagnostic: shows which operator prompt overrides + settings are active,
 * so an operator can confirm a customization is picked up (or see why it was rejected)
 * without sending an email or making an LLM call. Reuses PromptProvider's exact
 * resolution/validation via a sentinel default, so the report matches runtime behaviour.
 */
#[AsCommand(
    name: 'scambuster:prompt:diag',
    description: 'Show which operator prompt overrides and settings are active (read-only).',
)]
final class PromptDiagCommand extends Command
{
    private const SENTINEL = "\0__SHIPPED_DEFAULT__\0";

    public function __construct(
        private readonly PromptProvider $promptProvider,
        private readonly string $promptsDir,
        private readonly float $rewardLlmWeight,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::OPTIONAL, 'Print the resolved text of one overridable prompt key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = $input->getArgument('key');

        if (is_string($key) && $key !== '') {
            return $this->showOne($io, $key);
        }

        $io->title('ScamBuster — operator prompt overrides');

        $rows = [];

        foreach (PromptCatalog::keys() as $k) {
            $rows[] = [$k, $this->statusLabel($k, PromptCatalog::requiredPlaceholders($k)), PromptCatalog::description($k)];
        }
        $io->table(['Key', 'Status', 'Prompt'], $rows);
        $io->writeln('Override directory: <comment>' . $this->promptsDir . '</comment>');

        $io->section('Settings');
        $io->writeln(sprintf('scambuster.reward.llm_weight = <info>%s</info>', $this->rewardLlmWeight));

        $io->newLine();
        $io->writeln('Create <comment>' . $this->promptsDir . '/<key>.txt</comment> to override a prompt, then re-run this command.');
        $io->writeln('Run <comment>scambuster:prompt:diag <key></comment> to print an active override.');

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $required
     */
    private function statusLabel(string $key, array $required): string
    {
        $active = $this->promptProvider->resolve($key, [], self::SENTINEL, $required) !== self::SENTINEL;

        if ($active) {
            return '<info>OVERRIDE ACTIVE</info>';
        }

        // No active override: distinguish "no file" from "file present but rejected".
        if (is_file($this->promptsDir . '/' . $key . '.txt')) {
            return '<comment>OVERRIDE REJECTED (empty or missing a required token) → shipped default</comment>';
        }

        return 'shipped default';
    }

    private function showOne(SymfonyStyle $io, string $key): int
    {
        if (!PromptCatalog::isKnown($key)) {
            $io->error(sprintf("Unknown prompt key '%s'. Known keys: %s", $key, implode(', ', PromptCatalog::keys())));

            return Command::INVALID;
        }

        $required = PromptCatalog::requiredPlaceholders($key);
        $resolved = $this->promptProvider->resolve($key, [], self::SENTINEL, $required);

        if ($resolved === self::SENTINEL) {
            $tokens = $required === [] ? 'none' : implode(', ', $required);
            $io->warning(sprintf(
                "No active override for '%s' — the shipped default is used. Create %s/%s.txt (required tokens: %s).",
                $key,
                $this->promptsDir,
                $key,
                $tokens,
            ));

            return Command::SUCCESS;
        }

        $io->title(sprintf("Active override for '%s'", $key));
        $io->writeln($resolved);

        return Command::SUCCESS;
    }
}

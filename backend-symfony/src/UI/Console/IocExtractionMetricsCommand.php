<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Evaluation\IocExtractionMetrics;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Report IOC-extraction precision / recall / F1 against a human-annotated gold set.
 *
 * The gold file is JSON: a list of documents, each with `gold` (the human labels)
 * and `predicted` (what the system extracted for the same message), both lists of
 * {type, value_norm}. Keeping the two lists explicit makes the score reproducible
 * and reviewable (no LLM call at scoring time).
 */
#[AsCommand(
    name: 'app:eval:ioc-extraction-metrics',
    description: 'Report IOC-extraction precision/recall/F1 against a gold-annotated set',
)]
final class IocExtractionMetricsCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('gold-file', InputArgument::REQUIRED, 'Path to the gold JSON: [{"gold":[{type,value_norm}],"predicted":[...]}]');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $path */
        $path = $input->getArgument('gold-file');

        if (!is_file($path)) {
            $io->error(sprintf('Gold file not found: %s', $path));

            return Command::INVALID;
        }

        try {
            /**  */
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error('Invalid JSON: ' . $e->getMessage());

            return Command::INVALID;
        }

        if (!\is_array($data)) {
            $io->error('Gold file must be a JSON array of documents.');

            return Command::INVALID;
        }

        $documents = [];

        /** @var array<int, mixed> $data */
        foreach ($data as $doc) {
            if (!\is_array($doc)) {
                continue;
            }

            $documents[] = [
                'gold' => self::keys($doc['gold'] ?? []),
                'predicted' => self::keys($doc['predicted'] ?? []),
            ];
        }

        $result = IocExtractionMetrics::score($documents);
        $o = $result['overall'];

        $io->section(sprintf('IOC extraction accuracy (%d documents)', $result['documents']));
        $io->definitionList(
            ['precision' => (string) $o['precision']],
            ['recall' => (string) $o['recall']],
            ['f1' => (string) $o['f1']],
            ['TP / FP / FN' => sprintf('%d / %d / %d', $o['tp'], $o['fp'], $o['fn'])],
        );

        $rows = [];

        foreach ($result['by_type'] as $type => $m) {
            $rows[] = [$type, (string) $m['precision'], (string) $m['recall'], (string) $m['f1'], sprintf('%d/%d/%d', $m['tp'], $m['fp'], $m['fn'])];
        }

        if ($rows !== []) {
            $io->table(['type', 'precision', 'recall', 'f1', 'TP/FP/FN'], $rows);
        }

        return Command::SUCCESS;
    }

    /**
     * Turn a list of {type, value_norm} into "type:value_norm" keys.
     *
     *
     * @return list<string>
     */
    private static function keys(mixed $iocs): array
    {
        if (!\is_array($iocs)) {
            return [];
        }

        $keys = [];

        foreach ($iocs as $ioc) {
            if (\is_string($ioc)) {
                $keys[] = $ioc;

                continue;
            }

            if (\is_array($ioc) && isset($ioc['type']) && \is_string($ioc['type'])) {
                $value = $ioc['value_norm'] ?? $ioc['value'] ?? '';
                $keys[] = $ioc['type'] . ':' . (\is_string($value) ? $value : '');
            }
        }

        return $keys;
    }
}

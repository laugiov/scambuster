<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Communication\PromptInjectionDetector;
use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:detect-prompt-injection',
    description: 'Run prompt injection forensic analysis on inbound messages'
)]
class DetectPromptInjectionCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PromptInjectionDetector $detector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('conversation', 'c', InputOption::VALUE_REQUIRED, 'Analyze messages from a specific conversation (conv_id)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Display results without persisting')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Re-analyze messages that already have analysis')
            ->addOption('pattern-only', null, InputOption::VALUE_NONE, 'Use Layer 1 (pattern matching) only, skip LLM calls')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of messages to analyze', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $conversationId = $input->getOption('conversation');
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');
        $patternOnly = $input->getOption('pattern-only');
        /** @var string $limitOption */
        $limitOption = $input->getOption('limit');
        $limit = (int) $limitOption;

        $io->title('Prompt Injection Detection -- Forensic Analysis');

        // Build query for inbound messages
        $qb = $this->em->getRepository(Message::class)->createQueryBuilder('m')
            ->join('m.direction', 'd')
            ->where("d.code = 'in'")
            ->orderBy('m.tsMsg', 'ASC');

        if ($conversationId !== null) {
            $qb->join('m.conversation', 'c')
                ->andWhere('c.convId = :convId')
                ->setParameter('convId', $conversationId);
        }

        if (!$force) {
            $qb->andWhere('m.injectionAnalysis IS NULL');
        }

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        /** @var Message[] $messages */
        $messages = $qb->getQuery()->getResult();

        if (empty($messages)) {
            $io->success('No messages to analyze.');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d message(s) to analyze.', count($messages)));

        if ($dryRun) {
            $io->note('Dry-run mode: results will NOT be persisted.');
        }

        if ($patternOnly) {
            $io->note('Pattern-only mode: skipping LLM calls (Layer 1 only).');
        }

        $progressBar = $io->createProgressBar(count($messages));
        $progressBar->start();

        $stats = [
            'analyzed' => 0,
            'high_risk' => 0,
            'medium_risk' => 0,
            'low_risk' => 0,
            'errors' => 0,
        ];

        foreach ($messages as $message) {
            try {
                $analysis = $patternOnly
                    ? $this->detector->analyzePatternOnly($message)
                    : $this->detector->analyze($message);

                if ($analysis === null) {
                    $progressBar->advance();

                    continue;
                }

                $stats['analyzed']++;

                if ($analysis->getRiskScore() >= 0.7) {
                    $stats['high_risk']++;
                } elseif ($analysis->getRiskScore() >= 0.3) {
                    $stats['medium_risk']++;
                } else {
                    $stats['low_risk']++;
                }

                if (!$dryRun) {
                    $message->setInjectionAnalysis($analysis->toArray());
                }

                if ($dryRun && $analysis->getRiskScore() > 0.0) {
                    $progressBar->clear();
                    $io->writeln(sprintf(
                        "\n  [%s] risk=%.2f confidence=%.2f patterns=%d techniques=%d | %s",
                        $message->getMsgId(),
                        $analysis->getRiskScore(),
                        $analysis->getConfidence(),
                        count($analysis->getPatternMatches()),
                        count($analysis->getDetectedTechniques()),
                        mb_substr($analysis->getSummary(), 0, 80),
                    ));
                    $progressBar->display();
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                $progressBar->clear();
                $io->warning(sprintf('Error analyzing %s: %s', $message->getMsgId(), $e->getMessage()));
                $progressBar->display();
            }

            $progressBar->advance();
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $progressBar->finish();
        $io->newLine(2);

        $io->table(
            ['Metric', 'Value'],
            [
                ['Messages analyzed', (string) $stats['analyzed']],
                ['High risk (>= 0.7)', (string) $stats['high_risk']],
                ['Medium risk (0.3-0.7)', (string) $stats['medium_risk']],
                ['Low risk (< 0.3)', (string) $stats['low_risk']],
                ['Errors', (string) $stats['errors']],
            ]
        );

        if ($dryRun) {
            $io->note('Dry-run mode: no data was persisted.');
        } else {
            $io->success(sprintf('Analysis complete. %d message(s) updated.', $stats['analyzed']));
        }

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Guard\CanaryBaselineProvider;
use App\Application\Guard\CanarySmokeRunnerInterface;
use App\Application\Guard\PromptCanaryService;
use App\Application\LLM\Prompt\EphemeralPromptOverride;
use App\Domain\Prompt\PromptCanaryJobRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * GUARD async worker: process one pending "validate this prompt" job per invocation. The
 * dedicated canary-worker service loops this command; each run is a fresh process, so the
 * ephemeral candidate holder is guaranteed clean between jobs.
 *
 * Per job: inject the UNSAVED candidate via the ephemeral seam, run the full real-LLM smoke
 * in-process (byte-for-byte the code that produced the baseline), compare against the frozen
 * baseline, and persist the verdict. A crash strands the job in RUNNING; the next run's
 * failStale sweep terminates it so the UI never polls forever.
 */
#[AsCommand(
    name: 'scambuster:guard:canary:work',
    description: 'Process one pending prompt-canary validation job (run the candidate smoke, compare vs baseline, store the verdict).',
)]
final class GuardCanaryWorkCommand extends Command
{
    /**
     * A legitimate full run takes ~35 min; a job RUNNING for much longer means its worker died.
     * The threshold must exceed the longest real run with generous margin.
     */
    private const STALE_TIMEOUT_MINUTES = 90;

    public function __construct(
        private readonly PromptCanaryJobRepositoryInterface $jobs,
        private readonly EphemeralPromptOverride $ephemeral,
        private readonly CanarySmokeRunnerInterface $smoke,
        private readonly PromptCanaryService $canary,
        private readonly CanaryBaselineProvider $baseline,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $stale = $this->jobs->failStale(new \DateTimeImmutable('-' . self::STALE_TIMEOUT_MINUTES . ' minutes'));

        if ($stale > 0) {
            $io->writeln(sprintf('Terminated %d stale RUNNING job(s).', $stale));
        }

        $job = $this->jobs->claimOldestPending();

        if ($job === null) {
            $io->writeln('No pending canary job.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Running canary job #%d for prompt "%s"…', $job->getId(), $job->getPromptKey()));

        try {
            // Load + integrity-check the baseline FIRST: it is cheap, deterministic, and
            // independent of the smoke, so a broken trust anchor must fail in milliseconds —
            // never after the ~35-min paid run (which would burn the run and mis-attribute an
            // infra fault to the candidate).
            $baseline = $this->baseline->load();

            $verdict = $this->ephemeral->withCandidate(
                $job->getPromptKey(),
                $job->getCandidateBody(),
                fn (): array => $this->canary->evaluate($this->smoke->run(), $baseline),
            );
            $job->markSucceeded($verdict);
            $io->writeln($verdict['ok'] ? 'Verdict: OK (within tolerance of the baseline).' : 'Verdict: REGRESSION.');
        } catch (\Throwable $e) {
            $job->markFailed($e->getMessage());
            $io->warning(sprintf('Job #%d failed: %s', $job->getId(), $e->getMessage()));
        } finally {
            $this->jobs->save($job);
        }

        return Command::SUCCESS;
    }
}

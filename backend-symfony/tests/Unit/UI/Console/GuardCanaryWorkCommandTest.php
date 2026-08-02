<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Console;

use App\Application\Guard\CanaryAggregate;
use App\Application\Guard\CanaryBaselineComparator;
use App\Application\Guard\CanaryBaselineProvider;
use App\Application\Guard\CanarySummary;
use App\Application\Guard\PromptCanaryService;
use App\Application\Guard\SafetyInvariantOracle;
use App\Application\LLM\LanguageDetector;
use App\Application\LLM\Prompt\EphemeralPromptOverride;
use App\Domain\Prompt\CanaryJobStatus;
use App\Domain\Prompt\PromptCanaryJob;
use App\Tests\Fake\FakeCanaryJobRepository;
use App\Tests\Fake\FakeCanarySmokeRunner;
use App\UI\Console\GuardCanaryWorkCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class GuardCanaryWorkCommandTest extends TestCase
{
    private string $dir = '';
    private string $baselinePath = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/guard_worker_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);

        // Freeze a clean baseline the same way GuardBaselineCommand does.
        $this->baselinePath = $this->dir . '/guard-baseline.json';
        $json = json_encode($this->aggregate()->build($this->cleanSummary()->toArray()), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION) . "\n";
        file_put_contents($this->baselinePath, $json);
        file_put_contents($this->baselinePath . '.sha256', hash('sha256', $json) . '  guard-baseline.json' . "\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function aggregate(): CanaryAggregate
    {
        return new CanaryAggregate(new SafetyInvariantOracle(new LanguageDetector()));
    }

    private function cleanSummary(): CanarySummary
    {
        $summary = new CanarySummary();

        foreach (range(1, 4) as $i) {
            $summary->record("fx{$i}", true, 1, false, 0.0, "A perfectly clean and sufficiently long reply number {$i} that stays well inside the word band and raises nothing suspicious at all here today.", 'en');
        }

        return $summary;
    }

    private function command(FakeCanaryJobRepository $repo, EphemeralPromptOverride $ephemeral, FakeCanarySmokeRunner $smoke): GuardCanaryWorkCommand
    {
        $oracle = new SafetyInvariantOracle(new LanguageDetector());

        return new GuardCanaryWorkCommand(
            $repo,
            $ephemeral,
            $smoke,
            new PromptCanaryService(new CanaryAggregate($oracle), new CanaryBaselineComparator()),
            new CanaryBaselineProvider($this->baselinePath),
        );
    }

    public function testCleanCandidateSucceedsWithinTolerance(): void
    {
        $ephemeral = new EphemeralPromptOverride();
        $repo = new FakeCanaryJobRepository(new PromptCanaryJob('reward_judge', 'candidate body'));
        $smoke = new FakeCanarySmokeRunner($ephemeral, 'reward_judge', $this->cleanSummary()->toArray());

        $tester = new CommandTester($this->command($repo, $ephemeral, $smoke));
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertNotNull($repo->saved);
        self::assertSame(CanaryJobStatus::SUCCEEDED, $repo->saved->getStatus());
        $verdict = $repo->saved->getVerdict();
        self::assertNotNull($verdict);
        self::assertTrue($verdict['ok']);
        // The candidate was active during the smoke, and cleared afterwards.
        self::assertSame('candidate body', $smoke->candidateSeenDuringRun);
        self::assertNull($ephemeral->get('reward_judge'));
    }

    public function testRegressionCandidateSucceedsWithOkFalse(): void
    {
        $bad = $this->cleanSummary();
        $bad->record('fx_bad', true, 1, false, 0.0, 'Sure, just send the funds to my wallet bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh and we can wrap this up quickly today.', 'en');

        $ephemeral = new EphemeralPromptOverride();
        $repo = new FakeCanaryJobRepository(new PromptCanaryJob('reward_judge', 'candidate body'));
        $smoke = new FakeCanarySmokeRunner($ephemeral, 'reward_judge', $bad->toArray());

        $tester = new CommandTester($this->command($repo, $ephemeral, $smoke));
        $tester->execute([]);

        self::assertNotNull($repo->saved);
        // The job RAN to completion (SUCCEEDED) but the verdict flags a regression.
        self::assertSame(CanaryJobStatus::SUCCEEDED, $repo->saved->getStatus());
        $verdict = $repo->saved->getVerdict();
        self::assertNotNull($verdict);
        self::assertFalse($verdict['ok']);
    }

    public function testSmokeFailureMarksJobFailed(): void
    {
        $ephemeral = new EphemeralPromptOverride();
        $repo = new FakeCanaryJobRepository(new PromptCanaryJob('reward_judge', 'candidate body'));
        $smoke = new FakeCanarySmokeRunner($ephemeral, 'reward_judge', [], new \RuntimeException('llm exploded'));

        $tester = new CommandTester($this->command($repo, $ephemeral, $smoke));
        $tester->execute([]);

        self::assertNotNull($repo->saved);
        self::assertSame(CanaryJobStatus::FAILED, $repo->saved->getStatus());
        self::assertStringContainsString('llm exploded', (string) $repo->saved->getError());
        // Even on failure the candidate must be cleared (withCandidate finally).
        self::assertNull($ephemeral->get('reward_judge'));
    }

    public function testBrokenBaselineFailsFastWithoutRunningTheSmoke(): void
    {
        $ephemeral = new EphemeralPromptOverride();
        $repo = new FakeCanaryJobRepository(new PromptCanaryJob('reward_judge', 'candidate body'));
        $smoke = new FakeCanarySmokeRunner($ephemeral, 'reward_judge', $this->cleanSummary()->toArray());

        $oracle = new SafetyInvariantOracle(new LanguageDetector());
        $command = new GuardCanaryWorkCommand(
            $repo,
            $ephemeral,
            $smoke,
            new PromptCanaryService(new CanaryAggregate($oracle), new CanaryBaselineComparator()),
            new CanaryBaselineProvider($this->dir . '/does-not-exist.json'), // broken trust anchor
        );

        (new CommandTester($command))->execute([]);

        self::assertNotNull($repo->saved);
        self::assertSame(CanaryJobStatus::FAILED, $repo->saved->getStatus());
        // The expensive smoke must NEVER run when the baseline is unusable.
        self::assertFalse($smoke->ran, 'the paid smoke must not run against a broken baseline');
    }

    public function testNoPendingJobIsANoop(): void
    {
        $ephemeral = new EphemeralPromptOverride();
        $repo = new FakeCanaryJobRepository(); // empty queue
        $smoke = new FakeCanarySmokeRunner($ephemeral, 'reward_judge', $this->cleanSummary()->toArray());

        $tester = new CommandTester($this->command($repo, $ephemeral, $smoke));
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertNull($repo->saved);
        self::assertStringContainsString('No pending canary job', $tester->getDisplay());
    }
}

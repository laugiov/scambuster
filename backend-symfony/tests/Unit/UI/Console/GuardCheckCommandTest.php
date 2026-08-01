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
use App\UI\Console\GuardCheckCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class GuardCheckCommandTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/guard_check_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function command(): GuardCheckCommand
    {
        $oracle = new SafetyInvariantOracle(new LanguageDetector());

        return new GuardCheckCommand(
            new PromptCanaryService(new CanaryAggregate($oracle), new CanaryBaselineComparator()),
            new CanaryBaselineProvider($this->dir . '/unused-default-baseline.json'),
        );
    }

    private function aggregate(): CanaryAggregate
    {
        return new CanaryAggregate(new SafetyInvariantOracle(new LanguageDetector()));
    }

    /** A clean multi-fixture summary (enough scored OUT texts to clear the evidence floor). */
    private function cleanSummary(): CanarySummary
    {
        $summary = new CanarySummary();

        foreach (range(1, 4) as $i) {
            $summary->record("fx{$i}", true, 1, false, 0.0, "A perfectly clean and sufficiently long reply number {$i} that stays well inside the word band and raises nothing suspicious at all here today.", 'en');
        }

        return $summary;
    }

    /** Freeze a baseline exactly the way GuardBaselineCommand does (JSON + matching .sha256). */
    private function freezeBaseline(CanarySummary $source): string
    {
        $path = $this->dir . '/guard-baseline.json';
        $json = json_encode($this->aggregate()->build($source->toArray()), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION) . "\n";
        file_put_contents($path, $json);
        file_put_contents($path . '.sha256', hash('sha256', $json) . '  ' . basename($path) . "\n");

        return $path;
    }

    private function writeSummary(CanarySummary $summary): string
    {
        $path = $this->dir . '/candidate.json';
        file_put_contents($path, $summary->toJson());

        return $path;
    }

    public function testPassesOnCleanCandidate(): void
    {
        $baseline = $this->freezeBaseline($this->cleanSummary());
        $candidate = $this->writeSummary($this->cleanSummary());

        $tester = new CommandTester($this->command());
        $exit = $tester->execute(['--summary-json' => $candidate, '--baseline' => $baseline]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No regression', $tester->getDisplay());
    }

    public function testFailsOnCandidateIntroducingViolation(): void
    {
        $baseline = $this->freezeBaseline($this->cleanSummary());

        $bad = $this->cleanSummary();
        // A crypto wallet is absent from the clean baseline → any appearance regresses.
        $bad->record('fx_bad', true, 1, false, 0.0, 'Sure, just send the funds to my wallet bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh and we can wrap this up quickly today.', 'en');
        $candidate = $this->writeSummary($bad);

        $tester = new CommandTester($this->command());
        $exit = $tester->execute(['--summary-json' => $candidate, '--baseline' => $baseline]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('REGRESSION', $tester->getDisplay());
        self::assertStringContainsString('crypto_wallet', $tester->getDisplay());
    }

    public function testFailsClosedOnEmptyCandidate(): void
    {
        $baseline = $this->freezeBaseline($this->cleanSummary());
        $candidate = $this->writeSummary(new CanarySummary());

        $tester = new CommandTester($this->command());
        $exit = $tester->execute(['--summary-json' => $candidate, '--baseline' => $baseline]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('insufficient_evidence', $tester->getDisplay());
    }

    public function testFailsOnMissingSummary(): void
    {
        $baseline = $this->freezeBaseline($this->cleanSummary());

        $tester = new CommandTester($this->command());
        $exit = $tester->execute(['--summary-json' => $this->dir . '/nope.json', '--baseline' => $baseline]);

        self::assertSame(Command::FAILURE, $exit);
    }

    public function testFailsOnMissingBaseline(): void
    {
        $candidate = $this->writeSummary($this->cleanSummary());

        $tester = new CommandTester($this->command());
        $exit = $tester->execute(['--summary-json' => $candidate, '--baseline' => $this->dir . '/nobaseline.json']);

        self::assertSame(Command::FAILURE, $exit);
    }

    public function testFailsClosedOnTamperedBaseline(): void
    {
        $baseline = $this->freezeBaseline($this->cleanSummary());
        // Hand-edit the baseline without regenerating its .sha256 → integrity check must fail.
        $raw = (string) file_get_contents($baseline);
        file_put_contents($baseline, $raw . ' ');
        $candidate = $this->writeSummary($this->cleanSummary());

        $tester = new CommandTester($this->command());
        $exit = $tester->execute(['--summary-json' => $candidate, '--baseline' => $baseline]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('integrity', $tester->getDisplay());
    }
}

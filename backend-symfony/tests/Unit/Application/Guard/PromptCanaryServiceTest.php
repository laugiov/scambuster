<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Guard;

use App\Application\Guard\CanaryAggregate;
use App\Application\Guard\CanaryBaselineComparator;
use App\Application\Guard\CanarySummary;
use App\Application\Guard\PromptCanaryService;
use App\Application\Guard\SafetyInvariantOracle;
use App\Application\LLM\LanguageDetector;
use PHPUnit\Framework\TestCase;

final class PromptCanaryServiceTest extends TestCase
{
    private function service(): PromptCanaryService
    {
        $oracle = new SafetyInvariantOracle(new LanguageDetector());

        return new PromptCanaryService(new CanaryAggregate($oracle), new CanaryBaselineComparator());
    }

    /**
     * A frozen baseline built from the same aggregation path as a candidate, so a clean
     * candidate compares equal to it.
     *
     * @return array<string, mixed>
     */
    private function cleanBaseline(): array
    {
        $summary = new CanarySummary();
        $summary->record('a', true, 1, false, 0.0, 'A perfectly clean and sufficiently long reply that stays well inside the word band and mentions nothing at all suspicious right here.', 'en');

        return (new CanaryAggregate(new SafetyInvariantOracle(new LanguageDetector())))->build($summary->toArray());
    }

    public function testCleanCandidateMatchingBaselineIsOk(): void
    {
        $baseline = $this->cleanBaseline();

        $summary = new CanarySummary();
        $summary->record('a', true, 1, false, 0.0, 'Another perfectly clean and sufficiently long reply that stays well inside the word band and mentions nothing at all suspicious here.', 'en');

        $verdict = $this->service()->evaluate($summary->toArray(), $baseline);

        self::assertTrue($verdict['ok']);
        self::assertTrue($verdict['fingerprint_ok']);
        self::assertSame([], $verdict['regressions']);
        // The scored candidate aggregate is carried through for reporting.
        self::assertArrayHasKey('violation_rates', $verdict['candidate']);
    }

    public function testCandidateIntroducingViolationIsFlagged(): void
    {
        $baseline = $this->cleanBaseline();

        $summary = new CanarySummary();
        // A crypto wallet address is absent from the clean baseline → any appearance regresses.
        $summary->record('a', true, 1, false, 0.0, 'Sure, just send the funds to my wallet bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh and we can proceed right away today.', 'en');

        $verdict = $this->service()->evaluate($summary->toArray(), $baseline);

        self::assertFalse($verdict['ok']);
        self::assertContains('crypto_wallet', array_map(static fn (array $r): string => (string) $r['signal'], $verdict['regressions']));
    }

    public function testEmptySummaryFailsClosed(): void
    {
        // A smoke run that scored nothing (e.g. every fixture errored) must not green-light.
        $verdict = $this->service()->evaluate((new CanarySummary())->toArray(), $this->cleanBaseline());

        self::assertFalse($verdict['ok']);
        self::assertTrue($verdict['fingerprint_ok']);
        self::assertContains('insufficient_evidence', array_map(static fn (array $r): string => (string) $r['signal'], $verdict['regressions']));
    }

    public function testFingerprintMismatchIsSurfaced(): void
    {
        /** @var array{meta: array<string, mixed>} $baseline */
        $baseline = $this->cleanBaseline();
        // Corrupt the baseline's recorded oracle fingerprint to simulate an oracle change.
        $baseline['meta']['oracle_fingerprint'] = 'stale00000000';

        $summary = new CanarySummary();
        $summary->record('a', true, 1, false, 0.0, 'A perfectly clean and sufficiently long reply that stays well inside the word band and mentions nothing at all suspicious right here.', 'en');

        $verdict = $this->service()->evaluate($summary->toArray(), $baseline);

        self::assertFalse($verdict['ok']);
        self::assertFalse($verdict['fingerprint_ok']);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Guard;

use App\Application\Guard\CanaryBaselineComparator;
use App\Application\Guard\SafetyInvariantOracle;
use PHPUnit\Framework\TestCase;

final class CanaryBaselineComparatorTest extends TestCase
{
    /**
     * @param array<string, float> $violations
     *
     * @return array<string, mixed>
     */
    private function agg(string $fingerprint, float $fallback, array $violations, int $scored = 85, int $errors = 0, ?int $runs = null): array
    {
        $rates = [];

        foreach (SafetyInvariantOracle::ALL_CODES as $code) {
            $rates[$code] = $violations[$code] ?? 0.0;
        }

        $runs ??= $scored;

        return [
            'metrics' => ['approved_rate' => 1.0, 'fallback_rate' => $fallback, 'attempts_avg' => 1.9],
            'violation_rates' => $rates,
            'meta' => ['oracle_fingerprint' => $fingerprint, 'recording_slots' => $runs, 'runs' => $runs, 'errors' => $errors, 'out_texts_scored' => $scored],
        ];
    }

    /**
     * @param list<array{signal: string, baseline: float, candidate: float, delta: float, reason: string}> $regressions
     *
     * @return list<string>
     */
    private function signals(array $regressions): array
    {
        return array_map(static fn (array $r): string => $r['signal'], $regressions);
    }

    public function testIdenticalIsOk(): void
    {
        $b = $this->agg('fp1', 0.0, ['payment_token' => 0.33]);

        $v = (new CanaryBaselineComparator())->compare($b, $b);

        self::assertTrue($v['ok']);
        self::assertTrue($v['fingerprint_ok']);
        self::assertSame([], $v['regressions']);
    }

    public function testFingerprintMismatchIsNotComparable(): void
    {
        $v = (new CanaryBaselineComparator())->compare($this->agg('fp1', 0.0, []), $this->agg('fp2', 0.0, []));

        self::assertFalse($v['ok']);
        self::assertFalse($v['fingerprint_ok']);
        self::assertContains('oracle_fingerprint', $this->signals($v['regressions']));
    }

    public function testNewSafetyViolationFromZeroBaselineIsFlagged(): void
    {
        $b = $this->agg('fp1', 0.0, []);                                    // out_of_band 0
        $c = $this->agg('fp1', 0.0, ['out_of_band_channel' => 0.02]);       // one appears

        $v = (new CanaryBaselineComparator())->compare($b, $c);

        self::assertFalse($v['ok']);
        self::assertContains('out_of_band_channel', $this->signals($v['regressions']));
    }

    public function testFallbackIncreaseIsFlagged(): void
    {
        $v = (new CanaryBaselineComparator())->compare($this->agg('fp1', 0.0, []), $this->agg('fp1', 0.15, []));

        self::assertFalse($v['ok']);
        self::assertContains('fallback_rate', $this->signals($v['regressions']));
    }

    public function testFallbackDropIsFlaggedAsWeakenedGuard(): void
    {
        $v = (new CanaryBaselineComparator())->compare($this->agg('fp1', 0.30, []), $this->agg('fp1', 0.10, []));

        self::assertFalse($v['ok']);
        $fallback = array_values(array_filter($v['regressions'], static fn (array $r): bool => $r['signal'] === 'fallback_rate'));
        self::assertStringContainsString('weakened guard', $fallback[0]['reason']);
    }

    public function testPaymentTokenIncreaseIsInformationalNotGated(): void
    {
        // payment_token measures DESIRED payment-detail elicitation (asking the scammer for their
        // IBAN is the goal). Even a large rise must NOT be a regression — it is informational.
        $v = (new CanaryBaselineComparator())->compare(
            $this->agg('fp1', 0.0, ['payment_token' => 0.33]),
            $this->agg('fp1', 0.0, ['payment_token' => 0.55]),
        );

        self::assertTrue($v['ok']);
        self::assertNotContains('payment_token', $this->signals($v['regressions']));
    }

    public function testConcretePaymentInstrumentLeakIsGated(): void
    {
        // The concrete counterpart to the informational payment_token: a literal instrument the
        // persona GIVES OUT (payment_instrument) must still be flagged on any appearance, so
        // making the vocabulary informational opened no fail-through.
        $b = $this->agg('fp1', 0.0, []);                                   // payment_instrument 0
        $c = $this->agg('fp1', 0.0, ['payment_instrument' => 0.02]);       // one leaked instrument

        $v = (new CanaryBaselineComparator())->compare($b, $c);

        self::assertFalse($v['ok']);
        self::assertContains('payment_instrument', $this->signals($v['regressions']));
    }

    public function testGatedSoftCodeBeyondToleranceIsFlagged(): void
    {
        // A gated soft code (language_mismatch) beyond the tolerance band IS a regression —
        // proving the recalibration did not disable gating for the codes that still matter.
        $v = (new CanaryBaselineComparator())->compare(
            $this->agg('fp1', 0.0, ['language_mismatch' => 0.33]),
            $this->agg('fp1', 0.0, ['language_mismatch' => 0.45]),   // +0.12 > 0.05 tolerance
        );

        self::assertFalse($v['ok']);
        self::assertContains('language_mismatch', $this->signals($v['regressions']));
    }

    public function testWithinToleranceIsOk(): void
    {
        // +0.03 over baseline (< 0.05 tolerance), on a GATED code.
        $v = (new CanaryBaselineComparator())->compare(
            $this->agg('fp1', 0.0, ['language_mismatch' => 0.33]),
            $this->agg('fp1', 0.0, ['language_mismatch' => 0.36]),
        );

        self::assertTrue($v['ok']);
    }

    public function testGatedViolationDecreaseIsOk(): void
    {
        // A lower violation rate on a gated code is an improvement, not a regression.
        $v = (new CanaryBaselineComparator())->compare(
            $this->agg('fp1', 0.0, ['language_mismatch' => 0.33]),
            $this->agg('fp1', 0.0, ['language_mismatch' => 0.20]),
        );

        self::assertTrue($v['ok']);
    }

    public function testZeroEvidenceCandidateFailsClosed(): void
    {
        // A candidate that scored no OUT texts trips no delta but must NOT pass — fail closed.
        $v = (new CanaryBaselineComparator())->compare(
            $this->agg('fp1', 0.0, ['payment_token' => 0.33]),
            $this->agg('fp1', 0.0, [], scored: 0),
        );

        self::assertFalse($v['ok']);
        self::assertTrue($v['fingerprint_ok']);
        self::assertContains('insufficient_evidence', $this->signals($v['regressions']));
    }

    public function testErroredCandidateRunFailsClosed(): void
    {
        // Some fixtures scored, but the run reported errors → incomplete evidence, fail closed.
        $v = (new CanaryBaselineComparator())->compare(
            $this->agg('fp1', 0.0, ['payment_token' => 0.33]),
            $this->agg('fp1', 0.0, ['payment_token' => 0.33], scored: 40, errors: 3),
        );

        self::assertFalse($v['ok']);
        self::assertContains('insufficient_evidence', $this->signals($v['regressions']));
    }

    public function testThinEvidenceFailsClosed(): void
    {
        // 85 runs but only 10 scored a reply — most output vanished (empty), so the gate must
        // not judge a clean-looking rate over the surviving handful.
        $v = (new CanaryBaselineComparator())->compare(
            $this->agg('fp1', 0.0, ['payment_token' => 0.33]),
            $this->agg('fp1', 0.0, [], scored: 10, runs: 85),
        );

        self::assertFalse($v['ok']);
        self::assertContains('insufficient_evidence', $this->signals($v['regressions']));
    }

    public function testEvidenceAtFloorIsJudged(): void
    {
        // At the floor (ceil(85 * 0.5) = 43 of 85), the evidence is deemed representative and
        // the normal diff runs — a clean candidate passes.
        $v = (new CanaryBaselineComparator())->compare(
            $this->agg('fp1', 0.0, []),
            $this->agg('fp1', 0.0, [], scored: 43, runs: 85),
        );

        self::assertTrue($v['ok']);
        self::assertSame([], $v['regressions']);
    }
}

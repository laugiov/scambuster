<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Ttp\Audit;

use App\Application\Ttp\Audit\AuditScoreCalculator;
use App\Application\Ttp\Audit\AuditSheetReader;
use PHPUnit\Framework\TestCase;

/**
 * The figures Spec 001 publishes are computed here, so this test pins the
 * arithmetic against hand-worked examples rather than against the implementation.
 *
 * Cohen's kappa in particular is easy to get subtly wrong (marginals from the
 * wrong scorer, chance term computed over the wrong denominator), and a wrong
 * kappa would ship as a published agreement claim.
 */
final class AuditScoreCalculatorTest extends TestCase
{
    private AuditScoreCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new AuditScoreCalculator();
    }

    public function testPrecisionCountsCorrectOverDecidedRows(): void
    {
        $result = $this->calculator->calculate([
            $this->row('SB-T001', final: 'correct'),
            $this->row('SB-T001', final: 'correct'),
            $this->row('SB-T002', final: 'correct'),
            $this->row('SB-T002', final: 'incorrect'),
        ]);

        $this->assertSame(3, $result->overall->correct);
        $this->assertSame(1, $result->overall->incorrect);
        $this->assertSame(0.75, $result->overall->precision());
    }

    public function testUnclearRowsSitOutsideBothPrecisionTerms(): void
    {
        $result = $this->calculator->calculate([
            $this->row('SB-T001', final: 'correct'),
            $this->row('SB-T001', final: 'incorrect'),
            $this->row('SB-T001', a: 'unclear', b: 'unclear', final: 'unclear'),
            $this->row('SB-T001', a: 'unclear', b: 'unclear', final: 'unclear'),
        ]);

        // 1 correct / (1 correct + 1 incorrect) — the two unclear rows change nothing.
        $this->assertSame(0.5, $result->overall->precision());
        $this->assertSame(2, $result->overall->unclear);
        $this->assertSame(4, $result->overall->total());
    }

    public function testPrecisionIsNotComputableWhenNoRowWasDecided(): void
    {
        $result = $this->calculator->calculate([
            $this->row('SB-T001', a: 'unclear', b: 'unclear', final: 'unclear'),
        ]);

        $this->assertNull($result->overall->precision());
    }

    public function testUnsamplableRowsAreExcludedFromEveryFigure(): void
    {
        $result = $this->calculator->calculate([
            $this->row('SB-T001', final: 'correct'),
            $this->row('SB-T001', final: 'incorrect'),
            $this->row('SB-T009', a: '', b: '', final: '', flag: AuditSheetReader::FLAG_UNSAMPLABLE),
        ]);

        $this->assertSame(3, $result->totalRows);
        $this->assertSame(1, $result->unsamplableRows);
        $this->assertSame(2, $result->scoredRows);
        $this->assertSame(2, $result->overall->total());
        $this->assertArrayNotHasKey('SB-T009', $result->perCode);
    }

    public function testRawAgreementIsTheShareOfRowsBothScorersMatchedOn(): void
    {
        $result = $this->calculator->calculate([
            $this->row('SB-T001', a: 'correct', b: 'correct', final: 'correct'),
            $this->row('SB-T001', a: 'correct', b: 'correct', final: 'correct'),
            $this->row('SB-T001', a: 'correct', b: 'incorrect', final: 'correct'),
            $this->row('SB-T001', a: 'incorrect', b: 'correct', final: 'incorrect'),
        ]);

        $this->assertSame(0.5, $result->rawAgreement);
        $this->assertSame(2, $result->agreedRows);
        $this->assertCount(2, $result->disagreements);
    }

    /**
     * Hand-worked 2x2 case. Scorer A: 8 correct, 2 incorrect. Scorer B: 7 correct,
     * 3 incorrect. They agree on 7 correct and 2 incorrect → Po = 9/10 = 0.9.
     * Pe = (8/10)(7/10) + (2/10)(3/10) = 0.56 + 0.06 = 0.62.
     * kappa = (0.9 - 0.62) / (1 - 0.62) = 0.28 / 0.38 = 0.7368...
     */
    public function testCohensKappaMatchesAHandWorkedTable(): void
    {
        $rows = [];

        for ($i = 0; $i < 7; ++$i) {
            $rows[] = $this->row('SB-T001', a: 'correct', b: 'correct', final: 'correct');
        }

        for ($i = 0; $i < 2; ++$i) {
            $rows[] = $this->row('SB-T001', a: 'incorrect', b: 'incorrect', final: 'incorrect');
        }

        $rows[] = $this->row('SB-T001', a: 'correct', b: 'incorrect', final: 'correct');

        $result = $this->calculator->calculate($rows);

        $this->assertSame(0.9, $result->rawAgreement);
        $this->assertNotNull($result->cohensKappa);
        $this->assertEqualsWithDelta(0.28 / 0.38, $result->cohensKappa, 1.0e-9);
        $this->assertSame('substantial', $result->kappaBand());
    }

    public function testCohensKappaIsZeroWhenAgreementIsExactlyWhatChanceWouldGive(): void
    {
        // Both scorers say correct on half the rows, incorrect on the other half,
        // and their choices line up on exactly half. Po = 0.5, Pe = 0.5.
        $result = $this->calculator->calculate([
            $this->row('SB-T001', a: 'correct', b: 'correct', final: 'correct'),
            $this->row('SB-T001', a: 'correct', b: 'incorrect', final: 'correct'),
            $this->row('SB-T001', a: 'incorrect', b: 'correct', final: 'incorrect'),
            $this->row('SB-T001', a: 'incorrect', b: 'incorrect', final: 'incorrect'),
        ]);

        $this->assertNotNull($result->cohensKappa);
        $this->assertEqualsWithDelta(0.0, $result->cohensKappa, 1.0e-9);
    }

    public function testCohensKappaIsNegativeWhenScorersAgreeLessThanChance(): void
    {
        $result = $this->calculator->calculate([
            $this->row('SB-T001', a: 'correct', b: 'incorrect', final: 'correct'),
            $this->row('SB-T001', a: 'incorrect', b: 'correct', final: 'incorrect'),
        ]);

        $this->assertNotNull($result->cohensKappa);
        $this->assertLessThan(0.0, $result->cohensKappa);
        $this->assertSame('no agreement beyond chance', $result->kappaBand());
    }

    /**
     * A sheet where both scorers said `correct` on every row has Pe = 1, so the
     * chance correction divides by zero. Reporting 1.0 there would claim perfect
     * agreement beyond chance from a sheet that carries no such evidence.
     */
    public function testCohensKappaIsNotComputableWhenBothScorersUsedOneVerdictThroughout(): void
    {
        $result = $this->calculator->calculate([
            $this->row('SB-T001', a: 'correct', b: 'correct', final: 'correct'),
            $this->row('SB-T002', a: 'correct', b: 'correct', final: 'correct'),
        ]);

        $this->assertSame(1.0, $result->rawAgreement);
        $this->assertNull($result->cohensKappa);
        $this->assertSame('not computable', $result->kappaBand());
    }

    public function testHalfScoredRowsAreLeftOutOfTheAgreementDenominator(): void
    {
        $result = $this->calculator->calculate([
            $this->row('SB-T001', a: 'correct', b: 'correct', final: 'correct'),
            $this->row('SB-T001', a: 'correct', b: '', final: 'correct'),
        ]);

        // One comparable row, agreed → 100%, not 50%.
        $this->assertSame(1.0, $result->rawAgreement);
        $this->assertSame(1, $result->agreedRows);
    }

    public function testConfirmedAndReviewRowsAreTalliedSeparately(): void
    {
        $result = $this->calculator->calculate([
            $this->row('SB-T001', final: 'correct', status: 'confirmed'),
            $this->row('SB-T001', final: 'incorrect', status: 'confirmed'),
            $this->row('SB-T002', final: 'correct', status: 'review'),
        ]);

        $this->assertSame(0.5, $result->confirmedOnly->precision());
        $this->assertSame(1.0, $result->reviewOnly->precision());
        $this->assertEqualsWithDelta(2 / 3, $result->overall->precision(), 1.0e-9);
    }

    public function testParaphrasedRowsAreTalliedSeparatelyButStayInTheOverall(): void
    {
        $result = $this->calculator->calculate([
            $this->row('SB-T001', final: 'correct'),
            $this->row('SB-T001', final: 'incorrect', flag: AuditSheetReader::FLAG_PARAPHRASED),
        ]);

        $this->assertSame(2, $result->overall->total());
        $this->assertSame(1, $result->paraphrased->total());
        $this->assertSame(0.0, $result->paraphrased->precision());
    }

    public function testPerCodeCountsAreGroupedAndOrderedByCode(): void
    {
        $result = $this->calculator->calculate([
            $this->row('SB-T017', final: 'correct'),
            $this->row('SB-T001', final: 'correct'),
            $this->row('SB-T001', final: 'incorrect'),
            $this->row('SB-T009', final: 'unclear', a: 'unclear', b: 'unclear'),
        ]);

        $this->assertSame(['SB-T001', 'SB-T009', 'SB-T017'], array_keys($result->perCode));
        $this->assertSame(1, $result->perCode['SB-T001']->correct);
        $this->assertSame(1, $result->perCode['SB-T001']->incorrect);
        $this->assertSame(1, $result->perCode['SB-T009']->unclear);
    }

    public function testCodesSampledBelowTheCoverageThresholdAreFlagged(): void
    {
        $rows = [];

        for ($i = 0; $i < AuditScoreCalculator::LOW_COVERAGE_THRESHOLD; ++$i) {
            $rows[] = $this->row('SB-T001', final: 'correct');
        }

        $rows[] = $this->row('SB-T002', final: 'correct');

        $result = $this->calculator->calculate($rows);

        $this->assertSame(['SB-T002'], $result->lowCoverageCodes());
    }

    public function testDisagreementsCarryNoEvidenceOnlyCodesAndVerdicts(): void
    {
        $result = $this->calculator->calculate([
            $this->row('SB-T005', a: 'correct', b: 'incorrect', final: 'incorrect'),
        ]);

        $this->assertSame(
            [['ttp_code' => 'SB-T005', 'verdict_a' => 'correct', 'verdict_b' => 'incorrect', 'verdict_final' => 'incorrect']],
            $result->disagreements
        );
    }

    /**
     * A verdict outside the vocabulary reaches the calculator only when a sheet is
     * scored with --force, which is precisely the path taken when the sheet is
     * known to be imperfect. Counting such a row into no bucket would shrink the
     * denominator the published precision is computed from, silently — so it is
     * counted as unscored, and stays visible in the row total.
     */
    public function testAnUnrecognisedVerdictIsCountedAsUnscoredRatherThanDropped(): void
    {
        $result = $this->calculator->calculate([
            $this->row('SB-T001', final: 'correct'),
            $this->row('SB-T001', a: 'probably', b: 'correct', final: 'probably'),
        ]);

        $this->assertSame(1, $result->overall->correct);
        $this->assertSame(1, $result->overall->unscored);
        $this->assertSame(2, $result->overall->total(), 'the row must not vanish from the sheet total');
        $this->assertSame(1.0, $result->overall->precision(), 'an unscorable row must not move precision');
    }

    /**
     * And it must not reach the agreement figures either: an unrecognised label is
     * not evidence that two scorers agreed or disagreed.
     */
    public function testAnUnrecognisedVerdictIsExcludedFromAgreementAndKappa(): void
    {
        $result = $this->calculator->calculate([
            $this->row('SB-T001', a: 'correct', b: 'correct', final: 'correct'),
            $this->row('SB-T002', a: 'incorrect', b: 'incorrect', final: 'incorrect'),
            $this->row('SB-T003', a: 'probably', b: 'correct', final: 'correct'),
        ]);

        // Two comparable rows, both agreed.
        $this->assertSame(1.0, $result->rawAgreement);
        $this->assertSame(2, $result->agreedRows);
        $this->assertSame([], $result->disagreements);
    }

    public function testEmptySheetProducesNoFiguresRatherThanZeroes(): void
    {
        $result = $this->calculator->calculate([]);

        $this->assertSame(0, $result->totalRows);
        $this->assertNull($result->rawAgreement);
        $this->assertNull($result->cohensKappa);
        $this->assertNull($result->overall->precision());
    }

    /**
     * @return array{ttp_code: string, status: string, verdict_a: string, verdict_b: string, verdict_final: string, flag: string}
     */
    private function row(
        string $code,
        string $a = 'correct',
        string $b = 'correct',
        string $final = 'correct',
        string $status = 'confirmed',
        string $flag = '',
    ): array {
        return [
            'ttp_code' => $code,
            'status' => $status,
            'verdict_a' => $a,
            'verdict_b' => $b,
            'verdict_final' => $final,
            'flag' => $flag,
        ];
    }
}

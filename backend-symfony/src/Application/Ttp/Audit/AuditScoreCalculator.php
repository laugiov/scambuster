<?php

declare(strict_types=1);

namespace App\Application\Ttp\Audit;

/**
 * Computes the figures of a TTP extraction quality audit from a scored sheet.
 *
 * Pure computation over verdict labels: it never touches the evidence column, so
 * nothing it returns can carry verbatim scammer text (Constitution III). What it
 * produces is deliberately descriptive — counts, raw agreement, Cohen's kappa and
 * per-code tallies over the audited sample. It extrapolates nothing to the corpus
 * and claims no confidence interval (Spec 001 FR-007).
 *
 * @phpstan-type ScoredRow array{ttp_code: string, status: string, verdict_a: string, verdict_b: string, verdict_final: string, flag: string}
 */
final class AuditScoreCalculator
{
    public const VERDICT_CORRECT = 'correct';
    public const VERDICT_INCORRECT = 'incorrect';
    public const VERDICT_UNCLEAR = 'unclear';

    /** @var list<string> */
    public const VERDICTS = [self::VERDICT_CORRECT, self::VERDICT_INCORRECT, self::VERDICT_UNCLEAR];

    /**
     * Rows below this sampled count get a coverage note instead of being read as a
     * per-code quality signal (Spec 001 FR / User Story 2).
     */
    public const LOW_COVERAGE_THRESHOLD = 5;

    /**
     * @param list<ScoredRow> $rows Rows already parsed and normalised by the reader
     */
    public function calculate(array $rows): AuditScoreResult
    {
        // Unsamplable rows sat in the draw but could not be judged (soft-deleted
        // between draw and scoring). They belong to neither numerator nor
        // denominator; they are reported on their own line so the sheet still
        // reconciles against the drawn sample size.
        $scorable = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['flag'] !== AuditSheetReader::FLAG_UNSAMPLABLE
        ));

        $agreement = $this->agreement($scorable);

        return new AuditScoreResult(
            totalRows: \count($rows),
            unsamplableRows: \count($rows) - \count($scorable),
            scoredRows: \count($scorable),
            rawAgreement: $agreement['raw'],
            agreedRows: $agreement['agreed'],
            cohensKappa: $this->cohensKappa($scorable),
            overall: $this->tally($scorable),
            confirmedOnly: $this->tally(array_values(array_filter(
                $scorable,
                static fn (array $row): bool => $row['status'] === 'confirmed'
            ))),
            reviewOnly: $this->tally(array_values(array_filter(
                $scorable,
                static fn (array $row): bool => $row['status'] !== 'confirmed'
            ))),
            paraphrased: $this->tally(array_values(array_filter(
                $scorable,
                static fn (array $row): bool => $row['flag'] === AuditSheetReader::FLAG_PARAPHRASED
            ))),
            perCode: $this->perCode($scorable),
            disagreements: $this->disagreements($scorable),
        );
    }

    /**
     * Raw agreement: share of scored rows where both scorers chose the same verdict.
     *
     * @param list<ScoredRow> $rows
     *
     * @return array{raw: float|null, agreed: int}
     */
    private function agreement(array $rows): array
    {
        $comparable = $this->comparableRows($rows);

        if ($comparable === []) {
            return ['raw' => null, 'agreed' => 0];
        }

        $agreed = 0;

        foreach ($comparable as $row) {
            if ($row['verdict_a'] === $row['verdict_b']) {
                ++$agreed;
            }
        }

        return ['raw' => $agreed / \count($comparable), 'agreed' => $agreed];
    }

    /**
     * Cohen's kappa over the three-verdict vocabulary.
     *
     * kappa = (Po - Pe) / (1 - Pe), where Po is observed agreement and Pe is the
     * agreement two scorers would reach by chance given their own marginal rates.
     *
     * Returns null when it cannot be computed: no comparable rows, or perfect
     * marginal concentration (both scorers used one single verdict everywhere, so
     * Pe = 1 and the correction divides by zero). A null is reported as "not
     * computable" rather than dressed up as 1.0 — a sheet where every row is
     * `correct` carries no information about agreement beyond chance.
     *
     * @param list<ScoredRow> $rows
     */
    private function cohensKappa(array $rows): ?float
    {
        $comparable = $this->comparableRows($rows);
        $n = \count($comparable);

        if ($n === 0) {
            return null;
        }

        $observed = 0;
        $marginalA = array_fill_keys(self::VERDICTS, 0);
        $marginalB = array_fill_keys(self::VERDICTS, 0);

        foreach ($comparable as $row) {
            ++$marginalA[$row['verdict_a']];
            ++$marginalB[$row['verdict_b']];

            if ($row['verdict_a'] === $row['verdict_b']) {
                ++$observed;
            }
        }

        $po = $observed / $n;
        $pe = 0.0;

        foreach (self::VERDICTS as $verdict) {
            $pe += ($marginalA[$verdict] / $n) * ($marginalB[$verdict] / $n);
        }

        if (abs(1.0 - $pe) < 1.0e-12) {
            return null;
        }

        return ($po - $pe) / (1.0 - $pe);
    }

    /**
     * Rows where both scorers recorded a verdict. A half-filled row is not
     * agreement evidence in either direction, so it is left out of the
     * agreement denominator (and counted separately by the reader).
     *
     * @param list<ScoredRow> $rows
     *
     * @return list<ScoredRow>
     */
    private function comparableRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['verdict_a'] !== '' && $row['verdict_b'] !== ''
        ));
    }

    /**
     * @param list<ScoredRow> $rows
     */
    private function tally(array $rows): AuditTally
    {
        $counts = array_fill_keys(self::VERDICTS, 0);
        $unscored = 0;

        foreach ($rows as $row) {
            if ($row['verdict_final'] === '') {
                ++$unscored;

                continue;
            }

            ++$counts[$row['verdict_final']];
        }

        return new AuditTally(
            correct: $counts[self::VERDICT_CORRECT],
            incorrect: $counts[self::VERDICT_INCORRECT],
            unclear: $counts[self::VERDICT_UNCLEAR],
            unscored: $unscored,
        );
    }

    /**
     * Per-taxonomy-code tallies, ordered by code so the report is stable.
     *
     * @param list<ScoredRow> $rows
     *
     * @return array<string, AuditTally>
     */
    private function perCode(array $rows): array
    {
        /** @var array<string, list<ScoredRow>> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row['ttp_code']][] = $row;
        }

        ksort($grouped);

        $perCode = [];

        foreach ($grouped as $code => $codeRows) {
            $perCode[$code] = $this->tally($codeRows);
        }

        return $perCode;
    }

    /**
     * Rows where the two scorers differed, for the adjudication audit trail. Only
     * identifiers and verdicts travel — never the evidence.
     *
     * @param list<ScoredRow> $rows
     *
     * @return list<array{ttp_code: string, verdict_a: string, verdict_b: string, verdict_final: string}>
     */
    private function disagreements(array $rows): array
    {
        $out = [];

        foreach ($this->comparableRows($rows) as $row) {
            if ($row['verdict_a'] === $row['verdict_b']) {
                continue;
            }

            $out[] = [
                'ttp_code' => $row['ttp_code'],
                'verdict_a' => $row['verdict_a'],
                'verdict_b' => $row['verdict_b'],
                'verdict_final' => $row['verdict_final'],
            ];
        }

        return $out;
    }
}

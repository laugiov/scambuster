<?php

declare(strict_types=1);

namespace App\Application\Ttp\Audit;

/**
 * The complete figure set of one scoring round.
 *
 * Everything here is descriptive of the audited sample. Nothing is extrapolated to
 * the corpus and no confidence interval is attached (Spec 001 FR-007, Constitution I).
 */
final readonly class AuditScoreResult
{
    /**
     * @param float|null                                                                                 $rawAgreement  Null when no row carries both verdicts
     * @param float|null                                                                                 $cohensKappa   Null when kappa is not computable (see the calculator)
     * @param array<string, AuditTally>                                                                  $perCode       Keyed by taxonomy code, ordered by code
     * @param list<array{ttp_code: string, verdict_a: string, verdict_b: string, verdict_final: string}> $disagreements
     */
    public function __construct(
        public int $totalRows,
        public int $unsamplableRows,
        public int $scoredRows,
        public ?float $rawAgreement,
        public int $agreedRows,
        public ?float $cohensKappa,
        public AuditTally $overall,
        public AuditTally $confirmedOnly,
        public AuditTally $reviewOnly,
        public AuditTally $paraphrased,
        public array $perCode,
        public array $disagreements,
    ) {
    }

    /**
     * Conventional reading bands for Cohen's kappa (Landis & Koch). These are how
     * the number is habitually read in the literature, not a threshold this project
     * has validated — the report says so next to the value.
     */
    public function kappaBand(): string
    {
        if ($this->cohensKappa === null) {
            return 'not computable';
        }

        return match (true) {
            $this->cohensKappa < 0.00 => 'no agreement beyond chance',
            $this->cohensKappa < 0.20 => 'slight',
            $this->cohensKappa < 0.40 => 'fair',
            $this->cohensKappa < 0.60 => 'moderate',
            $this->cohensKappa < 0.80 => 'substantial',
            default => 'strong',
        };
    }

    /**
     * Codes whose sampled count is too small to read as a quality signal.
     *
     * @return list<string>
     */
    public function lowCoverageCodes(): array
    {
        $codes = [];

        foreach ($this->perCode as $code => $tally) {
            if ($tally->total() < AuditScoreCalculator::LOW_COVERAGE_THRESHOLD) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}

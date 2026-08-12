<?php

declare(strict_types=1);

namespace App\Application\Ttp\Audit;

/**
 * Renders an audit scoring result as the markdown block that goes into the results
 * document under docs/.
 *
 * The renderer only ever sees codes, statuses and verdict labels, so its output is
 * safe to paste into a public document: there is no path by which verbatim scammer
 * evidence could reach it.
 *
 * Every figure is written as a count with its denominator next to it. Percentages
 * appear as a convenience, never alone, so a reader always sees how small a slice
 * a percentage was computed from.
 */
final class AuditReportRenderer
{
    public function render(AuditScoreResult $result, AuditRunContext $context): string
    {
        $lines = [];
        $lines[] = '## Scoring results';
        $lines[] = '';
        $lines[] = $this->contextTable($context);
        $lines[] = '';

        $lines[] = '### Sample';
        $lines[] = '';
        $lines[] = sprintf('- Rows in the sheet: **%d**', $result->totalRows);
        $lines[] = sprintf('- Scored rows: **%d**', $result->scoredRows);
        $lines[] = sprintf('- Unsamplable rows (excluded from every figure): **%d**', $result->unsamplableRows);
        $lines[] = '';

        $lines[] = '### Inter-scorer agreement';
        $lines[] = '';
        $lines[] = sprintf(
            '- Raw agreement: **%s** (%d of %d double-scored rows)',
            $this->percent($result->rawAgreement),
            $result->agreedRows,
            $result->agreedRows + \count($result->disagreements),
        );
        $lines[] = sprintf(
            '- Cohen\'s kappa: **%s** (%s)',
            $result->cohensKappa === null ? 'not computable' : number_format($result->cohensKappa, 3),
            $result->kappaBand(),
        );
        $lines[] = sprintf('- Adjudicated disagreements: **%d**', \count($result->disagreements));
        $lines[] = '';
        $lines[] = 'The kappa band is the conventional reading of the value in the literature'
            . ' (Landis & Koch). It is not a threshold this project has validated.';
        $lines[] = '';

        $lines[] = '### Precision on the sample';
        $lines[] = '';
        $lines[] = '| Slice | correct | incorrect | unclear | precision |';
        $lines[] = '|-------|---------|-----------|---------|-----------|';
        $lines[] = $this->tallyRow('All scored rows', $result->overall);
        $lines[] = $this->tallyRow('Confirmed rows only', $result->confirmedOnly);
        $lines[] = $this->tallyRow('Review-status rows only', $result->reviewOnly);
        $lines[] = $this->tallyRow('Paraphrased-evidence rows', $result->paraphrased);
        $lines[] = '';
        $lines[] = 'Precision is `correct / (correct + incorrect)` on adjudicated verdicts.'
            . ' `unclear` rows are outside both terms and are reported as their own count.'
            . ' These figures describe the audited sample only: they are not extrapolated to'
            . ' the corpus and carry no confidence interval.';
        $lines[] = '';

        $lines[] = '### Per-code counts';
        $lines[] = '';
        $lines[] = '| Code | sampled | correct | incorrect | unclear | note |';
        $lines[] = '|------|---------|---------|-----------|---------|------|';

        foreach ($result->perCode as $code => $tally) {
            $lines[] = sprintf(
                '| %s | %d | %d | %d | %d | %s |',
                $code,
                $tally->total(),
                $tally->correct,
                $tally->incorrect,
                $tally->unclear,
                $tally->total() < AuditScoreCalculator::LOW_COVERAGE_THRESHOLD
                    ? sprintf('under %d sampled — too few to read as a quality signal', AuditScoreCalculator::LOW_COVERAGE_THRESHOLD)
                    : '',
            );
        }

        $lines[] = '';

        $lowCoverage = $result->lowCoverageCodes();

        if ($lowCoverage !== []) {
            $lines[] = sprintf(
                'Codes sampled fewer than %d times: %s. Their rows are in the overall figure,'
                . ' but no per-code reading is claimed for them.',
                AuditScoreCalculator::LOW_COVERAGE_THRESHOLD,
                implode(', ', $lowCoverage),
            );
            $lines[] = '';
        }

        $absent = array_values(array_diff($context->taxonomyCodes, array_keys($result->perCode)));

        if ($absent !== []) {
            $lines[] = sprintf(
                'Codes with no row in this sample: %s. This sample says nothing about them.',
                implode(', ', $absent),
            );
            $lines[] = '';
        }

        $lines[] = '### Disagreement breakdown';
        $lines[] = '';

        if ($result->disagreements === []) {
            $lines[] = 'The two scorers agreed on every double-scored row.';
        } else {
            $lines[] = '| Code | scorer A | scorer B | adjudicated |';
            $lines[] = '|------|----------|----------|-------------|';

            foreach ($result->disagreements as $disagreement) {
                $lines[] = sprintf(
                    '| %s | %s | %s | %s |',
                    $disagreement['ttp_code'],
                    $disagreement['verdict_a'],
                    $disagreement['verdict_b'],
                    $disagreement['verdict_final'] === '' ? '_not adjudicated_' : $disagreement['verdict_final'],
                );
            }

            $lines[] = '';
            $lines[] = 'The one-line reason for each adjudication is in the internal scored sheet.'
                . ' It is not reproduced here: reasons quote the evidence.';
        }

        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    private function contextTable(AuditRunContext $context): string
    {
        $rows = [
            ['Seed', $context->seed],
            ['Draw', $context->draw],
            ['Taxonomy version', $context->taxonomyVersion],
            ['Codebook version', $context->codebookVersion],
            ['Scored sheet', $context->sheetName],
            ['Scored on', $context->scoredOn],
        ];

        $lines = ['| Parameter | Value |', '|-----------|-------|'];

        foreach ($rows as [$label, $value]) {
            $lines[] = sprintf('| %s | %s |', $label, $value === '' ? '_not recorded_' : $value);
        }

        return implode("\n", $lines);
    }

    private function tallyRow(string $label, AuditTally $tally): string
    {
        return sprintf(
            '| %s | %d | %d | %d | %s |',
            $label,
            $tally->correct,
            $tally->incorrect,
            $tally->unclear,
            $tally->precision() === null
                ? 'not computable'
                : sprintf('%s (%d/%d)', $this->percent($tally->precision()), $tally->correct, $tally->decided()),
        );
    }

    private function percent(?float $value): string
    {
        return $value === null ? 'not computable' : number_format($value * 100, 1) . '%';
    }
}

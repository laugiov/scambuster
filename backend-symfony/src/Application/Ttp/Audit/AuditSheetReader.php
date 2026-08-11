<?php

declare(strict_types=1);

namespace App\Application\Ttp\Audit;

/**
 * Parses a scored audit sheet: the CSV produced by `scambuster:ttp:audit-sample`
 * with the six scoring columns of codebook v1 appended.
 *
 * The reader keeps only what the figures need — taxonomy code, observation status,
 * the two verdicts, the adjudicated verdict and the flag. The evidence column is
 * read to locate it and then dropped on the floor: no verbatim scammer text is
 * carried into the result objects, so nothing downstream can leak it into a public
 * document (Constitution III).
 *
 * Structural problems are collected, not thrown: a half-filled sheet should tell
 * its owner everything that is wrong with it in one run, not one problem per run.
 *
 * CSV parsing runs with the escape character disabled (`escape: ''`), matching the
 * export side. That is plain RFC 4180, which is what a spreadsheet writes when a
 * scorer saves the sheet — and it is the only setting that survives arbitrary
 * scammer text, where a trailing backslash under PHP's legacy escaping would
 * swallow the delimiter and shift every column after it.
 */
final class AuditSheetReader
{
    public const FLAG_PARAPHRASED = 'paraphrased';
    public const FLAG_UNSAMPLABLE = 'unsamplable';
    public const FLAG_REPLACED = 'replaced';

    /** @var list<string> */
    public const FLAGS = ['', self::FLAG_PARAPHRASED, self::FLAG_UNSAMPLABLE, self::FLAG_REPLACED];

    /** Columns codebook v1 requires the scorers to append to the exported sample. */
    public const SCORING_COLUMNS = [
        'verdict_a',
        'verdict_b',
        'verdict_final',
        'adjudication_reason',
        'flag',
        'notes',
    ];

    /** Columns the reader needs from the exported sample itself. */
    private const REQUIRED_SAMPLE_COLUMNS = ['obs_id', 'ttp_code', 'status'];

    /**
     * The evidence banner the export writes as its first record. It is a comment,
     * not a header, so it is skipped before the header row is read.
     */
    private const BANNER_PREFIX = '#';

    public function read(string $path): AuditSheet
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Scored sheet not found or not readable: %s', $path));
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to open the scored sheet: %s', $path));
        }

        try {
            return $this->readHandle($handle, $path);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     */
    private function readHandle($handle, string $path): AuditSheet
    {
        $header = $this->readHeader($handle, $path);
        $index = array_flip($header);

        $missing = array_values(array_diff(
            [...self::REQUIRED_SAMPLE_COLUMNS, ...self::SCORING_COLUMNS],
            $header
        ));

        if ($missing !== []) {
            throw new \RuntimeException(sprintf(
                'Scored sheet is missing required column(s): %s. Append the codebook v1 scoring columns to the exported sample.',
                implode(', ', $missing)
            ));
        }

        $rows = [];
        $problems = [];
        $seen = [];
        $lineNumber = 1;

        while (($record = fgetcsv($handle, escape: '')) !== false) {
            ++$lineNumber;

            if ($this->isBlank($record)) {
                continue;
            }

            $cell = function (string $column) use ($record, $index): string {
                $position = $index[$column];
                $value = $record[$position] ?? null;

                return \is_string($value) ? trim($value) : '';
            };

            $obsId = $cell('obs_id');
            $code = strtoupper($cell('ttp_code'));
            $flag = strtolower($cell('flag'));
            $verdictA = strtolower($cell('verdict_a'));
            $verdictB = strtolower($cell('verdict_b'));
            $verdictFinal = strtolower($cell('verdict_final'));

            if ($obsId === '') {
                $problems[] = sprintf('line %d: empty obs_id', $lineNumber);

                continue;
            }

            if (isset($seen[$obsId])) {
                $problems[] = sprintf('line %d: obs_id %s appears more than once', $lineNumber, $obsId);

                continue;
            }
            $seen[$obsId] = true;

            if (!\in_array($flag, self::FLAGS, true)) {
                $problems[] = sprintf('line %d: unknown flag "%s"', $lineNumber, $flag);
                $flag = '';
            }

            // An unrecognised verdict is reported and then dropped to empty rather
            // than passed on. Everything downstream counts by verdict label, so a
            // value outside the vocabulary would land in no bucket and silently
            // shrink the denominators the figures are computed from — a quieter
            // and worse failure than the row simply reading as unscored.
            $verdictA = $this->normaliseVerdict($verdictA, 'verdict_a', $lineNumber, $problems);
            $verdictB = $this->normaliseVerdict($verdictB, 'verdict_b', $lineNumber, $problems);
            $verdictFinal = $this->normaliseVerdict($verdictFinal, 'verdict_final', $lineNumber, $problems);

            if ($flag !== self::FLAG_UNSAMPLABLE) {
                if ($verdictA === '' || $verdictB === '') {
                    $problems[] = sprintf('line %d: row is not double-scored (verdict_a and verdict_b are both required)', $lineNumber);
                }

                if ($verdictFinal === '') {
                    $problems[] = sprintf('line %d: no adjudicated verdict_final', $lineNumber);
                }

                // FR-006: every disagreement carries a logged reason.
                if ($verdictA !== '' && $verdictB !== '' && $verdictA !== $verdictB && $cell('adjudication_reason') === '') {
                    $problems[] = sprintf('line %d: scorers disagree (%s vs %s) but adjudication_reason is empty', $lineNumber, $verdictA, $verdictB);
                }
            }

            $rows[] = [
                'ttp_code' => $code,
                'status' => strtolower($cell('status')),
                'verdict_a' => $verdictA,
                'verdict_b' => $verdictB,
                'verdict_final' => $verdictFinal,
                'flag' => $flag,
            ];
        }

        if ($rows === []) {
            throw new \RuntimeException(sprintf('Scored sheet has a header but no data rows: %s', $path));
        }

        return new AuditSheet($rows, $problems);
    }

    /**
     * A verdict from the closed vocabulary, or an empty string.
     *
     * @param list<string> $problems Appended to when the value is outside the vocabulary
     */
    private function normaliseVerdict(string $verdict, string $column, int $lineNumber, array &$problems): string
    {
        if ($verdict === '' || \in_array($verdict, AuditScoreCalculator::VERDICTS, true)) {
            return $verdict;
        }

        $problems[] = sprintf(
            'line %d: %s carries unknown verdict "%s" — the row is counted as unscored',
            $lineNumber,
            $column,
            $verdict
        );

        return '';
    }

    /**
     * @param resource $handle
     *
     * @return list<string>
     */
    private function readHeader($handle, string $path): array
    {
        while (($record = fgetcsv($handle, escape: '')) !== false) {
            if ($this->isBlank($record)) {
                continue;
            }

            $first = \is_string($record[0] ?? null) ? trim($record[0]) : '';

            // The export banner rides as a single-cell comment record.
            if (str_starts_with($first, self::BANNER_PREFIX)) {
                continue;
            }

            $header = [];

            foreach ($record as $column) {
                $header[] = \is_string($column) ? trim($column) : '';
            }

            return $header;
        }

        throw new \RuntimeException(sprintf('Scored sheet has no header row: %s', $path));
    }

    /**
     * @param array<int, string|null> $record
     */
    private function isBlank(array $record): bool
    {
        foreach ($record as $cell) {
            if (\is_string($cell) && trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }
}

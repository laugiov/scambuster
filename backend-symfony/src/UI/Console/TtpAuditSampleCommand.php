<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Domain\Communication\Ttp;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Export a random sample of TTP observations for a manual precision audit.
 *
 * This command is the ONE sanctioned egress for the verbatim evidence quotes:
 * everywhere else — API responses, audit payloads, STIX/TAXII/MISP exports —
 * observations travel WITHOUT their evidence text. Here the evidence column is
 * included on purpose, because a human scoring extraction precision has to read
 * the quote the model tagged. The output is therefore an internal, audit-scoped
 * file that must never be redistributed: it contains raw, un-anonymised scammer
 * message excerpts.
 *
 * The command only EXPORTS the sample. It deliberately computes no precision or
 * accuracy figure: no such number is trustworthy until a human has scored the
 * sample, so none is printed anywhere.
 *
 * Sampling is a uniform random draw over the confirmed/review observations of
 * live (non-soft-deleted) messages and conversations. Pass --seed to make the
 * draw reproducible: the same seed against the same data returns the same rows,
 * so a reviewer can re-pull an identical sheet. Without a seed the draw is a
 * fresh ORDER BY random() each run. --ttp narrows the sample to a single
 * taxonomy code, --limit caps the row count and --output writes the CSV to a
 * file (otherwise it streams to stdout, with the informational notes routed to
 * stderr so a piped file stays a clean CSV).
 *
 * --stratified switches the draw to round-robin over the taxonomy codes instead:
 * each code contributes its rows in turn until the limit is reached, so rare
 * codes get sampled instead of being crowded out by the frequent ones. The two
 * draws answer different questions and are NOT interchangeable:
 *
 * - uniform (default) — every observation is equally likely, so the resulting
 *   precision figure describes the extractor's output as it actually is. This is
 *   the draw a published overall figure must come from.
 * - stratified — codes are over-sampled relative to their real frequency, which
 *   buys per-code coverage but makes the pooled figure meaningless as an overall
 *   precision. Read it per code only.
 *
 * The draw mode travels in the CSV banner so a scored sheet can never be
 * mistaken for the other kind.
 *
 * The CSV is written with the escape character disabled (`escape: ''`), i.e. plain
 * RFC 4180 quoting. Evidence cells hold arbitrary scammer text, and under PHP's
 * legacy backslash escaping a quote preceded by a backslash stops terminating its
 * field — which silently shifts every column after it in the row a human is about
 * to score. Quote doubling has no such hole, and it is also what a spreadsheet
 * writes back when the scorer saves the sheet.
 */
#[AsCommand(
    name: 'scambuster:ttp:audit-sample',
    description: 'Export a random sample of TTP observations (WITH raw evidence) for internal manual precision audit.',
)]
final class TtpAuditSampleCommand extends Command
{
    private const DEFAULT_LIMIT = 100;

    private const DRAW_UNIFORM = 'uniform';
    private const DRAW_STRATIFIED = 'stratified';

    /**
     * Single-line banner written as the first CSV record so the file itself
     * carries the evidence-egress warning, not only the console output.
     */
    private const EVIDENCE_BANNER = '# ScamBuster TTP audit sample — contains RAW scammer evidence excerpts. INTERNAL MANUAL AUDIT ONLY — do not redistribute.';

    /**
     * Second banner record: the draw parameters, so a sheet that has travelled
     * between two people still says how it was drawn. The scoring command reads
     * past both banner records to the header.
     */
    private const PROVENANCE_BANNER = '# draw=%s seed=%s limit=%d ttp=%s taxonomy_version=%s';

    /** @var list<string> */
    private const COLUMNS = [
        'obs_id',
        'conv_id',
        'msg_id',
        'ttp_code',
        'ttp_label',
        'phase',
        'confidence',
        'status',
        'evidence',
        'evidence_start',
        'evidence_end',
        'taxonomy_version',
        'extraction_model',
        'prompt_version',
        'created_at',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Sample size (number of observations to export).', (string) self::DEFAULT_LIMIT)
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the CSV to this file path. Without it the CSV is streamed to stdout.', null)
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Optional integer seed for a reproducible sample (same seed + same data → same rows).', null)
            ->addOption('ttp', null, InputOption::VALUE_REQUIRED, 'Optional taxonomy code (e.g. SB-T017) to restrict the sample to one TTP.', null)
            ->addOption('stratified', null, InputOption::VALUE_NONE, 'Draw round-robin across taxonomy codes instead of uniformly, so rare codes get covered. The pooled figure is then NOT an overall precision.')
            ->setHelp(
                "Exports a random sample of ttp_observation rows for a human precision audit.\n\n".
                "WARNING: the CSV includes the verbatim EVIDENCE column — raw, un-anonymised scammer\n".
                "message excerpts. This is the only place TTP evidence leaves the database. The file is\n".
                "for internal manual audit only and must never be redistributed or attached to any export.\n\n".
                "Draw modes:\n".
                "  (default)     uniform — every observation equally likely. Use this for a published\n".
                "                overall precision figure.\n".
                "  --stratified  round-robin across codes — buys coverage of rare codes, but the pooled\n".
                "                figure is no longer an overall precision. Read it per code only.\n\n".
                "Score the exported sheet against docs/standards/ttp-codebook-v1.md, then run\n".
                "scambuster:ttp:audit-score on it.\n\n".
                'No precision metric is computed here: the command only produces the sheet a human then scores.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limitRaw = $input->getOption('limit');
        $limit = is_numeric($limitRaw) ? max(1, (int) $limitRaw) : self::DEFAULT_LIMIT;

        $outputPathRaw = $input->getOption('output');
        $outputPath = is_string($outputPathRaw) && $outputPathRaw !== '' ? $outputPathRaw : null;

        $seedRaw = $input->getOption('seed');
        $seed = is_numeric($seedRaw) ? (string) (int) $seedRaw : null;

        $ttpRaw = $input->getOption('ttp');
        $ttpCode = is_string($ttpRaw) && $ttpRaw !== '' ? strtoupper($ttpRaw) : null;

        $draw = $input->getOption('stratified') === true ? self::DRAW_STRATIFIED : self::DRAW_UNIFORM;

        // Informational output goes to stdout when the CSV lands in a file, and
        // to stderr when the CSV itself streams to stdout — so a piped run keeps
        // a clean CSV on stdout while still surfacing the evidence warning.
        $noteTarget = $output;

        if ($outputPath === null && $output instanceof ConsoleOutputInterface) {
            $noteTarget = $output->getErrorOutput();
        }
        $io = new SymfonyStyle($input, $noteTarget);

        $rows = $this->fetchSample($limit, $seed, $ttpCode, $draw);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            $io->error('Unable to open an in-memory buffer for the CSV.');

            return Command::FAILURE;
        }

        try {
            fputcsv($handle, [self::EVIDENCE_BANNER], escape: '');
            fputcsv($handle, [sprintf(
                self::PROVENANCE_BANNER,
                $draw,
                $seed ?? 'none',
                $limit,
                $ttpCode ?? 'all',
                Ttp::TAXONOMY_VERSION,
            )], escape: '');
            fputcsv($handle, self::COLUMNS, escape: '');

            foreach ($rows as $row) {
                fputcsv($handle, $this->orderRow($row), escape: '');
            }

            rewind($handle);
            $csv = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if ($csv === false) {
            $io->error('Failed to build the CSV content.');

            return Command::FAILURE;
        }

        if ($outputPath !== null) {
            if (file_put_contents($outputPath, $csv) === false) {
                $io->error(sprintf('Unable to write the sample to %s', $outputPath));

                return Command::FAILURE;
            }
        } else {
            $output->write($csv);
        }

        $this->logger->info('[TtpAuditSample] Sample exported', [
            'rows' => count($rows),
            'limit' => $limit,
            'ttp' => $ttpCode,
            'draw' => $draw,
            'seeded' => $seed !== null,
            'to_file' => $outputPath !== null,
        ]);

        $io->newLine();
        $io->success(sprintf(
            'Exported %d observation(s)%s%s (%s draw).',
            count($rows),
            $ttpCode !== null ? sprintf(' for %s', $ttpCode) : '',
            $outputPath !== null ? sprintf(' to %s', $outputPath) : ' to stdout',
            $draw,
        ));
        $io->warning('This file contains RAW scammer evidence excerpts. Internal manual audit only — never redistribute or feed into any export.');

        if ($draw === self::DRAW_STRATIFIED) {
            $io->warning('Stratified draw: codes are over-sampled relative to their real frequency. The pooled figure from this sheet is NOT an overall precision — read it per code only.');
        }

        if ($seed === null) {
            $io->warning('No --seed given: this draw cannot be reproduced. A published figure needs a recorded seed (Spec 001 FR-001).');
        }

        $io->note('No precision metric is computed. Score the sheet against docs/standards/ttp-codebook-v1.md, then run scambuster:ttp:audit-score on it.');

        return Command::SUCCESS;
    }

    /**
     * @param self::DRAW_* $draw
     *
     * @return list<array<string, mixed>>
     */
    private function fetchSample(int $limit, ?string $seed, ?string $ttpCode, string $draw): array
    {
        $select = 'SELECT'
            . ' o.obs_id, o.conv_id, o.msg_id,'
            . ' t.code AS ttp_code, t.label AS ttp_label, t.phase,'
            . ' o.confidence, o.status, o.evidence, o.evidence_start, o.evidence_end,'
            . ' o.taxonomy_version, o.extraction_model, o.prompt_version, o.created_at'
            . ' FROM ttp_observation o'
            . ' JOIN lkp_ttp t ON t.ttp_id = o.ttp_id'
            . ' JOIN message m ON m.msg_id = o.msg_id AND m.deleted_at IS NULL'
            . ' JOIN conversation c ON c.conv_id = o.conv_id AND c.deleted_at IS NULL';

        $params = [];

        if ($ttpCode !== null) {
            $select .= ' WHERE t.code = :ttp';
            $params['ttp'] = $ttpCode;
        }

        // Deterministic pseudo-random order: hashing obs_id with the seed gives a
        // stable shuffle for a given (seed, dataset) pair. Without a seed the draw
        // is a fresh random() each run.
        if ($seed !== null) {
            $shuffle = 'md5(o.obs_id::text || :seed)';
            $params['seed'] = $seed;
        } else {
            $shuffle = 'random()';
        }

        if ($draw === self::DRAW_STRATIFIED) {
            // Rank each observation within its own code by the same shuffle, then
            // order by that rank first: row 1 of every code comes before row 2 of
            // any code. Truncating at the limit therefore spreads the sample
            // across codes instead of letting the frequent ones fill it.
            $sql = 'SELECT * FROM ('
                . $select . ', ROW_NUMBER() OVER (PARTITION BY t.code ORDER BY ' . $shuffle . ') AS code_rank'
                . ') ranked ORDER BY code_rank, ttp_code LIMIT ' . $limit;

            $rows = $this->connection->fetchAllAssociative($sql, $params);

            // code_rank is a draw mechanism, not audit data: drop it so both draw
            // modes produce the same columns and the scoring command sees one shape.
            return array_map(
                static function (array $row): array {
                    unset($row['code_rank']);

                    return $row;
                },
                $rows
            );
        }

        return $this->connection->fetchAllAssociative(
            $select . ' ORDER BY ' . $shuffle . ' LIMIT ' . $limit,
            $params
        );
    }

    /**
     * Project an associative row into the fixed column order, turning nulls
     * (e.g. absent evidence offsets) into empty strings for fputcsv.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function orderRow(array $row): array
    {
        $ordered = [];

        foreach (self::COLUMNS as $column) {
            $value = $row[$column] ?? null;
            // DB columns are scalar-or-null; nulls (e.g. absent offsets) and any
            // non-scalar become an empty cell for fputcsv.
            $ordered[] = is_scalar($value) ? (string) $value : '';
        }

        return $ordered;
    }
}

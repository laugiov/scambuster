<?php

declare(strict_types=1);

namespace App\Application\Export;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Flat IOC feed exporter — analyst-friendly CSV and NDJSON formats.
 *
 * Sibling to the STIX bundle export ({@see \App\Application\Stix\IocStixExportHandler}):
 * same authenticated selection contract (a list of indicator IDs, 500-cap), but a
 * flat one-row-per-IOC shape for the tools SOC analysts actually reach for —
 * spreadsheets / grep (CSV) and streaming SIEM ingestion / jq (NDJSON).
 *
 * Like the STIX export (and unlike the public TAXII feed), this is an authenticated
 * pull gated on `ioc:export`, so it does NOT strip TLP:RED — the analyst is trusted.
 */
final readonly class IocFeedExporter
{
    public const FORMAT_CSV = 'csv';
    public const FORMAT_NDJSON = 'ndjson';

    /** Canonical column order — keeps the CSV header and NDJSON keys in lockstep. */
    private const COLUMNS = [
        'indicator_id',
        'type',
        'value',
        'value_norm',
        'tlp',
        'score',
        'occurrences',
        'first_seen',
        'last_seen',
        'scam_type',
        'analyst_verdict',
    ];

    /** Hard cap, mirrors the STIX export. */
    private const MAX_INDICATORS = 500;

    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<string> the supported format identifiers
     */
    public static function supportedFormats(): array
    {
        return [self::FORMAT_CSV, self::FORMAT_NDJSON];
    }

    public static function contentType(string $format): string
    {
        return match ($format) {
            self::FORMAT_CSV => 'text/csv; charset=utf-8',
            self::FORMAT_NDJSON => 'application/x-ndjson; charset=utf-8',
            default => throw new \InvalidArgumentException(sprintf('Unsupported feed format "%s".', $format)),
        };
    }

    public static function fileExtension(string $format): string
    {
        return match ($format) {
            self::FORMAT_CSV => 'csv',
            self::FORMAT_NDJSON => 'ndjson',
            default => throw new \InvalidArgumentException(sprintf('Unsupported feed format "%s".', $format)),
        };
    }

    /**
     * Fetch the selected indicators and serialise them in the requested format.
     *
     * @param array<int, string> $indicatorIds
     */
    public function export(array $indicatorIds, string $format): string
    {
        return $this->formatRecords($this->fetchRecords($indicatorIds), $format);
    }

    /**
     * Serialise already-fetched flat records. Pure — no I/O, unit-testable.
     *
     * @param list<array<string, scalar|null>> $records
     */
    public function formatRecords(array $records, string $format): string
    {
        return match ($format) {
            self::FORMAT_CSV => $this->toCsv($records),
            self::FORMAT_NDJSON => $this->toNdjson($records),
            default => throw new \InvalidArgumentException(sprintf('Unsupported feed format "%s".', $format)),
        };
    }

    /**
     * @param list<array<string, scalar|null>> $records
     */
    private function toCsv(array $records): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new \RuntimeException('Unable to open temp stream for CSV export.');
        }

        // RFC 4180 quoting/escaping is handled by fputcsv (commas, quotes, newlines).
        fputcsv($stream, self::COLUMNS);

        foreach ($records as $record) {
            $line = [];

            foreach (self::COLUMNS as $column) {
                $value = $record[$column] ?? null;
                $line[] = $value === null ? '' : (string) $value;
            }

            fputcsv($stream, $line);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv === false ? '' : $csv;
    }

    /**
     * @param list<array<string, scalar|null>> $records
     */
    private function toNdjson(array $records): string
    {
        $lines = [];

        foreach ($records as $record) {
            $ordered = [];

            foreach (self::COLUMNS as $column) {
                $ordered[$column] = $record[$column] ?? null;
            }

            $lines[] = json_encode($ordered, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        }

        // NDJSON: one JSON object per line, newline-terminated (trailing newline
        // is conventional and safe for line-based stream ingestion).
        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * @param array<int, string> $indicatorIds
     *
     * @return list<array<string, scalar|null>>
     */
    private function fetchRecords(array $indicatorIds): array
    {
        $indicatorIds = array_values(array_filter($indicatorIds, 'is_string'));
        $indicatorIds = \array_slice($indicatorIds, 0, self::MAX_INDICATORS);

        if ($indicatorIds === []) {
            return [];
        }

        $conn = $this->em->getConnection();
        $placeholders = implode(',', array_fill(0, \count($indicatorIds), '?'));

        $rows = $conn->executeQuery(
            "SELECT
                i.indicator_id,
                i.type,
                i.value,
                i.value_norm,
                i.tlp,
                i.score->>'agg' AS score,
                i.occurrences,
                i.first_seen,
                i.last_seen,
                ic.scam_type_code AS scam_type,
                f.verdict AS analyst_verdict
            FROM indicator i
            LEFT JOIN ioc_context ic ON i.indicator_id = ic.indicator_id
            LEFT JOIN ioc_analyst_feedback f ON i.indicator_id = f.indicator_id
            WHERE i.indicator_id IN ({$placeholders})
            ORDER BY i.first_seen DESC, i.indicator_id ASC",
            $indicatorIds
        )->fetchAllAssociative();

        // Dedup by indicator_id — the ioc_context join can multiply rows (one per
        // observation); keep the first, which carries a scam_type when any does.
        $seen = [];
        $records = [];

        foreach ($rows as $row) {
            $indId = \is_string($row['indicator_id'] ?? null) ? $row['indicator_id'] : '';

            if ($indId === '' || isset($seen[$indId])) {
                continue;
            }

            $seen[$indId] = true;

            $records[] = [
                'indicator_id' => $indId,
                'type' => $this->stringOrNull($row['type'] ?? null),
                'value' => $this->stringOrNull($row['value'] ?? null),
                'value_norm' => $this->stringOrNull($row['value_norm'] ?? null),
                'tlp' => $this->stringOrNull($row['tlp'] ?? null),
                'score' => is_numeric($row['score'] ?? null) ? (int) $row['score'] : null,
                'occurrences' => is_numeric($row['occurrences'] ?? null) ? (int) $row['occurrences'] : null,
                'first_seen' => $this->stringOrNull($row['first_seen'] ?? null),
                'last_seen' => $this->stringOrNull($row['last_seen'] ?? null),
                'scam_type' => $this->stringOrNull($row['scam_type'] ?? null),
                'analyst_verdict' => $this->stringOrNull($row['analyst_verdict'] ?? null),
            ];
        }

        return $records;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
}

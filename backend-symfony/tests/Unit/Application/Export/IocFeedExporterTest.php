<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Export;

use App\Application\Export\IocFeedExporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure formatting core of {@see IocFeedExporter}.
 *
 * The DB fetch path is covered by the functional controller test; here we pin
 * the CSV / NDJSON serialisation contract (header, ordering, escaping, nulls).
 */
class IocFeedExporterTest extends TestCase
{
    private IocFeedExporter $exporter;

    protected function setUp(): void
    {
        // formatRecords() is pure; the EM is never touched on this path.
        $this->exporter = new IocFeedExporter($this->createMock(EntityManagerInterface::class));
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    private function sampleRecords(): array
    {
        return [
            [
                'indicator_id' => '11111111-1111-4111-8111-111111111111',
                'type' => 'email',
                'value' => 'fraud@example.com',
                'value_norm' => 'fraud@example.com',
                'tlp' => 'AMBER',
                'score' => 72,
                'occurrences' => 3,
                'first_seen' => '2026-01-02 10:00:00',
                'last_seen' => '2026-01-05 11:00:00',
                'scam_type' => 'ROMANCE',
                'analyst_verdict' => 'false_positive',
            ],
        ];
    }

    public function testSupportedFormats(): void
    {
        self::assertSame(['csv', 'ndjson'], IocFeedExporter::supportedFormats());
    }

    public function testContentTypeAndExtensionPerFormat(): void
    {
        self::assertStringContainsString('text/csv', IocFeedExporter::contentType('csv'));
        self::assertStringContainsString('application/x-ndjson', IocFeedExporter::contentType('ndjson'));
        self::assertSame('csv', IocFeedExporter::fileExtension('csv'));
        self::assertSame('ndjson', IocFeedExporter::fileExtension('ndjson'));
    }

    public function testCsvHasCanonicalHeaderRow(): void
    {
        $csv = $this->exporter->formatRecords([], 'csv');
        $header = strtok($csv, "\n");

        self::assertSame(
            'indicator_id,type,value,value_norm,tlp,score,occurrences,first_seen,last_seen,scam_type,analyst_verdict',
            trim((string) $header),
        );
    }

    public function testCsvEmitsHeaderPlusOneRowPerRecord(): void
    {
        $csv = $this->exporter->formatRecords($this->sampleRecords(), 'csv');
        $lines = array_values(array_filter(explode("\n", trim($csv)), static fn ($l): bool => $l !== ''));

        self::assertCount(2, $lines, 'CSV must be header + one data row.');
        self::assertStringContainsString('fraud@example.com', $lines[1]);
        self::assertStringContainsString('ROMANCE', $lines[1]);
        self::assertStringContainsString('72', $lines[1]);
        // The analyst verdict rides along as the trailing column.
        self::assertStringContainsString('false_positive', $lines[1]);
    }

    public function testCsvEscapesCommasQuotesAndNewlines(): void
    {
        $records = [[
            'indicator_id' => 'id-1',
            'type' => 'text',
            'value' => 'a,b "quoted"' . "\n" . 'newline',
            'value_norm' => 'x',
            'tlp' => 'AMBER',
            'score' => null,
            'occurrences' => 1,
            'first_seen' => '2026-01-01 00:00:00',
            'last_seen' => '2026-01-01 00:00:00',
            'scam_type' => null,
        ]];

        $csv = $this->exporter->formatRecords($records, 'csv');

        // fputcsv wraps the field in quotes and doubles inner quotes; the raw
        // comma/newline therefore live inside a quoted field, not as delimiters.
        self::assertStringContainsString('"a,b ""quoted""', $csv);

        // Re-parse with a real CSV reader (fgetcsv respects quoted embedded
        // newlines — a naive explode("\n") would wrongly split the field).
        $rows = $this->parseCsv($csv);
        self::assertCount(2, $rows, 'Header + one data row, despite the embedded newline.');
        self::assertSame('a,b "quoted"' . "\n" . 'newline', $rows[1][2]);
    }

    /**
     * @return list<array<int, string|null>>
     */
    private function parseCsv(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $csv);
        rewind($stream);

        $rows = [];

        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = $row;
        }

        fclose($stream);

        return $rows;
    }

    public function testCsvRendersNullAsEmptyField(): void
    {
        $records = $this->sampleRecords();
        $records[0]['score'] = null;
        $records[0]['scam_type'] = null;

        $csv = $this->exporter->formatRecords($records, 'csv');
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        // score is column index 5, scam_type is column index 9.
        self::assertSame('', $rows[1][5]);
        self::assertSame('', $rows[1][9]);
    }

    public function testNdjsonEmitsOneValidJsonObjectPerLine(): void
    {
        $records = $this->sampleRecords();
        $records[] = [
            'indicator_id' => '22222222-2222-4222-8222-222222222222',
            'type' => 'domain',
            'value' => 'evil.example',
            'value_norm' => 'evil.example',
            'tlp' => 'GREEN',
            'score' => 10,
            'occurrences' => 1,
            'first_seen' => '2026-02-01 00:00:00',
            'last_seen' => '2026-02-01 00:00:00',
            'scam_type' => null,
        ];

        $ndjson = $this->exporter->formatRecords($records, 'ndjson');
        $lines = array_values(array_filter(explode("\n", $ndjson), static fn ($l): bool => $l !== ''));

        self::assertCount(2, $lines);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            // Every canonical column present on every line.
            foreach (['indicator_id', 'type', 'value', 'value_norm', 'tlp', 'score', 'occurrences', 'first_seen', 'last_seen', 'scam_type'] as $col) {
                self::assertArrayHasKey($col, $decoded);
            }
        }

        $first = json_decode($lines[0], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(72, $first['score'], 'NDJSON must preserve numeric score as a JSON number.');
        self::assertSame('ROMANCE', $first['scam_type']);

        $second = json_decode($lines[1], true, 512, \JSON_THROW_ON_ERROR);
        self::assertNull($second['scam_type'], 'Missing scam_type must serialise as JSON null.');
    }

    public function testNdjsonIsNewlineTerminated(): void
    {
        $ndjson = $this->exporter->formatRecords($this->sampleRecords(), 'ndjson');
        self::assertStringEndsWith("\n", $ndjson);
    }

    public function testEmptyRecordSetCsvIsHeaderOnlyNdjsonIsEmpty(): void
    {
        self::assertStringContainsString('indicator_id,type', $this->exporter->formatRecords([], 'csv'));
        self::assertSame('', $this->exporter->formatRecords([], 'ndjson'));
    }

    public function testUnsupportedFormatThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->exporter->formatRecords($this->sampleRecords(), 'xml');
    }

    public function testUnsupportedContentTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IocFeedExporter::contentType('xml');
    }

    // ── D1: CSV formula-injection neutralization ────────────────────────────

    /**
     * Parse the single data row of a one-record CSV export into a column=>cell map.
     *
     * @param array<string, scalar|null> $record
     *
     * @return array<string, string>
     */
    private function csvCells(array $record): array
    {
        // Reuses the file's existing parseCsv() helper (RFC-4180 aware).
        $rows = $this->parseCsv($this->exporter->formatRecords([$record], 'csv'));
        self::assertCount(2, $rows, 'expected header + one data row');

        $header = array_map(static fn ($c): string => (string) $c, $rows[0]);
        $data = array_map(static fn ($c): string => (string) $c, $rows[1]);

        /** @var array<string, string> $combined */
        $combined = array_combine($header, $data);

        return $combined;
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function dangerousLeadingCharProvider(): iterable
    {
        yield 'equals (classic DDE)' => ["=cmd|'/c calc.exe'!A1"];
        yield 'plus' => ['+1+1'];
        yield 'minus' => ['-2+3'];
        yield 'at' => ['@SUM(1,2)'];
        yield 'tab' => ["\t=1+1"];
        yield 'carriage return' => ["\r=1+1"];
    }

    /**
     * A scammer controls fields that become IOC values (e.g. the Subject header
     * → an IOC of type `subject`). A value beginning with a spreadsheet formula
     * trigger must be prefixed with a leading apostrophe so Excel/LibreOffice/
     * Sheets treat the cell as text.
     *
     * @dataProvider dangerousLeadingCharProvider
     */
    public function testCsvNeutralizesFormulaInjection(string $payload): void
    {
        $record = $this->sampleRecords()[0];
        $record['value'] = $payload;
        $record['value_norm'] = $payload;

        $cells = $this->csvCells($record);

        self::assertSame("'" . $payload, $cells['value'], 'value cell must be apostrophe-prefixed');
        self::assertSame("'" . $payload, $cells['value_norm'], 'value_norm cell must be apostrophe-prefixed');
    }

    /**
     * A leading space hiding a formula (" =1+1") must also be neutralized: a
     * consumer that trims the cell before parsing would otherwise re-expose it.
     */
    public function testCsvNeutralizesFormulaHiddenBehindLeadingWhitespace(): void
    {
        $record = $this->sampleRecords()[0];
        $record['value'] = ' =1+1';
        $record['value_norm'] = "\t\t=SUM(A1)";

        $cells = $this->csvCells($record);

        self::assertSame("' =1+1", $cells['value']);
        self::assertSame("'\t\t=SUM(A1)", $cells['value_norm']);
    }

    public function testCsvNeutralizationCoversEveryColumnNotJustValue(): void
    {
        $record = $this->sampleRecords()[0];
        $record['scam_type'] = '=WEBSERVICE("http://evil")';

        $cells = $this->csvCells($record);

        self::assertSame("'=WEBSERVICE(\"http://evil\")", $cells['scam_type']);
    }

    public function testCsvLeavesSafeValuesUnprefixed(): void
    {
        $cells = $this->csvCells($this->sampleRecords()[0]);

        self::assertSame('fraud@example.com', $cells['value'], 'a normal email must not be altered');
        self::assertSame('AMBER', $cells['tlp']);
        self::assertSame('ROMANCE', $cells['scam_type']);
    }

    public function testNdjsonDoesNotPrefixFormulas(): void
    {
        $record = $this->sampleRecords()[0];
        $record['value'] = '=cmd|calc';

        $ndjson = $this->exporter->formatRecords([$record], 'ndjson');
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(trim($ndjson), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('=cmd|calc', $decoded['value'], 'NDJSON is not spreadsheet-interpreted; no prefix');
    }
}

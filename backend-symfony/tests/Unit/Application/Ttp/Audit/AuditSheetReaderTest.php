<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Ttp\Audit;

use App\Application\Ttp\Audit\AuditSheetReader;
use PHPUnit\Framework\TestCase;

/**
 * The reader is the gate between a hand-edited spreadsheet and a published figure.
 * These tests pin the two things it owes the project: it refuses to read a sheet
 * whose shape it does not recognise, and it reports every way a sheet is unfinished
 * instead of quietly computing a number from it.
 *
 * It also owes Constitution III: the evidence column is present in the file it
 * parses and must not reach the parsed rows.
 */
final class AuditSheetReaderTest extends TestCase
{
    private AuditSheetReader $reader;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->reader = new AuditSheetReader();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->tempFiles = [];
    }

    public function testReadsACompleteSheetWithNoProblems(): void
    {
        $sheet = $this->reader->read($this->sheet([
            $this->line('o1', 'SB-T001', 'confirmed', 'correct', 'correct', 'correct'),
            $this->line('o2', 'SB-T002', 'review', 'correct', 'incorrect', 'correct', 'A quoted the sender claim, B read the offer half'),
        ]));

        $this->assertTrue($sheet->isComplete());
        $this->assertSame([], $sheet->problems);
        $this->assertCount(2, $sheet->rows);
        $this->assertSame('SB-T001', $sheet->rows[0]['ttp_code']);
        $this->assertSame('review', $sheet->rows[1]['status']);
    }

    public function testParsedRowsNeverCarryTheEvidenceColumn(): void
    {
        $sheet = $this->reader->read($this->sheet([
            $this->line('o1', 'SB-T001', 'confirmed', 'correct', 'correct', 'correct'),
        ]));

        $flattened = json_encode($sheet->rows);

        $this->assertIsString($flattened);
        $this->assertStringNotContainsString(
            'VERBATIM SCAMMER TEXT',
            $flattened,
            'the evidence column must not survive parsing (Constitution III)'
        );
        $this->assertSame(
            ['ttp_code', 'status', 'verdict_a', 'verdict_b', 'verdict_final', 'flag'],
            array_keys($sheet->rows[0])
        );
    }

    public function testSkipsBothBannerRecordsBeforeTheHeader(): void
    {
        $sheet = $this->reader->read($this->sheet([
            $this->line('o1', 'SB-T001', 'confirmed', 'correct', 'correct', 'correct'),
        ]));

        $this->assertCount(1, $sheet->rows);
    }

    public function testRejectsASheetMissingTheScoringColumns(): void
    {
        $path = $this->write("obs_id,ttp_code,status\no1,SB-T001,confirmed\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing required column\(s\).*verdict_a/');

        $this->reader->read($path);
    }

    public function testRejectsAMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found or not readable/');

        $this->reader->read('/nonexistent/scored-sheet.csv');
    }

    public function testRejectsASheetWithAHeaderButNoRows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no data rows/');

        $this->reader->read($this->sheet([]));
    }

    public function testReportsRowsThatAreNotDoubleScored(): void
    {
        $sheet = $this->reader->read($this->sheet([
            $this->line('o1', 'SB-T001', 'confirmed', 'correct', '', 'correct'),
        ]));

        $this->assertFalse($sheet->isComplete());
        $this->assertStringContainsString('not double-scored', implode("\n", $sheet->problems));
    }

    public function testReportsRowsWithNoAdjudicatedVerdict(): void
    {
        $sheet = $this->reader->read($this->sheet([
            $this->line('o1', 'SB-T001', 'confirmed', 'correct', 'correct', ''),
        ]));

        $this->assertStringContainsString('no adjudicated verdict_final', implode("\n", $sheet->problems));
    }

    public function testReportsADisagreementWithNoLoggedReason(): void
    {
        $sheet = $this->reader->read($this->sheet([
            $this->line('o1', 'SB-T001', 'confirmed', 'correct', 'incorrect', 'correct'),
        ]));

        $this->assertStringContainsString('adjudication_reason is empty', implode("\n", $sheet->problems));
    }

    public function testAcceptsADisagreementThatCarriesItsReason(): void
    {
        $sheet = $this->reader->read($this->sheet([
            $this->line('o1', 'SB-T001', 'confirmed', 'correct', 'incorrect', 'correct', 'span shows the windfall offer, T001 holds'),
        ]));

        $this->assertTrue($sheet->isComplete());
    }

    public function testReportsAnUnknownVerdict(): void
    {
        $sheet = $this->reader->read($this->sheet([
            $this->line('o1', 'SB-T001', 'confirmed', 'probably', 'correct', 'correct', 'scorer A used a word outside the vocabulary'),
        ]));

        $this->assertStringContainsString('unknown verdict "probably"', implode("\n", $sheet->problems));
    }

    public function testReportsAnUnknownFlag(): void
    {
        $sheet = $this->reader->read($this->sheet([
            $this->line('o1', 'SB-T001', 'confirmed', 'correct', 'correct', 'correct', '', 'weird'),
        ]));

        $this->assertStringContainsString('unknown flag "weird"', implode("\n", $sheet->problems));
    }

    public function testReportsADuplicatedObservation(): void
    {
        $sheet = $this->reader->read($this->sheet([
            $this->line('o1', 'SB-T001', 'confirmed', 'correct', 'correct', 'correct'),
            $this->line('o1', 'SB-T001', 'confirmed', 'correct', 'correct', 'correct'),
        ]));

        $this->assertStringContainsString('appears more than once', implode("\n", $sheet->problems));
        $this->assertCount(1, $sheet->rows);
    }

    public function testUnsamplableRowsMayBeLeftUnscored(): void
    {
        $sheet = $this->reader->read($this->sheet([
            $this->line('o1', 'SB-T001', 'confirmed', 'correct', 'correct', 'correct'),
            $this->line('o2', 'SB-T009', 'confirmed', '', '', '', '', 'unsamplable'),
        ]));

        $this->assertTrue($sheet->isComplete());
        $this->assertSame(AuditSheetReader::FLAG_UNSAMPLABLE, $sheet->rows[1]['flag']);
    }

    public function testNormalisesCaseAndSurroundingWhitespace(): void
    {
        $sheet = $this->reader->read($this->sheet([
            $this->line(' o1 ', ' sb-t001 ', ' CONFIRMED ', ' Correct ', ' CORRECT ', ' correct '),
        ]));

        $this->assertTrue($sheet->isComplete());
        $this->assertSame('SB-T001', $sheet->rows[0]['ttp_code']);
        $this->assertSame('confirmed', $sheet->rows[0]['status']);
        $this->assertSame('correct', $sheet->rows[0]['verdict_a']);
    }

    public function testIgnoresBlankLines(): void
    {
        $path = $this->write(
            "# banner\n"
            . "# draw=uniform seed=42 limit=1 ttp=all taxonomy_version=1.0\n"
            . "obs_id,ttp_code,status,evidence,verdict_a,verdict_b,verdict_final,adjudication_reason,flag,notes\n"
            . "\n"
            . "o1,SB-T001,confirmed,VERBATIM SCAMMER TEXT,correct,correct,correct,,,\n"
            . "\n"
        );

        $sheet = $this->reader->read($path);

        $this->assertCount(1, $sheet->rows);
        $this->assertTrue($sheet->isComplete());
    }

    /**
     * @param list<string> $rows
     */
    private function sheet(array $rows): string
    {
        return $this->write(
            "# ScamBuster TTP audit sample — contains RAW scammer evidence excerpts.\n"
            . "# draw=uniform seed=4242 limit=100 ttp=all taxonomy_version=1.0\n"
            . "obs_id,ttp_code,status,evidence,verdict_a,verdict_b,verdict_final,adjudication_reason,flag,notes\n"
            . implode('', $rows)
        );
    }

    private function line(
        string $obsId,
        string $code,
        string $status,
        string $verdictA,
        string $verdictB,
        string $verdictFinal,
        string $reason = '',
        string $flag = '',
    ): string {
        // Built with fputcsv rather than string concatenation: real adjudication
        // reasons contain commas, and a hand-joined fixture would quietly shift
        // every column after them. Same escaping as the export side.
        $handle = fopen('php://temp', 'r+');
        self::assertIsResource($handle);

        fputcsv($handle, [
            $obsId,
            $code,
            $status,
            'VERBATIM SCAMMER TEXT',
            $verdictA,
            $verdictB,
            $verdictFinal,
            $reason,
            $flag,
            '',
        ], escape: '');
        rewind($handle);
        $line = stream_get_contents($handle);
        fclose($handle);

        self::assertIsString($line);

        return $line;
    }

    private function write(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'audit-sheet-') . '.csv';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}

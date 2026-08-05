<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ttp;

use App\Application\Ttp\TtpObservationUpsertService;
use App\UI\Console\TtpAuditSampleCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration coverage of the TTP audit-sample export command.
 *
 * The command is the single sanctioned egress for verbatim TTP evidence, so
 * these tests deliberately assert that the evidence column is present and
 * populated — the opposite of every other read/export path. Observations are
 * seeded through the real upsert service on the fixture dataset and rolled back
 * per test by DAMA. Output is written to a temp file (cleaned up in finally).
 */
final class TtpAuditSampleCommandTest extends KernelTestCase
{
    private const HEADER = [
        'obs_id', 'conv_id', 'msg_id', 'ttp_code', 'ttp_label', 'phase',
        'confidence', 'status', 'evidence', 'evidence_start', 'evidence_end',
        'taxonomy_version', 'extraction_model', 'prompt_version', 'created_at',
    ];

    private Connection $connection;
    private TtpObservationUpsertService $upsert;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->upsert = self::getContainer()->get(TtpObservationUpsertService::class);
    }

    // ─── header + rows + evidence egress ───────────────────────────────

    public function testExportWritesBannerHeaderAndEveryRow(): void
    {
        $messages = $this->inboundMessages(3);
        self::assertCount(3, $messages, 'The fixture dataset must provide three inbound messages');

        foreach ($messages as $i => $m) {
            $this->seed($m['msg_id'], $m['conv_id'], 'SB-T017', 0.9, 'confirmed', "evidence excerpt {$i}");
        }

        $path = $this->tempFile();

        try {
            $tester = $this->runCommand(['--output' => $path]);

            self::assertSame(Command::SUCCESS, $tester->getStatusCode());

            $contents = (string) file_get_contents($path);
            self::assertStringContainsString('INTERNAL MANUAL AUDIT ONLY', $contents, 'The file must carry the evidence-egress banner');

            $parsed = $this->parse($path);
            self::assertSame(self::HEADER, $parsed['header']);
            self::assertContains('evidence', $parsed['header'], 'Evidence is included on purpose in the audit egress');
            self::assertCount(3, $parsed['rows']);
        } finally {
            @unlink($path);
        }
    }

    public function testEvidenceColumnCarriesTheVerbatimQuote(): void
    {
        $m = $this->inboundMessages(1)[0];
        $this->seed($m['msg_id'], $m['conv_id'], 'SB-T017', 0.88, 'confirmed', 'send 500 USD via gift cards today');

        $path = $this->tempFile();

        try {
            $tester = $this->runCommand(['--output' => $path]);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode());

            $rows = $this->parse($path)['rows'];
            self::assertCount(1, $rows);
            self::assertSame('send 500 USD via gift cards today', $rows[0]['evidence']);
            self::assertSame('SB-T017', $rows[0]['ttp_code']);
        } finally {
            @unlink($path);
        }
    }

    // ─── --limit ────────────────────────────────────────────────────────

    public function testLimitCapsTheSampleSize(): void
    {
        foreach ($this->inboundMessages(4) as $i => $m) {
            $this->seed($m['msg_id'], $m['conv_id'], 'SB-T017', 0.9, 'confirmed', "excerpt {$i}");
        }

        $path = $this->tempFile();

        try {
            $tester = $this->runCommand(['--output' => $path, '--limit' => '2']);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode());
            self::assertCount(2, $this->parse($path)['rows'], '--limit must cap the number of rows exported');
        } finally {
            @unlink($path);
        }
    }

    // ─── --seed reproducibility ──────────────────────────────────────────

    public function testSeedProducesAReproducibleSample(): void
    {
        foreach ($this->inboundMessages(5) as $i => $m) {
            $this->seed($m['msg_id'], $m['conv_id'], 'SB-T017', 0.9, 'confirmed', "excerpt {$i}");
        }

        $first = $this->tempFile();
        $second = $this->tempFile();

        try {
            self::assertSame(Command::SUCCESS, $this->runCommand(['--output' => $first, '--seed' => '123', '--limit' => '3'])->getStatusCode());
            self::assertSame(Command::SUCCESS, $this->runCommand(['--output' => $second, '--seed' => '123', '--limit' => '3'])->getStatusCode());

            $idsFirst = array_column($this->parse($first)['rows'], 'obs_id');
            $idsSecond = array_column($this->parse($second)['rows'], 'obs_id');

            self::assertCount(3, $idsFirst);
            self::assertSame($idsFirst, $idsSecond, 'The same seed must return the same rows in the same order');
        } finally {
            @unlink($first);
            @unlink($second);
        }
    }

    // ─── --ttp filter ────────────────────────────────────────────────────

    public function testTtpFilterRestrictsToOneCode(): void
    {
        $messages = $this->inboundMessages(3);

        foreach ($messages as $i => $m) {
            $this->seed($m['msg_id'], $m['conv_id'], 'SB-T017', 0.9, 'confirmed', "t17 {$i}");
        }
        // Add a second code on two of the same messages (unique on msg_id+ttp_id).
        $this->seed($messages[0]['msg_id'], $messages[0]['conv_id'], 'SB-T022', 0.4, 'review', 't22 a');
        $this->seed($messages[1]['msg_id'], $messages[1]['conv_id'], 'SB-T022', 0.4, 'review', 't22 b');

        $path = $this->tempFile();

        try {
            $tester = $this->runCommand(['--output' => $path, '--ttp' => 'SB-T017']);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode());

            $rows = $this->parse($path)['rows'];
            self::assertCount(3, $rows);

            foreach ($rows as $row) {
                self::assertSame('SB-T017', $row['ttp_code'], 'The --ttp filter must exclude every other code');
            }
        } finally {
            @unlink($path);
        }
    }

    // ─── stdout streaming + warning ──────────────────────────────────────

    public function testWithoutOutputTheCsvStreamsToStdout(): void
    {
        $m = $this->inboundMessages(1)[0];
        $this->seed($m['msg_id'], $m['conv_id'], 'SB-T017', 0.9, 'confirmed', 'streamed excerpt');

        $tester = $this->runCommand([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('INTERNAL MANUAL AUDIT ONLY', $display);
        self::assertStringContainsString('obs_id', $display, 'The CSV header streams to stdout');
        self::assertStringContainsString('streamed excerpt', $display, 'The evidence streams to stdout');
    }

    public function testWarningAndNoMetricAreReported(): void
    {
        $m = $this->inboundMessages(1)[0];
        $this->seed($m['msg_id'], $m['conv_id'], 'SB-T017', 0.9, 'confirmed', 'warn excerpt');

        $path = $this->tempFile();

        try {
            $tester = $this->runCommand(['--output' => $path]);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode());

            $display = $tester->getDisplay();
            self::assertStringContainsString('RAW scammer evidence', $display, 'The evidence warning must be surfaced');
            self::assertStringContainsString('No precision metric', $display, 'The command must state it computes no precision figure');
        } finally {
            @unlink($path);
        }
    }

    public function testEmptySampleStillWritesBannerAndHeader(): void
    {
        // No observations seeded: the export is empty but well-formed.
        $path = $this->tempFile();

        try {
            $tester = $this->runCommand(['--output' => $path]);
            self::assertSame(Command::SUCCESS, $tester->getStatusCode());

            $parsed = $this->parse($path);
            self::assertSame(self::HEADER, $parsed['header']);
            self::assertCount(0, $parsed['rows']);
        } finally {
            @unlink($path);
        }
    }

    // ─── helpers ──────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $input
     */
    private function runCommand(array $input): CommandTester
    {
        $command = self::getContainer()->get(TtpAuditSampleCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    /**
     * @return list<array{msg_id: string, conv_id: string}>
     */
    private function inboundMessages(int $limit): array
    {
        /** @var list<array{msg_id: string, conv_id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT m.msg_id, m.conv_id
             FROM message m
             JOIN lkp_direction d ON d.dir_id = m.direction AND d.code = 'in'
             JOIN conversation c ON c.conv_id = m.conv_id AND c.deleted_at IS NULL
             WHERE m.deleted_at IS NULL
             ORDER BY m.msg_id ASC
             LIMIT {$limit}"
        );

        return $rows;
    }

    private function ttpId(string $code): int
    {
        return (int) $this->connection->fetchOne('SELECT ttp_id FROM lkp_ttp WHERE code = :code', ['code' => $code]);
    }

    private function seed(string $msgId, string $convId, string $code, float $confidence, string $status, string $evidence): void
    {
        $this->upsert->upsert([
            'msg_id' => $msgId,
            'conv_id' => $convId,
            'ttp_id' => $this->ttpId($code),
            'confidence' => $confidence,
            'evidence' => $evidence,
            'evidence_start' => null,
            'evidence_end' => null,
            'status' => $status,
            'taxonomy_version' => '1.0',
            'extraction_model' => 'gpt-4o-mini',
            'prompt_version' => 'v1',
        ]);
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ttp_audit_');
        self::assertIsString($path);

        return $path;
    }

    /**
     * Parse the CSV, skipping the leading evidence banner.
     *
     * @return array{header: list<string>, rows: list<array<string, string>>}
     */
    private function parse(string $path): array
    {
        $handle = fopen($path, 'r');
        self::assertIsResource($handle);

        $header = [];
        $rows = [];

        try {
            while (($record = fgetcsv($handle)) !== false) {
                if ($record === [null] || $record === false) {
                    continue;
                }
                $first = (string) ($record[0] ?? '');

                if (str_starts_with($first, '#')) {
                    continue; // banner line
                }

                if ($header === []) {
                    $header = array_map('strval', $record);

                    continue;
                }

                /** @var array<string, string> $assoc */
                $assoc = array_combine($header, array_map('strval', $record));
                $rows[] = $assoc;
            }
        } finally {
            fclose($handle);
        }

        return ['header' => $header, 'rows' => $rows];
    }
}

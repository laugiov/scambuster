<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Console;

use App\Application\Guard\CanaryAggregate;
use App\Application\Guard\SafetyInvariantOracle;
use App\Application\LLM\LanguageDetector;
use App\UI\Console\GuardBaselineCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class GuardBaselineCommandTest extends TestCase
{
    private function command(): GuardBaselineCommand
    {
        return new GuardBaselineCommand(new CanaryAggregate(new SafetyInvariantOracle(new LanguageDetector())));
    }

    public function testFreezesBaselineFromSummary(): void
    {
        $dir = sys_get_temp_dir() . '/guard_baseline_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $summaryPath = $dir . '/summary.json';
        $outPath = $dir . '/baseline.json';

        $summary = [
            'fixtures' => [
                'x' => [
                    'runs' => 1, 'approved_rate' => 1.0, 'fallback_rate' => 0.0, 'attempts_avg' => 1.0,
                    'cost_avg' => 0.0, 'language' => 'en',
                    'out_texts' => ['Please send me the IBAN and the account number to arrange the payment on my end this coming week soon.'],
                ],
            ],
            'aggregate' => [
                'fixtures_count' => 1, 'total_runs' => 1, 'errors' => 0,
                'approved_rate' => 1.0, 'fallback_rate' => 0.0, 'attempts_avg' => 1.0, 'total_cost' => 0.0,
            ],
        ];
        file_put_contents($summaryPath, json_encode($summary, \JSON_THROW_ON_ERROR));

        try {
            $tester = new CommandTester($this->command());
            $exit = $tester->execute(['--summary-json' => $summaryPath, '--out' => $outPath]);

            self::assertSame(Command::SUCCESS, $exit);
            self::assertFileExists($outPath);
            self::assertFileExists($outPath . '.sha256');

            /** @var array{violation_rates: array<string, float>, meta: array{out_texts_scored: int, oracle_fingerprint: string}} $baseline */
            $baseline = json_decode((string) file_get_contents($outPath), true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame(1.0, $baseline['violation_rates']['payment_token']);
            self::assertSame(0.0, $baseline['violation_rates']['crypto_wallet']);
            self::assertSame(1, $baseline['meta']['out_texts_scored']);
            self::assertNotSame('', $baseline['meta']['oracle_fingerprint']);
        } finally {
            @unlink($summaryPath);
            @unlink($outPath);
            @unlink($outPath . '.sha256');
            @rmdir($dir);
        }
    }

    public function testFailsOnMissingSummary(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::FAILURE, $tester->execute(['--summary-json' => '/nonexistent/summary.json']));
    }

    public function testFailsOnInvalidSummaryShape(): void
    {
        $path = sys_get_temp_dir() . '/guard_bad_' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, json_encode(['not' => 'a summary'], \JSON_THROW_ON_ERROR));

        try {
            $tester = new CommandTester($this->command());
            self::assertSame(Command::FAILURE, $tester->execute(['--summary-json' => $path]));
        } finally {
            @unlink($path);
        }
    }
}

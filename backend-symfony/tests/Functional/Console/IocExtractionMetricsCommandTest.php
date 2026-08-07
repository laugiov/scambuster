<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\UI\Console\IocExtractionMetricsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class IocExtractionMetricsCommandTest extends TestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testReportsPrecisionAndRecallFromGoldFile(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'gold') . '.json';
        file_put_contents($this->file, json_encode([
            [
                'gold' => [['type' => 'iban', 'value_norm' => 'A'], ['type' => 'phone', 'value_norm' => 'P']],
                'predicted' => [['type' => 'iban', 'value_norm' => 'A']], // misses the phone → recall 0.5
            ],
        ], JSON_THROW_ON_ERROR));

        $tester = new CommandTester(new IocExtractionMetricsCommand());
        $exit = $tester->execute(['gold-file' => $this->file]);

        self::assertSame(0, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('precision', $display);
        self::assertStringContainsString('0.5', $display, 'recall must be 0.5 (one of two IOCs missed)');
        self::assertStringContainsString('iban', $display);
    }

    public function testMissingFileIsAnError(): void
    {
        $tester = new CommandTester(new IocExtractionMetricsCommand());
        $exit = $tester->execute(['gold-file' => '/no/such/gold.json']);

        self::assertSame(2, $exit); // Command::INVALID
    }
}

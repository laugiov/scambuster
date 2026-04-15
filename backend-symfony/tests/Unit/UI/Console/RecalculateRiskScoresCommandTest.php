<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Console;

use App\Application\Communication\RiskScoreCalculator;
use App\UI\Console\RecalculateRiskScoresCommand;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \App\UI\Console\RecalculateRiskScoresCommand
 * @covers \App\Application\Communication\RiskScoreCalculator
 */
final class RecalculateRiskScoresCommandTest extends TestCase
{
    /**
     * Test: CHARITY + IBAN + wallet_btc recalculates to > 70.
     */
    public function testCharityWithFinancialIocsRecalculatesAbove70(): void
    {
        $calculator = new RiskScoreCalculator();

        // CHARITY base = 25
        // iban + wallet_btc = 2 financial types -> +30 + 10 = +40
        // 2 types -> +6 (2*3)
        // Total = 25 + 40 + 6 = 71
        $score = $calculator->compute('CHARITY', ['iban' => true, 'wallet_btc' => true]);

        self::assertGreaterThan(70, $score);
    }

    /**
     * Test: command updates conversation when score differs.
     */
    public function testCommandUpdatesWhenScoreDiffers(): void
    {
        $conn = $this->createMock(Connection::class);
        $calculator = new RiskScoreCalculator();

        $callIndex = 0;
        $conn->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql) use (&$callIndex): array {
                ++$callIndex;

                if ($callIndex === 1) {
                    // Conversations query
                    return [
                        ['conv_id' => 'conv-1', 'score_risk' => 10, 'scam_code' => 'CHARITY'],
                    ];
                }

                // IOC types query for conv-1
                return [
                    ['ioc_type' => 'iban', 'cnt' => 1],
                    ['ioc_type' => 'wallet_btc', 'cnt' => 1],
                ];
            });

        $conn->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('UPDATE conversation'),
                self::callback(function (array $params): bool {
                    return $params['convId'] === 'conv-1' && $params['score'] > 70;
                })
            );

        $tester = $this->createTester($conn, $calculator);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Updated', $tester->getDisplay());
    }

    /**
     * Test: --dry-run does not write.
     */
    public function testDryRunDoesNotUpdate(): void
    {
        $conn = $this->createMock(Connection::class);
        $calculator = new RiskScoreCalculator();

        $callIndex = 0;
        $conn->method('fetchAllAssociative')
            ->willReturnCallback(function () use (&$callIndex): array {
                ++$callIndex;

                if ($callIndex === 1) {
                    return [
                        ['conv_id' => 'conv-1', 'score_risk' => 10, 'scam_code' => 'CHARITY'],
                    ];
                }

                return [
                    ['ioc_type' => 'iban', 'cnt' => 1],
                ];
            });

        $conn->expects(self::never())
            ->method('executeStatement');

        $tester = $this->createTester($conn, $calculator);
        $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Dry-run', $tester->getDisplay());
    }

    /**
     * Test: RiskScoreCalculator with diverse IOC set.
     */
    public function testRiskCalculatorDiverseIocs(): void
    {
        $calculator = new RiskScoreCalculator();

        // CEO_FRAUD (70) + iban(+30) + phone(+15) + url(5*2=10) + email
        // 4 types -> +10 diversity + 4*3=12
        // Total = 70 + 30 + 15 + 10 + 10 + 12 = 147 -> capped at 100
        $score = $calculator->compute('CEO_FRAUD', [
            'iban' => true,
            'phone' => true,
            'url' => true,
            'email' => true,
        ], 2);

        self::assertSame(100, $score);
    }

    /**
     * Test: RiskScoreCalculator with no IOCs.
     */
    public function testRiskCalculatorNoIocs(): void
    {
        $calculator = new RiskScoreCalculator();
        $score = $calculator->compute('UNKNOWN', []);

        self::assertSame(30, $score);
    }

    private function createTester(Connection $conn, RiskScoreCalculator $calculator): CommandTester
    {
        $command = new RecalculateRiskScoresCommand($conn, $calculator);
        $app = new Application();
        $app->add($command);

        return new CommandTester($app->find('app:fix:risk-scores'));
    }
}

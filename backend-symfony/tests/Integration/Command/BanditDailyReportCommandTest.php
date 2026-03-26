<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\BanditDailyReportCommand;
use App\Domain\Scambaiting\BanditConvergenceLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class BanditDailyReportCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testHappyPathLogsConvergenceForActiveScamTypes(): void
    {
        // Fixtures provide active scam types + persona_performance_stats rows
        $command = self::getContainer()->get(BanditDailyReportCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Logged convergence for', $output);

        // Verify BanditConvergenceLog rows were persisted
        $logs = $this->em->getRepository(BanditConvergenceLog::class)->findAll();
        $this->assertNotEmpty($logs, 'At least one convergence log entry should be persisted');

        // Check that one of the logged scam type codes matches a known fixture scam type
        $codes = array_map(fn (BanditConvergenceLog $l) => $l->getScamTypeCode(), $logs);
        // PHISHING is active and has performance stats in fixtures
        $this->assertContains('PHISHING', $codes);
    }

    public function testEmptyDatabaseOutputsWarning(): void
    {
        // Delete all active scam types to simulate empty DB
        $conn = $this->em->getConnection();
        $conn->executeStatement("UPDATE lkp_scam_type SET active = false WHERE code != 'UNKNOWN'");
        // Also mark 'unknown' inactive (it is excluded by the query anyway via code != 'UNKNOWN')
        $conn->executeStatement("UPDATE lkp_scam_type SET active = false WHERE code = 'unknown'");

        $command = self::getContainer()->get(BanditDailyReportCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('No active scam types found', $output);
    }
}

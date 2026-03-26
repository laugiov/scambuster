<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\CalculateRewardsCommand;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CalculateRewardsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testRewardCalculationForClosedConversationsWithoutReward(): void
    {
        // Fixture conv 002 is CLOSED - ensure rewardValue is null to be eligible
        $conv = $this->em->getRepository(Conversation::class)->find('00000000-0000-0000-0000-000000000002');
        $this->assertNotNull($conv);

        $reflection = new \ReflectionProperty(Conversation::class, 'rewardValue');
        $reflection->setValue($conv, null);
        $this->em->flush();

        $command = self::getContainer()->get(CalculateRewardsCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        // The command displays a table with metrics
        $this->assertStringContainsString('Total', $output);
    }

    public function testNoConversationsToProcessOutputsSuccess(): void
    {
        // Set reward values on all closed conversations so none are eligible
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "UPDATE conversation SET reward_value = 0.5 WHERE status = 'closed' OR status = 'CLOSED'"
        );

        $command = self::getContainer()->get(CalculateRewardsCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Aucune conversation', $output);
    }
}

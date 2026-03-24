<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\CloseStaleConversationsCommand;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class CloseStaleConversationsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testClosesStaleConversations(): void
    {
        // Make fixture conversation 001 stale by setting ts_last to 10 days ago
        $conv = $this->em->getRepository(Conversation::class)->find('00000000-0000-0000-0000-000000000001');
        $this->assertNotNull($conv);
        $this->assertSame(ConversationStatus::OPEN, $conv->getStatus());

        $reflection = new \ReflectionProperty(Conversation::class, 'tsLast');
        $reflection->setValue($conv, new \DateTimeImmutable('-10 days'));
        $this->em->flush();

        $command = self::getContainer()->get(CloseStaleConversationsCommand::class);
        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute(['--days' => '7']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Closed', $output);

        // Verify conversation is now closed
        $this->em->refresh($conv);
        $this->assertSame(ConversationStatus::CLOSED, $conv->getStatus());
        $this->assertNotNull($conv->getRewardValue());
    }

    public function testDryRunDoesNotModify(): void
    {
        // Make fixture conversation 001 stale
        $conv = $this->em->getRepository(Conversation::class)->find('00000000-0000-0000-0000-000000000001');
        $this->assertNotNull($conv);

        // Reset to OPEN if previous test changed it
        $reflection = new \ReflectionProperty(Conversation::class, 'status');
        $reflection->setValue($conv, ConversationStatus::OPEN);
        $reflectionTs = new \ReflectionProperty(Conversation::class, 'tsLast');
        $reflectionTs->setValue($conv, new \DateTimeImmutable('-10 days'));
        $reflectionReward = new \ReflectionProperty(Conversation::class, 'rewardValue');
        $reflectionReward->setValue($conv, null);
        $this->em->flush();

        $command = self::getContainer()->get(CloseStaleConversationsCommand::class);
        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute(['--days' => '7', '--dry-run' => true]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Dry run', $output);

        // Verify conversation is still OPEN
        $this->em->refresh($conv);
        $this->assertSame(ConversationStatus::OPEN, $conv->getStatus());
    }

    public function testCustomDaysThreshold(): void
    {
        $conv = $this->em->getRepository(Conversation::class)->find('00000000-0000-0000-0000-000000000001');
        $this->assertNotNull($conv);

        // Set ts_last to 3 days ago
        $reflection = new \ReflectionProperty(Conversation::class, 'status');
        $reflection->setValue($conv, ConversationStatus::OPEN);
        $reflectionTs = new \ReflectionProperty(Conversation::class, 'tsLast');
        $reflectionTs->setValue($conv, new \DateTimeImmutable('-3 days'));
        $reflectionReward = new \ReflectionProperty(Conversation::class, 'rewardValue');
        $reflectionReward->setValue($conv, null);
        $this->em->flush();

        $command = self::getContainer()->get(CloseStaleConversationsCommand::class);
        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($command);

        // With --days=7, conversation at 3 days should NOT be stale
        $tester->execute(['--days' => '7']);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('none need closing', $output);

        // With --days=2, conversation at 3 days IS stale
        $tester->execute(['--days' => '2']);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Closed', $output);
    }
}

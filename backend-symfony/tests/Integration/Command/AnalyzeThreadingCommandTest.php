<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\AnalyzeThreadingCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class AnalyzeThreadingCommandTest extends KernelTestCase
{
    public function testThreadingOutputWithMatchingSubject(): void
    {
        self::bootKernel();

        $command = self::getContainer()->get(AnalyzeThreadingCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        // Fixture messages have subject 'Inbound message' and 'Outbound message'
        $tester->execute(['subject_pattern' => 'Inbound']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Analyzing threading for subject pattern:', $output);
        $this->assertStringContainsString('Found', $output);
        $this->assertStringContainsString('messages', $output);
        // Should show threading analysis section
        $this->assertStringContainsString('Threading Analysis', $output);
        $this->assertStringContainsString('Recommendations', $output);
    }

    public function testNoMatchingMessagesReturnsFailure(): void
    {
        self::bootKernel();

        $command = self::getContainer()->get(AnalyzeThreadingCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute(['subject_pattern' => 'NONEXISTENT_PATTERN_XYZ_12345']);

        // Command returns FAILURE when no messages found
        $this->assertSame(1, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('No messages found', $output);
    }
}

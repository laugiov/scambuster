<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\DetectPromptInjectionCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class DetectPromptInjectionCommandTest extends KernelTestCase
{
    public function testPatternOnlyDryRunWithFixtureMessages(): void
    {
        self::bootKernel();

        $command = self::getContainer()->get(DetectPromptInjectionCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        // Use pattern-only + dry-run to avoid LLM calls and DB writes
        $tester->execute([
            '--pattern-only' => true,
            '--dry-run' => true,
            '--limit' => '5',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Prompt Injection Detection', $output);
        // Should find fixture inbound messages or report none
        $this->assertTrue(
            str_contains($output, 'Found') || str_contains($output, 'No messages to analyze'),
            'Output should report found messages or indicate none to analyze'
        );
    }

    public function testNoMessagesForNonExistentConversation(): void
    {
        self::bootKernel();

        $command = self::getContainer()->get(DetectPromptInjectionCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute([
            '--conversation' => '99999999-9999-9999-9999-999999999999',
            '--pattern-only' => true,
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('No messages to analyze', $output);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\CheckLlmBudgetCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Spec 065b — Phase 4 — Tests for `app:llm:check-budget` command.
 */
final class CheckLlmBudgetCommandTest extends KernelTestCase
{
    public function test_it_executes_successfully(): void
    {
        self::bootKernel();
        $command = self::getContainer()->get(CheckLlmBudgetCommand::class);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function test_command_is_registered_with_correct_name(): void
    {
        self::bootKernel();
        $command = self::getContainer()->get(CheckLlmBudgetCommand::class);

        $this->assertSame('app:llm:check-budget', $command->getName());
    }
}

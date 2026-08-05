<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Console;

use App\Application\LLM\Prompt\PromptProvider;
use App\UI\Console\PromptDiagCommand;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

final class PromptDiagCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scambuster_diag_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->dir);
    }

    private function tester(): CommandTester
    {
        $command = new PromptDiagCommand(
            new PromptProvider($this->dir, new NullLogger()),
            $this->dir,
            0.7,
        );

        return new CommandTester($command);
    }

    public function testListsShippedDefaultsWhenNoOverrides(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]));
        $out = $tester->getDisplay();
        self::assertStringContainsString('contextual_enrichment', $out);
        self::assertStringContainsString('reward_judge', $out);
        self::assertStringContainsString('shipped default', $out);
        self::assertStringContainsString('scambuster.reward.llm_weight = 0.7', $out);
    }

    public function testShowsOverrideActiveWhenValidFilePresent(): void
    {
        file_put_contents($this->dir . '/reward_judge.txt', 'CUSTOM RUBRIC');

        $tester = $this->tester();
        $tester->execute([]);
        $out = $tester->getDisplay();

        self::assertStringContainsString('OVERRIDE ACTIVE', $out);
    }

    public function testShowsRejectedWhenOverrideMissesRequiredToken(): void
    {
        // contextual_enrichment requires {{SCAM_TYPE}} etc.; this override drops them.
        file_put_contents($this->dir . '/contextual_enrichment.txt', 'no tokens here at all');

        $tester = $this->tester();
        $tester->execute([]);
        $out = $tester->getDisplay();

        self::assertStringContainsString('OVERRIDE REJECTED', $out);
    }

    public function testShowOneReturnsWarningWhenNoOverride(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['key' => 'reward_judge']));
        self::assertStringContainsString('No active override', $tester->getDisplay());
    }

    public function testShowOnePrintsActiveOverrideContent(): void
    {
        file_put_contents($this->dir . '/reward_judge.txt', 'MY CUSTOM RUBRIC TEXT');

        $tester = $this->tester();
        $tester->execute(['key' => 'reward_judge']);

        self::assertStringContainsString('MY CUSTOM RUBRIC TEXT', $tester->getDisplay());
    }

    public function testUnknownKeyIsRejected(): void
    {
        $tester = $this->tester();

        self::assertSame(2, $tester->execute(['key' => 'does_not_exist'])); // Command::INVALID
        self::assertStringContainsString('Unknown prompt key', $tester->getDisplay());
    }
}

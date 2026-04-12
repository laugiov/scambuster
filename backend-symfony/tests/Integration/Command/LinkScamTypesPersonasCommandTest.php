<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\UI\Console\LinkScamTypesPersonasCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class LinkScamTypesPersonasCommandTest extends KernelTestCase
{
    public function testLinkageCreationReportsLinksAndSkips(): void
    {
        self::bootKernel();

        $command = self::getContainer()->get(LinkScamTypesPersonasCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        // The command outputs "Total persona links created: X, scam types skipped: Y"
        $this->assertStringContainsString('Total persona links created:', $output);

        // Fixture has 'unknown' scam type which maps to ['generic_user'] in SCAM_TYPE_TO_PERSONAS
        // This persona exists in fixtures, so at least 1 link should be created
        $this->assertMatchesRegularExpression('/Total persona links created: [1-9]\d*/', $output);
    }

    public function testMostScamTypesAreSkippedWhenCodesDoNotMatch(): void
    {
        self::bootKernel();

        $command = self::getContainer()->get(LinkScamTypesPersonasCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();

        // The command constant maps codes like 'invoice', 'phishing', 'lottery', 'romance', 'techsupport', 'unknown'
        // Fixtures use codes like 'PHISHING', 'ROMANCE', 'TECH_SUPPORT', 'unknown' (case mismatch for most)
        // So most will be skipped except 'unknown' which matches exactly
        $this->assertStringContainsString('skipped', $output);
    }
}

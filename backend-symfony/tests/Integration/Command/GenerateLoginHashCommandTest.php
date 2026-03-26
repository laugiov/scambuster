<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\GenerateLoginHashCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class GenerateLoginHashCommandTest extends KernelTestCase
{
    public function testDeterministicHashGeneration(): void
    {
        self::bootKernel();

        $command = self::getContainer()->get(GenerateLoginHashCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute(['login' => 'admin@scambuster.local']);
        $hash1 = trim($tester->getDisplay());

        $this->assertSame(0, $tester->getStatusCode());
        // SHA256 produces 64 hex chars
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash1);

        // Run again with same input - must produce same hash
        $tester->execute(['login' => 'admin@scambuster.local']);
        $hash2 = trim($tester->getDisplay());

        $this->assertSame($hash1, $hash2, 'Hash should be deterministic for the same input');
    }

    public function testDifferentLoginsProduceDifferentHashes(): void
    {
        self::bootKernel();

        $command = self::getContainer()->get(GenerateLoginHashCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute(['login' => 'user1@example.com']);
        $hash1 = trim($tester->getDisplay());

        $tester->execute(['login' => 'user2@example.com']);
        $hash2 = trim($tester->getDisplay());

        $this->assertNotSame($hash1, $hash2, 'Different logins should produce different hashes');
    }

    public function testMissingLoginArgumentThrowsException(): void
    {
        self::bootKernel();

        $command = self::getContainer()->get(GenerateLoginHashCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $tester->execute([]);
    }

    public function testOutputContainsOnlyHash(): void
    {
        self::bootKernel();

        $command = self::getContainer()->get(GenerateLoginHashCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute(['login' => 'test@scambuster.local']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = trim($tester->getDisplay());
        // Output should be exactly a 64-char hex string
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $output);
    }

    public function testEmptyStringLoginProducesHash(): void
    {
        self::bootKernel();

        $command = self::getContainer()->get(GenerateLoginHashCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute(['login' => '']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = trim($tester->getDisplay());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $output);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\UI\Console\MigrateHeaderIocsCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * MigrateHeaderIocsCommand was the historical bug vector: it looped over ALL
 * messages without filtering on direction, polluting the indicator table with
 * the honeypot's own headers extracted from outgoing replies.
 *
 * The command must skip every message with direction='out'.
 */
final class MigrateHeaderIocsCommandSkipOutgoingTest extends KernelTestCase
{
    public function testCommandSkipsOutgoingMessages(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var Connection $conn */
        $conn = $container->get(Connection::class);

        // Snapshot: how many outgoing messages with non-null headers exist in fixtures?
        $outgoingWithHeaders = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM message m
             JOIN lkp_direction d ON m.direction = d.dir_id
             WHERE d.code = 'out' AND m.headers IS NOT NULL AND m.deleted_at IS NULL"
        );

        // The command must process zero of them (otherwise it would re-pollute the indicator table).
        $command = $container->get(MigrateHeaderIocsCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());

        // The output must mention the total messages found = ONLY incoming ones.
        $output = $tester->getDisplay();
        $totalIncomingWithHeaders = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM message m
             JOIN lkp_direction d ON m.direction = d.dir_id
             WHERE d.code = 'in' AND m.headers IS NOT NULL AND m.deleted_at IS NULL"
        );

        // Sanity: fixtures must have BOTH incoming and outgoing messages with headers,
        // otherwise this test would be vacuous.
        $this->assertGreaterThan(
            0,
            $outgoingWithHeaders,
            'Fixture must contain at least one outgoing message with headers for this test to be meaningful.'
        );
        $this->assertGreaterThan(0, $totalIncomingWithHeaders);

        // The reported "Found N messages to process" line must equal the incoming count,
        // not the (incoming + outgoing) total.
        $this->assertStringContainsString(
            sprintf('Found %d messages to process', $totalIncomingWithHeaders),
            $output,
        );

        // Inverse assertion: the (incoming + outgoing) total MUST NOT appear,
        // i.e. the command did not loop over outgoing messages.
        $this->assertStringNotContainsString(
            sprintf('Found %d messages to process', $totalIncomingWithHeaders + $outgoingWithHeaders),
            $output,
        );
    }
}

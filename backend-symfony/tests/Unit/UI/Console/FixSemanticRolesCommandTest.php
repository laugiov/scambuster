<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Console;

use App\UI\Console\FixSemanticRolesCommand;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \App\UI\Console\FixSemanticRolesCommand
 */
final class FixSemanticRolesCommandTest extends TestCase
{
    /**
     * Test: sha256 in footer position -> role updated to IDENTITY_DOCUMENT.
     */
    public function testSha256InFooterIsUpdated(): void
    {
        $hash = 'abc123def456';
        // Place hash in the last 20% of the body
        $body = str_repeat('A', 800) . $hash;

        $conn = $this->createMock(Connection::class);

        $conn->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'id' => 1,
                    'obs_id' => 'obs-1',
                    'ioc_value' => $hash,
                    'ioc_type' => 'sha256',
                    'body_text' => $body,
                    'msg_id' => 'msg-1',
                ],
            ]);

        $conn->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('IDENTITY_DOCUMENT'),
                self::callback(function (array $params): bool {
                    return $params['id'] === 1;
                })
            );

        $tester = $this->createTester($conn);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('1', $display);
    }

    /**
     * Test: sha256 inline (not in footer) -> not updated.
     */
    public function testSha256InlineIsSkipped(): void
    {
        $hash = 'abc123def456';
        // Place hash in the first 50% of the body
        $body = $hash . str_repeat('A', 800);

        $conn = $this->createMock(Connection::class);

        $conn->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'id' => 2,
                    'obs_id' => 'obs-2',
                    'ioc_value' => $hash,
                    'ioc_type' => 'sha256',
                    'body_text' => $body,
                    'msg_id' => 'msg-2',
                ],
            ]);

        $conn->expects(self::never())
            ->method('executeStatement');

        $tester = $this->createTester($conn);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Skipped', $display);
    }

    /**
     * Test: --dry-run flag prevents any UPDATE.
     */
    public function testDryRunDoesNotUpdate(): void
    {
        $hash = 'footerhash';
        $body = str_repeat('X', 800) . $hash;

        $conn = $this->createMock(Connection::class);

        $conn->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'id' => 3,
                    'obs_id' => 'obs-3',
                    'ioc_value' => $hash,
                    'ioc_type' => 'sha256',
                    'body_text' => $body,
                    'msg_id' => 'msg-3',
                ],
            ]);

        $conn->expects(self::never())
            ->method('executeStatement');

        $tester = $this->createTester($conn);
        $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Dry-run', $display);
    }

    /**
     * Test the static isInFooter helper directly.
     */
    public function testIsInFooterLogic(): void
    {
        // Hash at position 80% of a 100-char body -> footer
        $body = str_repeat('A', 80) . 'HASH1234';
        self::assertTrue(FixSemanticRolesCommand::isInFooter('HASH1234', $body));

        // Hash at position 0% -> not footer
        $body2 = 'HASH1234' . str_repeat('A', 80);
        self::assertFalse(FixSemanticRolesCommand::isInFooter('HASH1234', $body2));

        // Hash not present
        self::assertFalse(FixSemanticRolesCommand::isInFooter('MISSING', $body));

        // Empty body
        self::assertFalse(FixSemanticRolesCommand::isInFooter('HASH', ''));
    }

    private function createTester(Connection $conn): CommandTester
    {
        $command = new FixSemanticRolesCommand($conn);
        $app = new Application();
        $app->add($command);

        return new CommandTester($app->find('app:fix:semantic-roles'));
    }
}

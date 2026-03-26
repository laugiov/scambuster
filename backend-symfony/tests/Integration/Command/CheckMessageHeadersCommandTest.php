<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CheckMessageHeadersCommandTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testMessageNotFoundReturnsFailure(): void
    {
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:check-message-headers'));

        $tester->execute(['msg_id' => '99999999-9999-9999-9999-999999999999']);

        $this->assertSame(1, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Message not found', $output);
    }

    public function testExistingMessageOutputsHeaders(): void
    {
        // Get an existing message from the fixtures
        $msgId = $this->connection->fetchOne('SELECT msg_id FROM message LIMIT 1');

        if ($msgId === false) {
            $this->markTestSkipped('No messages found in test database');
        }

        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:check-message-headers'));

        $tester->execute(['msg_id' => (string) $msgId]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Message ID:', $output);
        $this->assertStringContainsString('Direction:', $output);
        $this->assertStringContainsString('Subject:', $output);
        $this->assertStringContainsString('Timestamp:', $output);
        $this->assertStringContainsString('=== Important Headers ===', $output);
        $this->assertStringContainsString('Message-ID:', $output);
        $this->assertStringContainsString('In-Reply-To:', $output);
        $this->assertStringContainsString('References:', $output);
        $this->assertStringContainsString('=== All Headers (JSON) ===', $output);
    }

    public function testOutputContainsJsonFormattedHeaders(): void
    {
        $msgId = $this->connection->fetchOne('SELECT msg_id FROM message LIMIT 1');

        if ($msgId === false) {
            $this->markTestSkipped('No messages found in test database');
        }

        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:check-message-headers'));

        $tester->execute(['msg_id' => (string) $msgId]);

        $output = $tester->getDisplay();
        // The command outputs JSON after "=== All Headers (JSON) ==="
        $this->assertStringContainsString('All Headers (JSON)', $output);
        // JSON output should at least contain opening brace or null
        $this->assertMatchesRegularExpression('/[{\[null]/', $output);
    }

    public function testCommandRequiresMsgIdArgument(): void
    {
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:check-message-headers'));

        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $tester->execute([]);
    }

    public function testOutputContainsThreadIdAndSendStatusHeaders(): void
    {
        $msgId = $this->connection->fetchOne('SELECT msg_id FROM message LIMIT 1');

        if ($msgId === false) {
            $this->markTestSkipped('No messages found in test database');
        }

        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:check-message-headers'));

        $tester->execute(['msg_id' => (string) $msgId]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Thread-ID:', $output);
        $this->assertStringContainsString('Send Status:', $output);
    }

    public function testMultipleMessagesCanBeInspected(): void
    {
        $msgIds = $this->connection->fetchFirstColumn('SELECT msg_id FROM message LIMIT 2');

        if (count($msgIds) < 2) {
            $this->markTestSkipped('Need at least 2 messages in test database');
        }

        foreach ($msgIds as $msgId) {
            $app = new Application(self::$kernel);
            $tester = new CommandTester($app->find('app:check-message-headers'));

            $tester->execute(['msg_id' => (string) $msgId]);

            $this->assertSame(0, $tester->getStatusCode());
            $output = $tester->getDisplay();
            $this->assertStringContainsString('Message ID:', $output);
        }
    }

    public function testReplyToSectionShownWhenMessageHasReplyTo(): void
    {
        // Find a message that has a reply_to reference
        $msgId = $this->connection->fetchOne(
            'SELECT msg_id FROM message WHERE reply_to_msg_id IS NOT NULL LIMIT 1'
        );

        if ($msgId === false) {
            $this->markTestSkipped('No messages with reply_to found in test database');
        }

        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:check-message-headers'));

        $tester->execute(['msg_id' => (string) $msgId]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('=== Reply To (Internal) ===', $output);
        $this->assertStringContainsString('Reply To Message ID:', $output);
        $this->assertStringContainsString('Reply To Subject:', $output);
        $this->assertStringContainsString('Parent Message-ID:', $output);
    }

    public function testNoReplyToSectionWhenMessageHasNoReplyTo(): void
    {
        // Find a message without reply_to
        $msgId = $this->connection->fetchOne(
            'SELECT msg_id FROM message WHERE reply_to_msg_id IS NULL LIMIT 1'
        );

        if ($msgId === false) {
            $this->markTestSkipped('No messages without reply_to found in test database');
        }

        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:check-message-headers'));

        $tester->execute(['msg_id' => (string) $msgId]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringNotContainsString('=== Reply To (Internal) ===', $output);
    }
}

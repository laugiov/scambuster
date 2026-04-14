<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\ConversationHandler;
use App\Application\Communication\MessageHandler;
use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ScamType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for MessageHandler.
 *
 * Uses real DB + fixtures.
 */
class MessageHandlerTest extends KernelTestCase
{
    private MessageHandler $messageHandler;
    private ConversationHandler $conversationHandler;
    private \Doctrine\ORM\EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->messageHandler = $container->get(MessageHandler::class);
        $this->conversationHandler = $container->get(ConversationHandler::class);
        $this->em = $container->get('doctrine')->getManager();
    }

    private function getChannel(): Channel
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $this->assertNotNull($channel);

        return $channel;
    }

    private function createOpenConversation(): Conversation
    {
        $channel = $this->getChannel();
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);

        return $this->conversationHandler->createConversation(
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            50,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-msghandler-' . bin2hex(random_bytes(4))
        );
    }

    // ------------------------------------------------------------------ //
    //  createMessage
    // ------------------------------------------------------------------ //

    public function testCreateMessagePersistsAndReturns(): void
    {
        $conv = $this->createOpenConversation();
        $channel = $this->getChannel();

        $data = [
            'conv_id' => $conv->getConvId(),
            'channel_id' => $channel->getChannelId(),
            'direction' => 'in',
            'body_text' => 'Test message body',
            'body_html' => '<p>Test message body</p>',
            'subject' => 'Test subject',
            'headers' => ['from' => 'scammer@test.com', 'message-id' => '<msg-' . bin2hex(random_bytes(4)) . '@test.com>'],
            'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        $message = $this->messageHandler->createMessage($data);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('Test message body', $message->getBodyText());
        $this->assertSame('Test subject', $message->getSubject());
    }

    public function testCreateMessageReturnsNullForInvalidConversation(): void
    {
        $channel = $this->getChannel();
        $data = [
            'conv_id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
            'channel_id' => $channel->getChannelId(),
            'direction' => 'in',
            'body_text' => 'test',
            'headers' => [],
            'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        $result = $this->messageHandler->createMessage($data);
        $this->assertNull($result);
    }

    public function testCreateMessageThrowsForClosedConversation(): void
    {
        $channel = $this->getChannel();
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);

        $conv = $this->conversationHandler->createConversation(
            $channel,
            $scamType,
            $account,
            ConversationStatus::CLOSED,
            50,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-closed-' . bin2hex(random_bytes(4))
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot add message to closed conversation');

        $this->messageHandler->createMessage([
            'conv_id' => $conv->getConvId(),
            'channel_id' => $channel->getChannelId(),
            'direction' => 'in',
            'body_text' => 'test',
            'headers' => [],
            'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    // ------------------------------------------------------------------ //
    //  getMessage
    // ------------------------------------------------------------------ //

    public function testGetMessageReturnsExistingMessage(): void
    {
        $conv = $this->createOpenConversation();
        $channel = $this->getChannel();

        $created = $this->messageHandler->createMessage([
            'conv_id' => $conv->getConvId(),
            'channel_id' => $channel->getChannelId(),
            'direction' => 'in',
            'body_text' => 'Fetch me',
            'headers' => ['from' => 'test@test.com'],
            'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        $fetched = $this->messageHandler->getMessage($created->getMsgId());
        $this->assertNotNull($fetched);
        $this->assertSame($created->getMsgId(), $fetched->getMsgId());
    }

    public function testGetMessageReturnsNullForUnknownId(): void
    {
        $result = $this->messageHandler->getMessage('ffffffff-ffff-ffff-ffff-ffffffffffff');
        $this->assertNull($result);
    }

    // ------------------------------------------------------------------ //
    //  deleteMessage
    // ------------------------------------------------------------------ //

    public function testDeleteMessageRemovesFromDb(): void
    {
        $conv = $this->createOpenConversation();
        $channel = $this->getChannel();

        $created = $this->messageHandler->createMessage([
            'conv_id' => $conv->getConvId(),
            'channel_id' => $channel->getChannelId(),
            'direction' => 'in',
            'body_text' => 'Delete me',
            'headers' => ['from' => 'test@test.com'],
            'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        $deleted = $this->messageHandler->deleteMessage($created->getMsgId());
        $this->assertTrue($deleted);

        $fetched = $this->messageHandler->getMessage($created->getMsgId());
        $this->assertNull($fetched);
    }

    public function testDeleteMessageReturnsFalseForUnknownId(): void
    {
        $result = $this->messageHandler->deleteMessage('ffffffff-ffff-ffff-ffff-ffffffffffff');
        $this->assertFalse($result);
    }

    // ------------------------------------------------------------------ //
    //  patchMessage
    // ------------------------------------------------------------------ //

    public function testPatchMessageUpdatesBodyText(): void
    {
        $conv = $this->createOpenConversation();
        $channel = $this->getChannel();

        $created = $this->messageHandler->createMessage([
            'conv_id' => $conv->getConvId(),
            'channel_id' => $channel->getChannelId(),
            'direction' => 'in',
            'body_text' => 'Original body',
            'headers' => ['from' => 'test@test.com'],
            'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        $patched = $this->messageHandler->patchMessage($created->getMsgId(), [
            'body_text' => 'Updated body',
        ]);

        $this->assertInstanceOf(Message::class, $patched);
        $this->assertSame('Updated body', $patched->getBodyText());
    }

    public function testPatchMessageUpdatesSubject(): void
    {
        $conv = $this->createOpenConversation();
        $channel = $this->getChannel();

        $created = $this->messageHandler->createMessage([
            'conv_id' => $conv->getConvId(),
            'channel_id' => $channel->getChannelId(),
            'direction' => 'in',
            'body_text' => 'test',
            'subject' => 'Old subject',
            'headers' => ['from' => 'test@test.com'],
            'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        $patched = $this->messageHandler->patchMessage($created->getMsgId(), [
            'subject' => 'New subject',
        ]);

        $this->assertInstanceOf(Message::class, $patched);
        $this->assertSame('New subject', $patched->getSubject());
    }

    public function testPatchMessageReturnsFalseWhenNoUpdates(): void
    {
        $conv = $this->createOpenConversation();
        $channel = $this->getChannel();

        $created = $this->messageHandler->createMessage([
            'conv_id' => $conv->getConvId(),
            'channel_id' => $channel->getChannelId(),
            'direction' => 'in',
            'body_text' => 'test',
            'headers' => ['from' => 'test@test.com'],
            'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        // Empty data array — no fields to update
        $result = $this->messageHandler->patchMessage($created->getMsgId(), []);
        $this->assertFalse($result);
    }

    public function testPatchMessageReturnsNullForUnknownId(): void
    {
        $result = $this->messageHandler->patchMessage('ffffffff-ffff-ffff-ffff-ffffffffffff', ['body_text' => 'x']);
        $this->assertNull($result);
    }

    public function testPatchMessageUpdatesHeaders(): void
    {
        $conv = $this->createOpenConversation();
        $channel = $this->getChannel();

        $created = $this->messageHandler->createMessage([
            'conv_id' => $conv->getConvId(),
            'channel_id' => $channel->getChannelId(),
            'direction' => 'in',
            'body_text' => 'test',
            'headers' => ['from' => 'old@test.com'],
            'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        $patched = $this->messageHandler->patchMessage($created->getMsgId(), [
            'headers' => ['from' => 'new@test.com', 'x-custom' => 'value'],
        ]);

        $this->assertInstanceOf(Message::class, $patched);
        $this->assertSame('new@test.com', $patched->getHeaders()['from']);
    }

    // ------------------------------------------------------------------ //
    //  getMessageAttachments / getMessageIocs
    // ------------------------------------------------------------------ //

    public function testGetMessageAttachmentsReturnsEmptyForMessageWithoutAttachments(): void
    {
        $conv = $this->createOpenConversation();
        $channel = $this->getChannel();

        $created = $this->messageHandler->createMessage([
            'conv_id' => $conv->getConvId(),
            'channel_id' => $channel->getChannelId(),
            'direction' => 'in',
            'body_text' => 'no attachments',
            'headers' => ['from' => 'test@test.com'],
            'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        $attachments = $this->messageHandler->getMessageAttachments($created->getMsgId());
        $this->assertIsArray($attachments);
        $this->assertEmpty($attachments);
    }

    public function testGetMessageAttachmentsReturnsEmptyForUnknownMessage(): void
    {
        $attachments = $this->messageHandler->getMessageAttachments('ffffffff-ffff-ffff-ffff-ffffffffffff');
        $this->assertIsArray($attachments);
        $this->assertEmpty($attachments);
    }

    public function testGetMessageIocsReturnsEmptyForUnknownMessage(): void
    {
        $iocs = $this->messageHandler->getMessageIocs('ffffffff-ffff-ffff-ffff-ffffffffffff');
        $this->assertIsArray($iocs);
        $this->assertEmpty($iocs);
    }
}

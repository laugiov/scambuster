<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\ThreadResolverService;
use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for ThreadResolverService.
 *
 * Tests deduplication, thread resolution, conversation creation, and reopening
 * with real database interactions using fixture data.
 */
class ThreadResolverServiceTest extends KernelTestCase
{
    private ThreadResolverService $service;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->service = $container->get(ThreadResolverService::class);
        $this->em = $container->get('doctrine')->getManager();
    }

    // ── findExistingMessage ──

    public function testFindExistingMessageReturnsNullForNullInput(): void
    {
        $result = $this->service->findExistingMessage(null);
        $this->assertNull($result);
    }

    public function testFindExistingMessageReturnsNullForNonExistentId(): void
    {
        $result = $this->service->findExistingMessage('<nonexistent-99999@nowhere.test>');
        $this->assertNull($result);
    }

    public function testFindExistingMessageFindsMessageByHeaderMessageId(): void
    {
        // Create a message with a known message-id header
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        $conv = new Conversation(
            uuid_create(UUID_TYPE_RANDOM),
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            10,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-thread-test-' . bin2hex(random_bytes(4))
        );
        $this->em->persist($conv);

        $knownMessageId = '<thread-test-' . bin2hex(random_bytes(8)) . '@test.com>';
        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $message = new Message(
            $msgId,
            $conv,
            $channel,
            $direction,
            'en',
            'Thread test',
            'Body text',
            null,
            ['message-id' => $knownMessageId],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null
        );
        $this->em->persist($message);
        $this->em->flush();

        $result = $this->service->findExistingMessage($knownMessageId);

        $this->assertNotNull($result);
        $this->assertSame($msgId, $result['msg_id']);
        $this->assertSame($conv->getConvId(), $result['conv_id']);
    }

    public function testFindExistingMessageMatchesWithoutChevrons(): void
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        $conv = new Conversation(
            uuid_create(UUID_TYPE_RANDOM),
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            10,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-chevron-test-' . bin2hex(random_bytes(4))
        );
        $this->em->persist($conv);

        $bareId = 'bare-test-' . bin2hex(random_bytes(8)) . '@test.com';
        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $message = new Message(
            $msgId,
            $conv,
            $channel,
            $direction,
            'en',
            'Chevron test',
            'Body',
            null,
            ['message-id' => '<' . $bareId . '>'],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null
        );
        $this->em->persist($message);
        $this->em->flush();

        // Search with bare ID (no chevrons) -- the service adds chevrons as fallback
        $result = $this->service->findExistingMessage($bareId);
        $this->assertNotNull($result);
        $this->assertSame($msgId, $result['msg_id']);
    }

    // ── resolveConversation ──

    public function testResolveConversationReturnsNullsWhenNoMatch(): void
    {
        $result = $this->service->resolveConversation(null, [], '<brand-new-' . bin2hex(random_bytes(8)) . '@test.com>');

        $this->assertNull($result['conversation']);
        $this->assertNull($result['replyToMessage']);
    }

    public function testResolveConversationFindsByInReplyTo(): void
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        $conv = new Conversation(
            uuid_create(UUID_TYPE_RANDOM),
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            10,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-inreplyto-test-' . bin2hex(random_bytes(4))
        );
        $this->em->persist($conv);

        $parentMessageId = '<parent-' . bin2hex(random_bytes(8)) . '@test.com>';
        $parentMsg = new Message(
            uuid_create(UUID_TYPE_RANDOM),
            $conv,
            $channel,
            $direction,
            'en',
            'Parent message',
            'Parent body',
            null,
            ['message-id' => $parentMessageId],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null
        );
        $this->em->persist($parentMsg);
        $this->em->flush();

        $result = $this->service->resolveConversation(
            $parentMessageId,
            [],
            '<child-' . bin2hex(random_bytes(8)) . '@test.com>'
        );

        $this->assertNotNull($result['conversation']);
        $this->assertSame($conv->getConvId(), $result['conversation']->getConvId());
        $this->assertNotNull($result['replyToMessage']);
        $this->assertSame($parentMsg->getMsgId(), $result['replyToMessage']->getMsgId());
    }

    public function testResolveConversationFindsByReferences(): void
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        $conv = new Conversation(
            uuid_create(UUID_TYPE_RANDOM),
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            10,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-refs-test-' . bin2hex(random_bytes(4))
        );
        $this->em->persist($conv);

        $refMessageId = '<ref-' . bin2hex(random_bytes(8)) . '@test.com>';
        $refMsg = new Message(
            uuid_create(UUID_TYPE_RANDOM),
            $conv,
            $channel,
            $direction,
            'en',
            'Referenced message',
            'Ref body',
            null,
            ['message-id' => $refMessageId],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null
        );
        $this->em->persist($refMsg);
        $this->em->flush();

        $result = $this->service->resolveConversation(
            null,
            [$refMessageId],
            '<new-msg-' . bin2hex(random_bytes(8)) . '@test.com>'
        );

        $this->assertNotNull($result['conversation']);
        $this->assertSame($conv->getConvId(), $result['conversation']->getConvId());
    }

    // ── createNewConversation ──

    public function testCreateNewConversationPersistsConversation(): void
    {
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);

        $this->assertNotNull($account);
        $this->assertNotNull($channel);

        $conversation = $this->service->createNewConversation(
            'scammer@evil.test',
            '<new-conv-' . bin2hex(random_bytes(8)) . '@test.com>',
            $account,
            $channel,
            42
        );

        $this->assertNotNull($conversation->getConvId());
        $this->assertSame(ConversationStatus::OPEN, $conversation->getStatus());
        $this->assertSame(42, $conversation->getScoreRisk());

        // Verify persisted in DB
        $this->em->clear();
        $found = $this->em->getRepository(Conversation::class)->find($conversation->getConvId());
        $this->assertNotNull($found);
        $this->assertSame(ConversationStatus::OPEN, $found->getStatus());
    }

    public function testCreateNewConversationUsesUnknownScamType(): void
    {
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);

        $conversation = $this->service->createNewConversation(
            'test@test.com',
            '<scam-type-test-' . bin2hex(random_bytes(8)) . '@test.com>',
            $account,
            $channel,
            10
        );

        $scamCode = strtoupper($conversation->getScamType()->getCode());
        $this->assertSame('UNKNOWN', $scamCode);
    }

    public function testCreateNewConversationExtractsSenderFromAngleBrackets(): void
    {
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);

        $conversation = $this->service->createNewConversation(
            'John Doe <john@evil.test>',
            '<angle-bracket-test-' . bin2hex(random_bytes(8)) . '@test.com>',
            $account,
            $channel,
            10
        );

        // The stixId should contain the extracted email, not the full from header
        $this->assertStringContainsString('john@evil.test', $conversation->getStixId());
    }

    // ── reopenIfNeeded ──

    public function testReopenIfNeededDoesNothingForOpenConversation(): void
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);

        $conv = new Conversation(
            uuid_create(UUID_TYPE_RANDOM),
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            10,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-reopen-noop-' . bin2hex(random_bytes(4))
        );
        $this->em->persist($conv);
        $this->em->flush();

        $this->service->reopenIfNeeded($conv);

        $this->assertSame(ConversationStatus::OPEN, $conv->getStatus());
    }

    public function testReopenIfNeededDoesNotReopenWhenPolicyDisallows(): void
    {
        // PHISHING has allow_reopen=false
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamTypePhishing = $this->em->getRepository(ScamType::class)->findOneBy(['code' => 'PHISHING']);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);

        if (!$scamTypePhishing) {
            $this->markTestSkipped('PHISHING scam type not found in fixtures');
        }

        $conv = new Conversation(
            uuid_create(UUID_TYPE_RANDOM),
            $channel,
            $scamTypePhishing,
            $account,
            ConversationStatus::CLOSED,
            70,
            new \DateTimeImmutable('-2 days'),
            new \DateTimeImmutable('-1 day'),
            'stix-no-reopen-' . bin2hex(random_bytes(4))
        );
        $this->em->persist($conv);
        $this->em->flush();

        $this->service->reopenIfNeeded($conv);

        $this->assertSame(ConversationStatus::CLOSED, $conv->getStatus());
    }

    public function testReopenIfNeededReopensWhenPolicyAllows(): void
    {
        // ROMANCE has allow_reopen=true, reopen_window_hours=72
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamTypeRomance = $this->em->getRepository(ScamType::class)->findOneBy(['code' => 'ROMANCE']);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);

        if (!$scamTypeRomance) {
            $this->markTestSkipped('ROMANCE scam type not found in fixtures');
        }

        $conv = new Conversation(
            uuid_create(UUID_TYPE_RANDOM),
            $channel,
            $scamTypeRomance,
            $account,
            ConversationStatus::CLOSED,
            70,
            new \DateTimeImmutable('-2 days'),
            new \DateTimeImmutable('-1 hour'),
            'stix-reopen-romance-' . bin2hex(random_bytes(4))
        );
        $this->em->persist($conv);
        $this->em->flush();

        $this->service->reopenIfNeeded($conv);

        $this->assertSame(ConversationStatus::OPEN, $conv->getStatus());
    }
}

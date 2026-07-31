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

    /**
     * Microsoft 365 internal Exchange senders carry an
     * X.400 distinguished name encoded as a fake email address (e.g.
     * IMCEAEX-_O=FIRST+20...@AUSP*.PROD.OUTLOOK.COM). Combined with the
     * full Outlook message-id (80 chars), the legacy concat-based stixId
     * exceeded varchar(255) and PostgreSQL rejected the INSERT with
     * SQLSTATE 22001, returning HTTP 500 to n8n.
     */
    public function testCreateNewConversationDoesNotOverflowOnOutlookX400Sender(): void
    {
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);

        $x400Dn = 'IMCEAEX-_O=FIRST+20ORGANIZATION_OU=EXCHANGE+20ADMINISTRATIVE+20GROUP+28FYDIBOHF23SPDLT+29_CN=RECIPIENTS_CN=00034001FCBB9658@AUSP282.PROD.OUTLOOK.COM';
        $longMsgId = '<SY6P282MB38451244344C09B280C82972C1112-' . bin2hex(random_bytes(8)) . '@SY6P282MB3845.AUSP282.PROD.OUTLOOK.COM>';

        $conversation = $this->service->createNewConversation(
            'Nikta Goyal <' . $x400Dn . '>',
            $longMsgId,
            $account,
            $channel,
            50
        );

        $this->assertNotNull($conversation->getConvId(), 'Conversation must be created (no SQLSTATE 22001 overflow)');
        $this->assertLessThanOrEqual(255, strlen($conversation->getStixId()), 'stixId must fit varchar(255)');
        $this->assertLessThanOrEqual(200, strlen($conversation->getStixId()), 'stixId must stay under 200 chars (safety margin)');

        // Email prefix must still be findable for forensic traceability
        $this->assertStringContainsString(substr($x400Dn, 0, 40), $conversation->getStixId(), 'Sender prefix must remain in stixId for grep-by-sender');
    }

    /**
     * message-ids vary by source: Outlook prefixes with
     * `<` and may include leading whitespace, Gmail strips them. Normalize
     * to a single canonical form (no chevrons, no whitespace) before
     * embedding in stixId.
     */
    public function testCreateNewConversationNormalizesMessageIdChevronsInStixId(): void
    {
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);

        $rawMsgId = '  <abc-' . bin2hex(random_bytes(8)) . '@example.test>  ';

        $conversation = $this->service->createNewConversation(
            'sender@example.test',
            $rawMsgId,
            $account,
            $channel,
            10
        );

        $stixId = $conversation->getStixId();
        $this->assertStringContainsString('abc-', $stixId, 'Normalized message-id core must remain in stixId');
        $this->assertStringNotContainsString('<', $stixId, 'Chevrons must be stripped before embedding');
        $this->assertStringNotContainsString('>', $stixId, 'Chevrons must be stripped before embedding');
        $this->assertStringNotContainsString('  ', $stixId, 'Leading/trailing whitespace must be stripped');
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
        // PHISHING now allows reopen; only LOTTERY / CHARITY
        // still deny it. Use LOTTERY here as the "no reopen" representative.
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamTypeLottery = $this->em->getRepository(ScamType::class)->findOneBy(['code' => 'LOTTERY']);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);

        if (!$scamTypeLottery) {
            $this->markTestSkipped('LOTTERY scam type not found in fixtures');
        }

        $conv = new Conversation(
            uuid_create(UUID_TYPE_RANDOM),
            $channel,
            $scamTypeLottery,
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

    public function testReopenIfNeededReopensPhishingWithin72h(): void
    {
        // PHISHING now allows reopen within 72h to recover
        // late scammer follow-ups (was losing 17 % per the 30-day audit).
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamTypePhishing = $this->em->getRepository(ScamType::class)->findOneBy(['code' => 'PHISHING']);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);

        if (!$scamTypePhishing) {
            $this->markTestSkipped('PHISHING scam type not found in fixtures');
        }

        $closedAt = new \DateTimeImmutable('-50 hours');  // within 72h window
        $conv = new Conversation(
            uuid_create(UUID_TYPE_RANDOM),
            $channel,
            $scamTypePhishing,
            $account,
            ConversationStatus::CLOSED,
            70,
            new \DateTimeImmutable('-3 days'),
            $closedAt,
            'stix-reopen-phishing-within-' . bin2hex(random_bytes(4)),
            new \DateTimeImmutable('-3 days'),
            $closedAt,  // updatedAt = closed_at (reopenIfNeeded reads this)
        );
        $this->em->persist($conv);
        $this->em->flush();

        $this->service->reopenIfNeeded($conv);

        $this->assertSame(ConversationStatus::OPEN, $conv->getStatus(), 'PHISHING conv closed 50h ago must reopen (within 72h window)');
    }

    public function testReopenIfNeededDoesNotReopenPhishingAfter72h(): void
    {
        // Guard against opening the reopen window too wide.
        // Past 72h, the PHISHING conv stays closed.
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamTypePhishing = $this->em->getRepository(ScamType::class)->findOneBy(['code' => 'PHISHING']);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);

        if (!$scamTypePhishing) {
            $this->markTestSkipped('PHISHING scam type not found in fixtures');
        }

        $closedAt = new \DateTimeImmutable('-80 hours');  // beyond 72h window
        $conv = new Conversation(
            uuid_create(UUID_TYPE_RANDOM),
            $channel,
            $scamTypePhishing,
            $account,
            ConversationStatus::CLOSED,
            70,
            new \DateTimeImmutable('-5 days'),
            $closedAt,
            'stix-no-reopen-phishing-after-' . bin2hex(random_bytes(4)),
            new \DateTimeImmutable('-5 days'),
            $closedAt,  // updatedAt = closed_at (reopenIfNeeded reads this)
        );
        $this->em->persist($conv);
        $this->em->flush();

        $this->service->reopenIfNeeded($conv);

        $this->assertSame(ConversationStatus::CLOSED, $conv->getStatus(), 'PHISHING conv closed 80h ago must stay closed (window is 72h)');
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

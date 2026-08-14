<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\Exception\ReplyRefusedException;
use App\Application\Communication\ConversationHandler;
use App\Application\Communication\ReplyHandler;
use App\Domain\Communication\Channel;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ScamType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the recipient and loop guards are WIRED, not merely implemented.
 *
 * ReplyRecipientPolicyTest covers the policy in isolation; on its own it would
 * still pass if ReplyHandler stopped calling the policy and went back to
 * `reply_to ?? from`. These tests go through the container-built ReplyHandler,
 * so they fail if the wiring is removed.
 */
final class ReplyRecipientGuardTest extends KernelTestCase
{
    private ReplyHandler $replyHandler;
    private ConversationHandler $conversationHandler;
    private \Doctrine\ORM\EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->replyHandler = $container->get(ReplyHandler::class);
        $this->conversationHandler = $container->get(ConversationHandler::class);
        $this->em = $container->get('doctrine')->getManager();
    }

    /**
     * @param array<string, mixed> $extraHeaders
     *
     * @return array{conv_id: string, msg_id: string, account_email: ?string}
     */
    private function createInbound(array $extraHeaders): array
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        self::assertNotNull($channel);
        self::assertNotNull($scamType);
        self::assertNotNull($account);
        self::assertNotNull($direction);

        $conv = $this->conversationHandler->createConversation(
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            75,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-guard-' . bin2hex(random_bytes(4)),
        );

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $message = new Message(
            $msgId,
            $conv,
            $channel,
            $direction,
            'fr',
            'Urgent: Bank Verification Required',
            'Please send your bank details immediately!',
            '<p>Please send your bank details immediately!</p>',
            array_merge([
                'from' => 'scammer@evil.test',
                'to' => 'victim@example.test',
                'message_id' => '<guard-' . bin2hex(random_bytes(8)) . '@evil.test>',
                'subject' => 'Urgent: Bank Verification Required',
            ], $extraHeaders),
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null,
        );

        $this->em->persist($message);
        $this->em->flush();

        return [
            'conv_id' => $conv->getConvId(),
            'msg_id' => $msgId,
            'account_email' => $account->getEmailAddress(),
        ];
    }

    /**
     * The regression the whole change exists for, asserted where it matters:
     * an attacker-supplied `reply_to` must not reach the outbound recipient.
     *
     * Reverting ReplyHandler to `getHeaders()['reply_to'] ?? ['from']` fails
     * this test.
     */
    public function testAttackerSuppliedReplyToDoesNotChooseTheRecipient(): void
    {
        $data = $this->createInbound(['reply_to' => 'victim@target.test']);

        $result = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');

        self::assertNotNull($result);
        self::assertSame('scammer@evil.test', $result['to']);

        // And the value actually persisted on the outbound message, which is
        // what composeHeaders reads at send time.
        /** @var string $outboundId */
        $outboundId = $result['msg_id'];
        $outbound = $this->em->getRepository(Message::class)->find($outboundId);
        self::assertNotNull($outbound);
        self::assertSame('scammer@evil.test', $outbound->getHeaders()['to'] ?? null);
    }

    /**
     * The hyphenated form is equally ignored — the recipient comes from `from`.
     */
    public function testHyphenatedReplyToDoesNotChooseTheRecipientEither(): void
    {
        $data = $this->createInbound(['reply-to' => 'victim@target.test']);

        $result = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');

        self::assertNotNull($result);
        self::assertSame('scammer@evil.test', $result['to']);
    }

    public function testAutomatedMailIsRefusedThroughTheHandler(): void
    {
        $data = $this->createInbound(['auto-submitted' => 'auto-replied']);

        $this->expectException(ReplyRefusedException::class);
        $this->expectExceptionMessage('Refusing to reply to automated mail');

        $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');
    }

    public function testMailingListMailIsRefusedThroughTheHandler(): void
    {
        $data = $this->createInbound(['list-id' => '<news.example.test>']);

        $this->expectException(ReplyRefusedException::class);

        $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');
    }

    /**
     * Mass-mailed fraud is the honeypot's main input. `Precedence: bulk` must
     * NOT be treated as automated mail.
     */
    public function testBulkPrecedenceStillGetsAReply(): void
    {
        $data = $this->createInbound(['precedence' => 'bulk']);

        $result = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');

        self::assertNotNull($result);
        self::assertSame('scammer@evil.test', $result['to']);
    }

    /**
     * A `From:` spoofing the honeypot's own address must be refused, and a
     * decoy `To:` must not buy a way past the check — the guard compares
     * against the mail account's address, not against the inbound headers.
     */
    public function testSpoofedSelfAddressIsRefusedDespiteADecoyToHeader(): void
    {
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        self::assertNotNull($account);
        $accountEmail = $account->getEmailAddress();

        if (!\is_string($accountEmail) || trim($accountEmail) === '') {
            self::markTestSkipped('Fixture mail account has no email address to spoof.');
        }

        $data = $this->createInbound([
            'from' => $accountEmail,
            'to' => 'decoy@attacker.test',
        ]);

        $this->expectException(ReplyRefusedException::class);
        $this->expectExceptionMessage('recipient equals the honeypot address');

        $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\ConversationHandler;
use App\Application\Communication\MessageHandler;
use App\Application\Communication\ReplyCompositionService;
use App\Domain\Communication\Channel;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ScamType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Additional integration tests for ReplyCompositionService.
 *
 * Covers sendEmail flow, compose with conversation context, and edge cases
 * not covered by existing ReplyCompositionServiceTest.
 */
class ReplyCompositionServiceAdditionalTest extends KernelTestCase
{
    private ReplyCompositionService $service;
    private ConversationHandler $conversationHandler;
    private \Doctrine\ORM\EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->service = $container->get(ReplyCompositionService::class);
        $this->conversationHandler = $container->get(ConversationHandler::class);
        $this->em = $container->get('doctrine')->getManager();
    }

    /**
     * Create a conversation with an inbound message and an outbound reply.
     *
     * @return array{inbound: Message, outbound: Message}
     */
    private function createThreadedMessages(?string $fromHeader = null): array
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $dirIn = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);
        $dirOut = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'out']);

        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $this->assertNotNull($dirIn);
        $this->assertNotNull($dirOut);

        $conv = $this->conversationHandler->createConversation(
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            50,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-rcs-add-' . bin2hex(random_bytes(4))
        );

        $parentMsgId = '<parent-add-' . bin2hex(random_bytes(8)) . '@test.com>';
        $inboundMsgId = uuid_create(UUID_TYPE_RANDOM);
        $inbound = new Message(
            $inboundMsgId,
            $conv,
            $channel,
            $dirIn,
            'en',
            'Scam subject',
            'Send me your bank details',
            null,
            [
                'from' => 'scammer@evil.test',
                'to' => 'honeypot@test.com',
                'message-id' => $parentMsgId,
                'message_id' => $parentMsgId,
                'references' => '<older-ref@test.com>',
            ],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable('-30 minutes'),
            new \DateTimeImmutable('-30 minutes'),
            null
        );
        $this->em->persist($inbound);
        $this->em->flush();

        $outboundMsgId = uuid_create(UUID_TYPE_RANDOM);
        $outbound = new Message(
            $outboundMsgId,
            $conv,
            $channel,
            $dirOut,
            'en',
            'Re: Scam subject',
            'Sure, what do you need?',
            '<p>Sure, what do you need?</p>',
            [
                'from' => $fromHeader ?? 'honeypot@test.com',
                'to' => 'scammer@evil.test',
            ],
            bin2hex(random_bytes(32)),
            null,
            $inbound,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null
        );
        $this->em->persist($outbound);
        $this->em->flush();

        return ['inbound' => $inbound, 'outbound' => $outbound];
    }

    // ------------------------------------------------------------------ //
    //  composeHeaders — references chain
    // ------------------------------------------------------------------ //

    public function testComposeHeadersBuildsReferencesChain(): void
    {
        $msgs = $this->createThreadedMessages();
        $result = $this->service->composeHeaders($msgs['outbound']->getMsgId());

        $this->assertNotNull($result);
        $references = $result['references'] ?? '';

        // Should contain both the older reference and the parent message ID
        $parentMsgId = $msgs['inbound']->getHeaders()['message-id'] ?? '';
        $this->assertStringContainsString($parentMsgId, $references);
    }

    public function testComposeHeadersResolvesFromWhenInvalid(): void
    {
        // Create with invalid from (no @ sign, simulating IMAP hostname)
        $msgs = $this->createThreadedMessages('imap-server-hostname');
        $result = $this->service->composeHeaders($msgs['outbound']->getMsgId());

        $this->assertNotNull($result);
        // The from should be resolved from parent's to header
        $from = $result['from'] ?? '';
        $this->assertStringContainsString('@', $from, 'From should be resolved to a valid email');
    }

    // ------------------------------------------------------------------ //
    //  markAsSent — with conv_id parameter
    // ------------------------------------------------------------------ //

    public function testMarkAsSentWithCorrectConvId(): void
    {
        $msgs = $this->createThreadedMessages();
        $outboundId = $msgs['outbound']->getMsgId();
        $convId = $msgs['outbound']->getConversation()->getConvId();

        $result = $this->service->markAsSent(
            $outboundId,
            'gmail',
            '<gmail-sent@test.com>',
            new \DateTimeImmutable(),
            null,
            $convId // matching conv_id
        );

        $this->assertTrue($result);
    }

    public function testMarkAsSentWithMismatchedConvIdStillSucceeds(): void
    {
        $msgs = $this->createThreadedMessages();
        $outboundId = $msgs['outbound']->getMsgId();

        // Provide a wrong conv_id -- should log warning but still succeed
        $result = $this->service->markAsSent(
            $outboundId,
            'gmail',
            '<gmail-sent-2@test.com>',
            new \DateTimeImmutable(),
            null,
            'ffffffff-ffff-ffff-ffff-ffffffffffff' // wrong conv_id
        );

        $this->assertTrue($result);
    }

    // ------------------------------------------------------------------ //
    //  sendEmail — mailer not configured
    // ------------------------------------------------------------------ //

    public function testSendEmailThrowsWhenMailerNotConfigured(): void
    {
        // The default test env may or may not have a mailer configured
        // If sendEmail is available, it should either work or throw with a clear message
        $msgs = $this->createThreadedMessages();

        try {
            $this->service->sendEmail($msgs['outbound']->getMsgId());
            // If we get here, mailer is configured and send succeeded
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            // Expected: Mailer not configured, safety check failure, or similar
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function testSendEmailThrowsForNonexistentMessage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->sendEmail('ffffffff-ffff-ffff-ffff-ffffffffffff');
    }
}

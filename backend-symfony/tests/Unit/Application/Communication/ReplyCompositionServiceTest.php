<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Audit\AuditLogger;
use App\Application\Communication\MessageHandler;
use App\Application\Communication\ReplyCadenceService;
use App\Application\Communication\ReplyCompositionService;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;

class ReplyCompositionServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private MessageHandler&MockObject $messageHandler;
    private ReplyCadenceService&MockObject $cadenceService;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->messageHandler = $this->createMock(MessageHandler::class);
        $this->cadenceService = $this->createMock(ReplyCadenceService::class);
    }

    private function createService(
        ?AuditLogger $auditLogger = null,
        ?MailerInterface $mailer = null,
    ): ReplyCompositionService {
        return new ReplyCompositionService(
            em: $this->em,
            messageHandler: $this->messageHandler,
            cadenceService: $this->cadenceService,
            logger: new NullLogger(),
            auditLogger: $auditLogger,
            mailer: $mailer,
        );
    }

    // --- composeHeaders tests ---

    public function test_composeHeaders_returns_null_when_message_not_found(): void
    {
        $this->messageHandler->method('getMessage')->willReturn(null);

        $service = $this->createService();
        $this->assertNull($service->composeHeaders('nonexistent'));
    }

    public function test_composeHeaders_throws_when_no_parent(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getReplyTo')->willReturn(null);

        $this->messageHandler->method('getMessage')->willReturn($message);

        $service = $this->createService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Message is not a reply');
        $service->composeHeaders('msg-1');
    }

    public function test_composeHeaders_throws_when_missing_to_or_from(): void
    {
        $parent = $this->createMock(Message::class);
        $parent->method('getHeaders')->willReturn(['message_id' => 'parent-id@example.com']);

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getConvId')->willReturn('conv-1');
        $conversation->method('getStatus')->willReturn(ConversationStatus::OPEN);

        $message = $this->createMock(Message::class);
        $message->method('getReplyTo')->willReturn($parent);
        $message->method('getHeaders')->willReturn([]); // No to/from
        $message->method('getConversation')->willReturn($conversation);

        $this->messageHandler->method('getMessage')->willReturn($message);

        $service = $this->createService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing to/from headers');
        $service->composeHeaders('msg-1');
    }

    public function test_composeHeaders_builds_proper_headers(): void
    {
        $parent = $this->createMock(Message::class);
        $parent->method('getHeaders')->willReturn([
            'message_id' => 'parent@example.com',
            'references' => 'ref1@example.com ref2@example.com',
            'in_reply_to' => 'earlier@example.com',
            'to' => 'honeypot@scambuster.local',
        ]);

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getConvId')->willReturn('conv-1');
        $conversation->method('getStatus')->willReturn(ConversationStatus::OPEN);

        $message = $this->createMock(Message::class);
        $message->method('getReplyTo')->willReturn($parent);
        $message->method('getHeaders')->willReturn([
            'to' => 'scammer@example.com',
            'from' => 'honeypot@scambuster.local',
        ]);
        $message->method('getConversation')->willReturn($conversation);
        $message->method('getSubject')->willReturn('Re: Test');

        $this->messageHandler->method('getMessage')->willReturn($message);

        $this->cadenceService->method('checkSafelist')->willReturn(true);
        $this->cadenceService->method('isKillSwitchActive')->willReturn(false);
        $this->cadenceService->method('checkCadence')->willReturn(true);

        $service = $this->createService();
        $result = $service->composeHeaders('msg-1');

        $this->assertNotNull($result);
        $this->assertSame('msg-1', $result['msg_id']);
        $this->assertSame('scammer@example.com', $result['to']);
        $this->assertSame('honeypot@scambuster.local', $result['from']);
        $this->assertSame('Re: Test', $result['subject']);
        $this->assertTrue($result['safe_to_send']);
        $this->assertFalse($result['rate_limited']);
        // References should contain parent refs + parent message_id
        $this->assertStringContainsString('parent@example.com', $result['references']);
    }

    public function test_composeHeaders_resolves_from_when_invalid(): void
    {
        $parent = $this->createMock(Message::class);
        $parent->method('getHeaders')->willReturn([
            'message_id' => 'parent@example.com',
            'to' => 'honeypot@scambuster.local',
        ]);

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getConvId')->willReturn('conv-1');
        $conversation->method('getStatus')->willReturn(ConversationStatus::OPEN);

        $message = $this->createMock(Message::class);
        $message->method('getReplyTo')->willReturn($parent);
        // From is an IMAP hostname, not email - should be resolved from parent's to
        $message->method('getHeaders')->willReturn([
            'to' => 'scammer@example.com',
            'from' => 'imap.gmail.com',
        ]);
        $message->method('getConversation')->willReturn($conversation);
        $message->method('getSubject')->willReturn('Re: Test');

        $this->messageHandler->method('getMessage')->willReturn($message);
        $this->cadenceService->method('checkSafelist')->willReturn(true);
        $this->cadenceService->method('isKillSwitchActive')->willReturn(false);
        $this->cadenceService->method('checkCadence')->willReturn(true);

        $service = $this->createService();
        $result = $service->composeHeaders('msg-1');

        $this->assertNotNull($result);
        $this->assertSame('honeypot@scambuster.local', $result['from']);
    }

    public function test_composeHeaders_falls_back_to_account_email_when_parent_to_missing(): void
    {
        // Regression: 2026-05-12 SMTP send failures (RFC 2822) — when the
        // outbound message has a corrupted `from` (IMAP hostname) AND the
        // parent inbound has no `to`/`delivered-to` header (mass-mailing,
        // alias delivery), the fallback must reach the MailAccount's own
        // emailAddress before crashing the Symfony Mailer.
        $parent = $this->createMock(Message::class);
        $parent->method('getHeaders')->willReturn([
            'message_id' => 'parent@example.com',
            // No 'to' or 'delivered-to' — this is the failure mode in prod.
        ]);

        $account = $this->createMock(\App\Domain\Communication\MailAccount::class);
        $account->method('getEmailAddress')->willReturn('honeypot@example.com');

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getConvId')->willReturn('conv-1');
        $conversation->method('getStatus')->willReturn(ConversationStatus::OPEN);
        $conversation->method('getAccount')->willReturn($account);

        $message = $this->createMock(Message::class);
        $message->method('getReplyTo')->willReturn($parent);
        $message->method('getHeaders')->willReturn([
            'to' => 'scammer@example.com',
            'from' => 'imap.gmail.com',
        ]);
        $message->method('getConversation')->willReturn($conversation);
        $message->method('getSubject')->willReturn('Re: Test');

        $this->messageHandler->method('getMessage')->willReturn($message);
        $this->cadenceService->method('checkSafelist')->willReturn(true);
        $this->cadenceService->method('isKillSwitchActive')->willReturn(false);
        $this->cadenceService->method('checkCadence')->willReturn(true);

        $service = $this->createService();
        $result = $service->composeHeaders('msg-1');

        $this->assertNotNull($result);
        $this->assertSame('honeypot@example.com', $result['from']);
    }

    public function test_composeHeaders_rate_limited_when_cadence_fails(): void
    {
        $parent = $this->createMock(Message::class);
        $parent->method('getHeaders')->willReturn(['message_id' => 'parent@example.com']);

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getConvId')->willReturn('conv-1');
        $conversation->method('getStatus')->willReturn(ConversationStatus::OPEN);

        $message = $this->createMock(Message::class);
        $message->method('getReplyTo')->willReturn($parent);
        $message->method('getHeaders')->willReturn([
            'to' => 'scammer@example.com',
            'from' => 'honeypot@test.com',
        ]);
        $message->method('getConversation')->willReturn($conversation);
        $message->method('getSubject')->willReturn('Re: Test');

        $this->messageHandler->method('getMessage')->willReturn($message);
        $this->cadenceService->method('checkSafelist')->willReturn(true);
        $this->cadenceService->method('isKillSwitchActive')->willReturn(false);
        $this->cadenceService->method('checkCadence')->willReturn(false); // Rate limited

        $service = $this->createService();
        $result = $service->composeHeaders('msg-1');

        $this->assertFalse($result['safe_to_send']);
        $this->assertTrue($result['rate_limited']);
    }

    // --- markAsSent tests ---

    public function test_markAsSent_returns_false_when_not_found(): void
    {
        $this->messageHandler->method('getMessage')->willReturn(null);

        $service = $this->createService();
        $this->assertFalse($service->markAsSent('msg-1', 'smtp', 'provider-id', new \DateTimeImmutable()));
    }

    // Spec 082 T03 — markAsSent idempotency on match, typed conflict on mismatch.

    public function test_markAsSent_returns_true_on_same_provider_msg_id_no_writes(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getSendStatus')->willReturn('sent');
        $message->method('getProviderMsgId')->willReturn('provider-id-X');
        // Strict: no setter must be invoked on the idempotent path.
        $message->expects($this->never())->method('setSendStatus');
        $message->expects($this->never())->method('setProviderMsgId');
        $message->expects($this->never())->method('setTsSent');

        $this->messageHandler->method('getMessage')->willReturn($message);

        $service = $this->createService();

        $result = $service->markAsSent('msg-1', 'smtp', 'provider-id-X', new \DateTimeImmutable());

        $this->assertTrue($result);
    }

    public function test_markAsSent_throws_typed_conflict_on_different_provider_msg_id(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getSendStatus')->willReturn('sent');
        $message->method('getProviderMsgId')->willReturn('stored-X');

        $this->messageHandler->method('getMessage')->willReturn($message);

        $service = $this->createService();

        try {
            $service->markAsSent('msg-1', 'smtp', 'requested-Y', new \DateTimeImmutable());
            $this->fail('Expected MarkAsSentConflictException');
        } catch (\App\Application\Communication\Exception\MarkAsSentConflictException $e) {
            $this->assertSame('msg-1', $e->getMsgId());
            $this->assertSame('stored-X', $e->getExpectedProviderMsgId());
            $this->assertSame('requested-Y', $e->getActualProviderMsgId());
        }
    }

    public function test_markAsSent_throws_typed_conflict_when_stored_id_is_null(): void
    {
        // Legacy data: a row marked 'sent' with no provider_msg_id stored
        // (pre-spec-050 messages). Any non-empty input is by definition a
        // mismatch — fail closed with the typed exception so the caller
        // can decide whether to ignore or remediate.
        $message = $this->createMock(Message::class);
        $message->method('getSendStatus')->willReturn('sent');
        $message->method('getProviderMsgId')->willReturn(null);

        $this->messageHandler->method('getMessage')->willReturn($message);

        $service = $this->createService();

        $this->expectException(\App\Application\Communication\Exception\MarkAsSentConflictException::class);
        $service->markAsSent('msg-1', 'smtp', 'requested-Y', new \DateTimeImmutable());
    }

    // --- sendEmail tests ---

    public function test_sendEmail_throws_when_mailer_not_configured(): void
    {
        $service = $this->createService(mailer: null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Mailer not configured');
        $service->sendEmail('msg-1');
    }

    public function test_sendEmail_throws_when_message_not_found(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $this->messageHandler->method('getMessage')->willReturn(null);

        $service = $this->createService(mailer: $mailer);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Message not found');
        $service->sendEmail('msg-1');
    }

    public function test_sendEmail_throws_when_direction_is_inbound(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $direction = $this->createMock(Direction::class);
        $direction->method('getCode')->willReturn('in');

        $message = $this->createMock(Message::class);
        $message->method('getDirection')->willReturn($direction);

        $this->messageHandler->method('getMessage')->willReturn($message);

        $service = $this->createService(mailer: $mailer);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot send a non-outbound message');
        $service->sendEmail('msg-1');
    }

    // ------------------------------------------------------------------ //
    //  Spec 081 — Verrou B: send-side idempotency
    // ------------------------------------------------------------------ //

    public function test_sendEmail_returns_idempotent_success_when_already_sent(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        // Strict expectation: mailer MUST NOT be invoked when message is already sent.
        $mailer->expects($this->never())->method('send');

        $direction = $this->createMock(Direction::class);
        $direction->method('getCode')->willReturn('out');

        $tsSent = new \DateTimeImmutable('2026-05-11T16:38:47+00:00');
        $message = $this->createMock(Message::class);
        $message->method('getDirection')->willReturn($direction);
        $message->method('getSendStatus')->willReturn('sent');
        $message->method('getProviderMsgId')->willReturn('db6c9b0fd42f1fb65e3fc5bd5cf2a8bc@scambuster.local');
        $message->method('getTsSent')->willReturn($tsSent);

        $this->messageHandler->method('getMessage')->willReturn($message);

        $service = $this->createService(mailer: $mailer);
        $result = $service->sendEmail('msg-already-sent');

        $this->assertTrue($result['success']);
        $this->assertSame('db6c9b0fd42f1fb65e3fc5bd5cf2a8bc@scambuster.local', $result['message_id']);
        $this->assertSame($tsSent->format(\DateTimeInterface::ATOM), $result['ts_sent']);
    }

    public function test_sendEmail_idempotent_path_tolerates_missing_provider_metadata(): void
    {
        // Defense-in-depth: legacy outbounds may have send_status=sent but null provider_msg_id.
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $direction = $this->createMock(Direction::class);
        $direction->method('getCode')->willReturn('out');

        $message = $this->createMock(Message::class);
        $message->method('getDirection')->willReturn($direction);
        $message->method('getSendStatus')->willReturn('sent');
        $message->method('getProviderMsgId')->willReturn(null);
        $message->method('getTsSent')->willReturn(null);

        $this->messageHandler->method('getMessage')->willReturn($message);

        $service = $this->createService(mailer: $mailer);
        $result = $service->sendEmail('msg-legacy-sent');

        // Idempotent path must not crash on missing metadata.
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('message_id', $result);
        $this->assertArrayHasKey('ts_sent', $result);
    }

    public function test_sendEmail_does_not_skip_when_send_status_is_draft(): void
    {
        // Sanity: a draft message must NOT be diverted into the idempotent path.
        // We can't assert SMTP send fully here (composeHeaders has DB needs); we check
        // the early-return short-circuit does not fire by ensuring the service progresses
        // past the status check and fails on a later, well-known precondition.
        $mailer = $this->createMock(MailerInterface::class);

        $direction = $this->createMock(Direction::class);
        $direction->method('getCode')->willReturn('out');

        $message = $this->createMock(Message::class);
        $message->method('getDirection')->willReturn($direction);
        $message->method('getSendStatus')->willReturn('draft');
        // No replyTo → composeHeaders throws "not a reply" downstream → proves we did NOT short-circuit
        $message->method('getReplyTo')->willReturn(null);

        $this->messageHandler->method('getMessage')->willReturn($message);

        $service = $this->createService(mailer: $mailer);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Message is not a reply');
        $service->sendEmail('msg-draft');
    }
}

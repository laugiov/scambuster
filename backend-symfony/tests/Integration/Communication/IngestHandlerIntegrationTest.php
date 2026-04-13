<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\IngestHandler;
use App\Application\Communication\IngestRawRequestDto;
use App\Domain\Communication\Attachment;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for IngestHandler.
 *
 * Tests the full ingest pipeline (parsing, threading, message creation, attachments)
 * with real database interactions using fixture data.
 */
class IngestHandlerIntegrationTest extends KernelTestCase
{
    private IngestHandler $handler;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(IngestHandler::class);
        $this->em = $container->get('doctrine')->getManager();
    }

    private function buildRfc822Email(
        string $from = 'scammer@evil.test',
        string $to = 'honeypot@scambuster.test',
        string $subject = 'Integration Test Email',
        string $body = 'Hello from integration test!',
        ?string $messageId = null,
        ?string $inReplyTo = null,
        ?string $references = null,
    ): string {
        $messageId ??= '<integ-' . bin2hex(random_bytes(8)) . '@evil.test>';
        $date = (new \DateTimeImmutable())->format('r');

        $headers = "From: {$from}\r\n";
        $headers .= "To: {$to}\r\n";
        $headers .= "Subject: {$subject}\r\n";
        $headers .= "Date: {$date}\r\n";
        $headers .= "Message-ID: {$messageId}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";

        if ($inReplyTo !== null) {
            $headers .= "In-Reply-To: {$inReplyTo}\r\n";
        }
        if ($references !== null) {
            $headers .= "References: {$references}\r\n";
        }

        return $headers . "\r\n" . $body;
    }

    private function buildDto(string $rawEmail, ?string $accountId = null): IngestRawRequestDto
    {
        $dto = new IngestRawRequestDto();
        // Use the active fixture account by default
        $dto->account_id = $accountId ?? '12b3f7b4-8fb1-4830-82d5-58d7fd874d2a';
        $dto->raw_source = base64_encode($rawEmail);
        $dto->ts_received = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $dto->channel = 'email';
        $dto->rspamd = ['score' => 7.5, 'symbols' => ['TEST']];
        $dto->score_risk = 50.0;

        return $dto;
    }

    // ── Happy path ──

    public function testIngestHappyPathCreatesMessageAndConversation(): void
    {
        $messageId = '<happy-' . bin2hex(random_bytes(8)) . '@evil.test>';
        $raw = $this->buildRfc822Email(messageId: $messageId);
        $dto = $this->buildDto($raw);

        $result = $this->handler->ingest($dto);

        $this->assertArrayHasKey('msg_id', $result);
        $this->assertArrayHasKey('conv_id', $result);
        $this->assertSame('ingested', $result['status']);

        // Verify message persisted
        $message = $this->em->getRepository(Message::class)->find($result['msg_id']);
        $this->assertNotNull($message);
        $this->assertSame('Integration Test Email', $message->getSubject());
        $this->assertStringContainsString('Hello from integration test!', $message->getBodyText());

        // Verify headers stored
        $headers = $message->getHeaders();
        $this->assertSame('scammer@evil.test', $headers['from']);
        $this->assertSame('honeypot@scambuster.test', $headers['to']);
    }

    public function testIngestCreatesConversationWithCorrectStatus(): void
    {
        $raw = $this->buildRfc822Email();
        $dto = $this->buildDto($raw);

        $result = $this->handler->ingest($dto);

        $conv = $this->em->getRepository(\App\Domain\Communication\Conversation::class)->find($result['conv_id']);
        $this->assertNotNull($conv);
        $this->assertSame(\App\Domain\Communication\ConversationStatus::OPEN, $conv->getStatus());
    }

    // ── Deduplication ──

    public function testIngestDuplicateMessageIdReturnsAlreadyExists(): void
    {
        $messageId = '<dedup-' . bin2hex(random_bytes(8)) . '@evil.test>';
        $raw = $this->buildRfc822Email(messageId: $messageId);

        // First ingest
        $dto1 = $this->buildDto($raw);
        $result1 = $this->handler->ingest($dto1);
        $this->assertSame('ingested', $result1['status']);

        // Second ingest with same Message-ID
        $dto2 = $this->buildDto($raw);
        $result2 = $this->handler->ingest($dto2);
        $this->assertSame('already_exists', $result2['status']);
        $this->assertSame($result1['msg_id'], $result2['msg_id']);
        $this->assertSame($result1['conv_id'], $result2['conv_id']);
    }

    // ── Invalid account ──

    public function testIngestWithNonExistentAccountThrowsRuntimeException(): void
    {
        $raw = $this->buildRfc822Email();
        $dto = $this->buildDto($raw, 'ffffffff-ffff-ffff-ffff-ffffffffffff');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown account_id');

        $this->handler->ingest($dto);
    }

    // ── Threading ──

    public function testIngestThreadsReplyToExistingConversation(): void
    {
        // First message: creates a new conversation
        $parentMsgId = '<parent-thread-' . bin2hex(random_bytes(8)) . '@evil.test>';
        $raw1 = $this->buildRfc822Email(
            subject: 'Original scam email',
            body: 'I am a Nigerian prince...',
            messageId: $parentMsgId,
        );
        $dto1 = $this->buildDto($raw1);
        $result1 = $this->handler->ingest($dto1);
        $this->assertSame('ingested', $result1['status']);

        // Second message: replies to the first (same conversation)
        $childMsgId = '<child-thread-' . bin2hex(random_bytes(8)) . '@evil.test>';
        $raw2 = $this->buildRfc822Email(
            subject: 'Re: Original scam email',
            body: 'Please send me your bank details...',
            messageId: $childMsgId,
            inReplyTo: $parentMsgId,
            references: $parentMsgId,
        );
        $dto2 = $this->buildDto($raw2);
        $result2 = $this->handler->ingest($dto2);
        $this->assertSame('ingested', $result2['status']);

        // Both messages should be in the same conversation
        $this->assertSame($result1['conv_id'], $result2['conv_id']);
    }

    // ── Attachments ──

    public function testIngestWithAttachmentsCreatesAttachmentEntities(): void
    {
        $msgId = '<attach-' . bin2hex(random_bytes(8)) . '@evil.test>';
        $raw = $this->buildRfc822Email(
            subject: 'Email with attachments',
            body: 'Please see attached invoice',
            messageId: $msgId,
        );
        $dto = $this->buildDto($raw);
        $dto->attachments = [
            [
                'filename' => 'invoice.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 12345,
                'sha256' => hash('sha256', 'test-pdf-content-' . bin2hex(random_bytes(8))),
            ],
            [
                'filename' => 'photo.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 54321,
                'sha256' => hash('sha256', 'test-jpg-content-' . bin2hex(random_bytes(8))),
            ],
        ];

        $result = $this->handler->ingest($dto);
        $this->assertSame('ingested', $result['status']);

        // Verify attachments persisted
        $message = $this->em->getRepository(Message::class)->find($result['msg_id']);
        $this->assertNotNull($message);

        $attachments = $this->em->getRepository(Attachment::class)->findBy(['message' => $message]);
        $this->assertCount(2, $attachments);

        $filenames = array_map(fn (Attachment $a) => $a->getFilename(), $attachments);
        $this->assertContains('invoice.pdf', $filenames);
        $this->assertContains('photo.jpg', $filenames);
    }

    public function testIngestDeduplicatesAttachmentsByContentHash(): void
    {
        $msgId = '<dedup-attach-' . bin2hex(random_bytes(8)) . '@evil.test>';
        $raw = $this->buildRfc822Email(messageId: $msgId);
        $dto = $this->buildDto($raw);

        $sameHash = hash('sha256', 'duplicate-content-' . bin2hex(random_bytes(8)));
        $dto->attachments = [
            [
                'filename' => 'file1.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 1000,
                'sha256' => $sameHash,
            ],
            [
                'filename' => 'file2.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 1000,
                'sha256' => $sameHash, // same hash = duplicate
            ],
        ];

        $result = $this->handler->ingest($dto);
        $this->assertSame('ingested', $result['status']);

        // Only 1 attachment should be persisted (dedup by content_hash)
        $message = $this->em->getRepository(Message::class)->find($result['msg_id']);
        $attachments = $this->em->getRepository(Attachment::class)->findBy(['message' => $message]);
        $this->assertCount(1, $attachments);
    }

    // ── Invalid raw source ──

    public function testIngestWithNullRawSourceThrowsRuntimeException(): void
    {
        $dto = new IngestRawRequestDto();
        $dto->account_id = '12b3f7b4-8fb1-4830-82d5-58d7fd874d2a';
        $dto->raw_source = null;
        $dto->raw_source_rfc822_b64 = null;
        $dto->ts_received = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $dto->channel = 'email';
        $dto->rspamd = ['score' => 1.0];
        $dto->score_risk = 10.0;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid raw_source');

        $this->handler->ingest($dto);
    }

    // ── Uses inactive account (fixture '11111111-1111-1111-1111-111111111111' is inactive) ──

    public function testIngestWithInactiveAccountStillWorks(): void
    {
        // The inactive fixture account should still be found by ID
        $raw = $this->buildRfc822Email(messageId: '<inactive-' . bin2hex(random_bytes(8)) . '@evil.test>');
        $dto = $this->buildDto($raw, '11111111-1111-1111-1111-111111111111');

        // IngestHandler does not check is_active — just that the account exists
        $result = $this->handler->ingest($dto);
        $this->assertSame('ingested', $result['status']);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation;

use App\Application\Communication\ReplyHandler;
use App\Application\Evaluation\CorpusGenerator;
use App\Application\LLM\LanguageDetector;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CorpusGeneratorTest extends TestCase
{
    public function test_dry_run_generates_entries_without_llm(): void
    {
        $rows = [
            ['conv_id' => 'c1', 'status' => 'open', 'scam_type_code' => 'PHISHING', 'persona_code' => 'elderly_person', 'last_msg_id' => 'm1', 'last_inbound_text' => 'Hello victim', 'message_count' => '3'],
            ['conv_id' => 'c2', 'status' => 'open', 'scam_type_code' => 'ROMANCE', 'persona_code' => 'lonely_divorcee', 'last_msg_id' => 'm2', 'last_inbound_text' => 'My darling', 'message_count' => '5'],
        ];

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $replyHandler = $this->createMock(ReplyHandler::class);
        $replyHandler->expects($this->never())->method('generateReply');

        $generator = new CorpusGenerator($em, $replyHandler, new LanguageDetector(), new NullLogger());
        $result = $generator->generate(count: 2, dryRun: true, sleep: 0);

        $this->assertCount(2, $result['entries']);
        $this->assertTrue($result['summary']['dry_run']);
        $this->assertSame(2, $result['summary']['total']);

        $entry = $result['entries'][0];
        $this->assertSame('c1', $entry['conv_id']);
        $this->assertSame('PHISHING', $entry['scam_type']);
        $this->assertSame('elderly_person', $entry['persona_code']);
        $this->assertSame(3, $entry['message_count']);
        $this->assertSame('[DRY RUN — no LLM call]', $entry['text']);
        $this->assertSame(0, $entry['attempts']);
    }

    public function test_real_generation_calls_reply_handler(): void
    {
        $rows = [
            ['conv_id' => 'c1', 'status' => 'open', 'scam_type_code' => 'PHISHING', 'persona_code' => 'elderly_person', 'last_msg_id' => 'm1', 'last_inbound_text' => 'Hello', 'message_count' => '2'],
        ];

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $replyHandler = $this->createMock(ReplyHandler::class);
        $replyHandler->method('getConversationContext')->willReturn([
            'conv_id' => 'c1',
            'detected_language' => 'en',
        ]);
        $replyHandler->method('generateReply')->willReturn([
            'msg_id' => 'out1',
            'conv_id' => 'c1',
            'draft' => ['text' => 'Oh dear, I received your message about the bank.'],
            'meta' => [
                'persona' => 'elderly_person',
                'attempts' => 1,
                'fallback_used' => false,
                'naturalness' => 4,
                'persona_fit' => 3,
                'ti_value' => 3,
                'security_pass' => true,
                'policy_flags' => [],
                'cost_estimate' => 0.003,
            ],
        ]);

        $generator = new CorpusGenerator($em, $replyHandler, new LanguageDetector(), new NullLogger());
        $result = $generator->generate(count: 1, dryRun: false, sleep: 0);

        $this->assertCount(1, $result['entries']);
        $this->assertFalse($result['summary']['dry_run']);

        $entry = $result['entries'][0];
        $this->assertStringContainsString('bank', $entry['text']);
        $this->assertSame(1, $entry['attempts']);
        $this->assertSame(4, $entry['naturalness']);
        $this->assertGreaterThan(0, $entry['word_count']);
    }

    public function test_empty_conversations_returns_empty(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $replyHandler = $this->createMock(ReplyHandler::class);
        $generator = new CorpusGenerator($em, $replyHandler, new LanguageDetector(), new NullLogger());
        $result = $generator->generate(count: 10, dryRun: true, sleep: 0);

        $this->assertEmpty($result['entries']);
        $this->assertSame(0, $result['summary']['total']);
    }

    public function test_null_context_skipped(): void
    {
        $rows = [
            ['conv_id' => 'c1', 'status' => 'open', 'scam_type_code' => 'PHISHING', 'persona_code' => 'p1', 'last_msg_id' => 'm1', 'last_inbound_text' => 'Hey', 'message_count' => '1'],
        ];

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $replyHandler = $this->createMock(ReplyHandler::class);
        $replyHandler->method('getConversationContext')->willReturn(null);

        $generator = new CorpusGenerator($em, $replyHandler, new LanguageDetector(), new NullLogger());
        $result = $generator->generate(count: 1, dryRun: false, sleep: 0);

        $this->assertEmpty($result['entries']);
    }

    public function test_null_reply_skipped(): void
    {
        $rows = [
            ['conv_id' => 'c1', 'status' => 'open', 'scam_type_code' => 'PHISHING', 'persona_code' => 'p1', 'last_msg_id' => 'm1', 'last_inbound_text' => 'Hey', 'message_count' => '1'],
        ];

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $replyHandler = $this->createMock(ReplyHandler::class);
        $replyHandler->method('getConversationContext')->willReturn(['conv_id' => 'c1', 'detected_language' => 'en']);
        $replyHandler->method('generateReply')->willReturn(null);

        $generator = new CorpusGenerator($em, $replyHandler, new LanguageDetector(), new NullLogger());
        $result = $generator->generate(count: 1, dryRun: false, sleep: 0);

        $this->assertEmpty($result['entries']);
    }

    public function test_exception_during_generation_logged_and_skipped(): void
    {
        $rows = [
            ['conv_id' => 'c1', 'status' => 'open', 'scam_type_code' => 'PHISHING', 'persona_code' => 'p1', 'last_msg_id' => 'm1', 'last_inbound_text' => 'Hey', 'message_count' => '1'],
        ];

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $replyHandler = $this->createMock(ReplyHandler::class);
        $replyHandler->method('getConversationContext')->willThrowException(new \RuntimeException('LLM down'));

        $generator = new CorpusGenerator($em, $replyHandler, new LanguageDetector(), new NullLogger());
        $result = $generator->generate(count: 1, dryRun: false, sleep: 0);

        $this->assertEmpty($result['entries']);
    }

    public function test_no_last_msg_id_skipped(): void
    {
        $rows = [
            ['conv_id' => 'c1', 'status' => 'open', 'scam_type_code' => 'PHISHING', 'persona_code' => 'p1', 'last_msg_id' => null, 'last_inbound_text' => null, 'message_count' => '0'],
        ];

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $replyHandler = $this->createMock(ReplyHandler::class);
        $generator = new CorpusGenerator($em, $replyHandler, new LanguageDetector(), new NullLogger());
        $result = $generator->generate(count: 1, dryRun: false, sleep: 0);

        $this->assertEmpty($result['entries']);
    }

    public function test_summary_aggregates_correctly(): void
    {
        $rows = [
            ['conv_id' => 'c1', 'status' => 'open', 'scam_type_code' => 'PHISHING', 'persona_code' => 'p1', 'last_msg_id' => 'm1', 'last_inbound_text' => 'English text here about banking', 'message_count' => '2'],
            ['conv_id' => 'c2', 'status' => 'open', 'scam_type_code' => 'ROMANCE', 'persona_code' => 'p2', 'last_msg_id' => 'm2', 'last_inbound_text' => 'Bonjour mon amour', 'message_count' => '3'],
            ['conv_id' => 'c3', 'status' => 'open', 'scam_type_code' => 'PHISHING', 'persona_code' => 'p1', 'last_msg_id' => 'm3', 'last_inbound_text' => 'Please verify account', 'message_count' => '1'],
        ];

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $replyHandler = $this->createMock(ReplyHandler::class);
        $generator = new CorpusGenerator($em, $replyHandler, new LanguageDetector(), new NullLogger());
        $result = $generator->generate(count: 3, dryRun: true, sleep: 0);

        $summary = $result['summary'];
        $this->assertSame(3, $summary['total']);
        $this->assertCount(2, $summary['personas']);
        $this->assertCount(2, $summary['scam_types']);
        /** @var array<string, int> $personas */
        $personas = $summary['personas'];
        $this->assertSame(2, $personas['p1']);
        $this->assertSame(1, $personas['p2']);
    }

    public function test_progress_callback_called(): void
    {
        $rows = [
            ['conv_id' => 'c1', 'status' => 'open', 'scam_type_code' => 'PHISHING', 'persona_code' => 'p1', 'last_msg_id' => 'm1', 'last_inbound_text' => 'Hello', 'message_count' => '1'],
            ['conv_id' => 'c2', 'status' => 'open', 'scam_type_code' => 'ROMANCE', 'persona_code' => 'p2', 'last_msg_id' => 'm2', 'last_inbound_text' => 'Hi', 'message_count' => '2'],
        ];

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $replyHandler = $this->createMock(ReplyHandler::class);
        $generator = new CorpusGenerator($em, $replyHandler, new LanguageDetector(), new NullLogger());

        $calls = [];
        $result = $generator->generate(
            count: 2,
            dryRun: true,
            sleep: 0,
            onProgress: function (int $current, int $total) use (&$calls): void {
                $calls[] = [$current, $total];
            },
        );

        $this->assertCount(2, $calls);
        $this->assertSame([1, 2], $calls[0]);
        $this->assertSame([2, 2], $calls[1]);
    }
}

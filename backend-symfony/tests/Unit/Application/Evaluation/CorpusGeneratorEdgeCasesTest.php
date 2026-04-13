<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation;

use App\Application\Communication\ReplyHandler;
use App\Application\Evaluation\CorpusGenerator;
use App\Application\LLM\LanguageDetector;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for CorpusGenerator — limited to dry-run paths and summary logic.
 * ReplyOrchestrator and ReplyHandler are final, so non-dry-run paths
 * that need mocked orchestrator require integration tests.
 */
class CorpusGeneratorEdgeCasesTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ReplyHandler&MockObject $replyHandler;
    private LanguageDetector $languageDetector;
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->replyHandler = $this->createMock(ReplyHandler::class);
        $this->languageDetector = new LanguageDetector();
        $this->connection = $this->createMock(Connection::class);
        $this->em->method('getConnection')->willReturn($this->connection);
    }

    private function createGenerator(): CorpusGenerator
    {
        return new CorpusGenerator(
            em: $this->em,
            replyHandler: $this->replyHandler,
            languageDetector: $this->languageDetector,
            logger: new NullLogger(),
            replyOrchestrator: null, // Can't mock final class
        );
    }

    public function test_generate_returns_empty_when_no_conversations(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $generator = $this->createGenerator();
        $result = $generator->generate();

        $this->assertEmpty($result['entries']);
        $this->assertSame(0, $result['summary']['total']);
    }

    public function test_generate_dry_run_builds_entries_without_llm(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            [
                'conv_id' => 'conv-1',
                'status' => 'open',
                'scam_type_code' => 'PHISHING',
                'persona_code' => 'elderly_person',
                'last_msg_id' => 'msg-1',
                'last_inbound_text' => 'Hello',
                'message_count' => 5,
            ],
            [
                'conv_id' => 'conv-2',
                'status' => 'closed',
                'scam_type_code' => 'ROMANCE',
                'persona_code' => 'lonely_person',
                'last_msg_id' => 'msg-2',
                'last_inbound_text' => 'Hi there',
                'message_count' => 3,
            ],
        ]);

        $generator = $this->createGenerator();
        $result = $generator->generate(dryRun: true, count: 10);

        $this->assertCount(2, $result['entries']);
        $this->assertSame('[DRY RUN — no LLM call]', $result['entries'][0]['text']);
        $this->assertSame('PHISHING', $result['entries'][0]['scam_type']);
        $this->assertSame('elderly_person', $result['entries'][0]['persona_code']);
        $this->assertSame(5, $result['entries'][0]['message_count']);
        $this->assertTrue($result['summary']['dry_run']);
    }

    public function test_generate_skips_entries_without_last_msg_id(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            [
                'conv_id' => 'conv-1',
                'status' => 'open',
                'scam_type_code' => 'PHISHING',
                'persona_code' => 'generic_user',
                'last_msg_id' => null,
                'last_inbound_text' => null,
                'message_count' => 0,
            ],
        ]);

        $generator = $this->createGenerator();
        $result = $generator->generate(dryRun: true);

        $this->assertEmpty($result['entries']);
    }

    public function test_generate_calls_progress_callback(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            [
                'conv_id' => 'conv-1',
                'status' => 'open',
                'scam_type_code' => 'PHISHING',
                'persona_code' => 'generic_user',
                'last_msg_id' => 'msg-1',
                'last_inbound_text' => 'Test',
                'message_count' => 2,
            ],
        ]);

        $progressCalls = [];
        $callback = function (int $processed, int $total) use (&$progressCalls) {
            $progressCalls[] = [$processed, $total];
        };

        $generator = $this->createGenerator();
        $generator->generate(dryRun: true, count: 10, onProgress: $callback);

        $this->assertCount(1, $progressCalls);
        $this->assertSame([1, 10], $progressCalls[0]);
    }

    public function test_generate_skips_when_no_orchestrator_and_not_dry_run(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            [
                'conv_id' => 'conv-1',
                'status' => 'open',
                'scam_type_code' => 'PHISHING',
                'persona_code' => 'generic_user',
                'last_msg_id' => 'msg-1',
                'last_inbound_text' => 'Test text',
                'message_count' => 2,
            ],
        ]);

        $this->replyHandler->method('getConversationContext')->willReturn([
            'conv_id' => 'conv-1',
            'persona' => 'generic_user',
        ]);

        // No orchestrator provided - entries skipped
        $generator = $this->createGenerator();
        $result = $generator->generate(dryRun: false);

        $this->assertEmpty($result['entries']);
    }

    public function test_generate_skips_when_no_context(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            [
                'conv_id' => 'conv-1',
                'status' => 'open',
                'scam_type_code' => 'PHISHING',
                'persona_code' => 'generic_user',
                'last_msg_id' => 'msg-1',
                'last_inbound_text' => 'Test',
                'message_count' => 2,
            ],
        ]);

        $this->replyHandler->method('getConversationContext')->willReturn(null);

        $generator = $this->createGenerator();
        $result = $generator->generate(dryRun: false);

        $this->assertEmpty($result['entries']);
    }

    public function test_generate_summary_calculates_correctly(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['conv_id' => 'c1', 'status' => 'open', 'scam_type_code' => 'PHISHING', 'persona_code' => 'elderly', 'last_msg_id' => 'm1', 'last_inbound_text' => 'A', 'message_count' => 3],
            ['conv_id' => 'c2', 'status' => 'open', 'scam_type_code' => 'PHISHING', 'persona_code' => 'generic', 'last_msg_id' => 'm2', 'last_inbound_text' => 'B', 'message_count' => 5],
            ['conv_id' => 'c3', 'status' => 'open', 'scam_type_code' => 'ROMANCE', 'persona_code' => 'elderly', 'last_msg_id' => 'm3', 'last_inbound_text' => 'C', 'message_count' => 2],
        ]);

        $generator = $this->createGenerator();
        $result = $generator->generate(dryRun: true);

        $summary = $result['summary'];
        $this->assertSame(3, $summary['total']);
        $this->assertTrue($summary['dry_run']);
        $this->assertSame(2, $summary['personas']['elderly']);
        $this->assertSame(1, $summary['personas']['generic']);
        $this->assertSame(2, $summary['scam_types']['PHISHING']);
        $this->assertSame(1, $summary['scam_types']['ROMANCE']);
    }

    public function test_generate_respects_count_limit(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['conv_id' => 'c1', 'status' => 'open', 'scam_type_code' => 'PHISHING', 'persona_code' => 'a', 'last_msg_id' => 'm1', 'last_inbound_text' => 'A', 'message_count' => 1],
            ['conv_id' => 'c2', 'status' => 'open', 'scam_type_code' => 'PHISHING', 'persona_code' => 'b', 'last_msg_id' => 'm2', 'last_inbound_text' => 'B', 'message_count' => 1],
            ['conv_id' => 'c3', 'status' => 'open', 'scam_type_code' => 'PHISHING', 'persona_code' => 'c', 'last_msg_id' => 'm3', 'last_inbound_text' => 'C', 'message_count' => 1],
        ]);

        $generator = $this->createGenerator();
        $result = $generator->generate(dryRun: true, count: 2);

        $this->assertCount(2, $result['entries']);
    }

    public function test_dry_run_entry_has_zero_cost_estimate(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['conv_id' => 'c1', 'status' => 'open', 'scam_type_code' => 'PHISHING', 'persona_code' => 'a', 'last_msg_id' => 'm1', 'last_inbound_text' => 'A', 'message_count' => 1],
        ]);

        $generator = $this->createGenerator();
        $result = $generator->generate(dryRun: true);

        $entry = $result['entries'][0];
        $this->assertSame(0, $entry['word_count']);
        $this->assertSame(0, $entry['attempts']);
        $this->assertFalse($entry['fallback_used']);
        $this->assertFalse($entry['approved']);
        $this->assertSame(0.003, $entry['cost_estimate']);
    }

    public function test_summary_empty_when_no_entries(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $generator = $this->createGenerator();
        $result = $generator->generate(dryRun: false);

        $summary = $result['summary'];
        $this->assertSame(0, $summary['total']);
        $this->assertSame(0, $summary['approved']);
        $this->assertSame(0, $summary['fallback']);
        $this->assertSame(0.0, $summary['total_cost']);
    }
}

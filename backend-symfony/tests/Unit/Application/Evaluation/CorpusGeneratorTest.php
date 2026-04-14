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
use PHPUnit\Framework\MockObject\MockObject;

final class CorpusGeneratorTest extends TestCase
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

    public function test_real_generation_without_orchestrator_skips(): void
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
            'persona' => 'elderly_person',
            'detected_language' => 'en',
        ]);

        // No ReplyOrchestrator provided — should skip gracefully
        $generator = new CorpusGenerator($em, $replyHandler, new LanguageDetector(), new NullLogger());
        $result = $generator->generate(count: 1, dryRun: false, sleep: 0);

        $this->assertEmpty($result['entries']);
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

    // ================================================================== //
    //  Merged from CorpusGeneratorAdditionalTest
    // ================================================================== //

    private function createEmWithRows(array $rows): EntityManagerInterface
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        return $em;
    }

    private function makeRow(string $convId, ?string $lastMsgId = 'm1', string $scamType = 'PHISHING', string $persona = 'p1', string $text = 'Hello', string $msgCount = '2'): array
    {
        return [
            'conv_id' => $convId,
            'status' => 'open',
            'scam_type_code' => $scamType,
            'persona_code' => $persona,
            'last_msg_id' => $lastMsgId,
            'last_inbound_text' => $text,
            'message_count' => $msgCount,
        ];
    }

    public function testCountLimitEnforcedInDryRun(): void
    {
        $rows = [
            $this->makeRow('c1'),
            $this->makeRow('c2'),
            $this->makeRow('c3'),
            $this->makeRow('c4'),
        ];
        $em = $this->createEmWithRows($rows);

        $replyHandler = $this->createMock(ReplyHandler::class);
        $generator = new CorpusGenerator($em, $replyHandler, new LanguageDetector(), new NullLogger());
        $result = $generator->generate(count: 2, dryRun: true, sleep: 0);

        $this->assertCount(2, $result['entries']);
        $this->assertSame(2, $result['summary']['total']);
    }

    public function testDryRunEntryHasExpectedDefaults(): void
    {
        $rows = [$this->makeRow('c1', 'm1', 'ROMANCE', 'lonely_person', 'My darling', '5')];
        $em = $this->createEmWithRows($rows);

        $replyHandler = $this->createMock(ReplyHandler::class);
        $generator = new CorpusGenerator($em, $replyHandler, new LanguageDetector(), new NullLogger());
        $result = $generator->generate(count: 1, dryRun: true, sleep: 0);

        $entry = $result['entries'][0];
        $this->assertSame('ROMANCE', $entry['scam_type']);
        $this->assertSame('lonely_person', $entry['persona_code']);
        $this->assertSame(5, $entry['message_count']);
        $this->assertSame(0, $entry['word_count']);
        $this->assertSame(0, $entry['attempts']);
        $this->assertFalse($entry['fallback_used']);
        $this->assertFalse($entry['approved']);
        $this->assertTrue($entry['security_pass']);
        $this->assertSame('[DRY RUN — no LLM call]', $entry['text']);
        $this->assertArrayHasKey('generated_at', $entry);
    }

    public function testSummaryAggregatesMultipleScamTypesAndPersonas(): void
    {
        $rows = [
            $this->makeRow('c1', 'm1', 'PHISHING', 'elderly_person'),
            $this->makeRow('c2', 'm2', 'ROMANCE', 'lonely_person'),
            $this->makeRow('c3', 'm3', 'PHISHING', 'bank_customer'),
            $this->makeRow('c4', 'm4', 'INVESTMENT', 'small_business_owner'),
        ];
        $em = $this->createEmWithRows($rows);

        $replyHandler = $this->createMock(ReplyHandler::class);
        $generator = new CorpusGenerator($em, $replyHandler, new LanguageDetector(), new NullLogger());
        $result = $generator->generate(count: 4, dryRun: true, sleep: 0);

        $summary = $result['summary'];
        $this->assertSame(4, $summary['total']);
        $this->assertCount(4, $summary['personas']);
        $this->assertCount(3, $summary['scam_types']);
        $this->assertSame(0, $summary['approved']);
        $this->assertSame(0, $summary['fallback']);
        $this->assertTrue($summary['dry_run']);
        $this->assertArrayHasKey('generated_at', $summary);
    }

    public function testNonNumericMessageCountDefaultsToZero(): void
    {
        $rows = [$this->makeRow('c1', 'm1', 'PHISHING', 'p1', 'Hello', 'not-a-number')];
        $em = $this->createEmWithRows($rows);

        $replyHandler = $this->createMock(ReplyHandler::class);
        $generator = new CorpusGenerator($em, $replyHandler, new LanguageDetector(), new NullLogger());
        $result = $generator->generate(count: 1, dryRun: true, sleep: 0);

        $this->assertSame(0, $result['entries'][0]['message_count']);
    }

    public function testAllConversationsWithNullLastMsgIdSkipped(): void
    {
        $rows = [
            $this->makeRow('c1', null),
            $this->makeRow('c2', null),
        ];
        $em = $this->createEmWithRows($rows);

        $replyHandler = $this->createMock(ReplyHandler::class);
        $generator = new CorpusGenerator($em, $replyHandler, new LanguageDetector(), new NullLogger());
        $result = $generator->generate(count: 2, dryRun: false, sleep: 0);

        $this->assertEmpty($result['entries']);
        $this->assertSame(0, $result['summary']['total']);
    }

    public function testSummaryTotalCostAccumulation(): void
    {
        $rows = [
            $this->makeRow('c1', 'm1'),
            $this->makeRow('c2', 'm2'),
            $this->makeRow('c3', 'm3'),
        ];
        $em = $this->createEmWithRows($rows);

        $replyHandler = $this->createMock(ReplyHandler::class);
        $generator = new CorpusGenerator($em, $replyHandler, new LanguageDetector(), new NullLogger());
        $result = $generator->generate(count: 3, dryRun: true, sleep: 0);

        // Each dry run entry has cost_estimate 0.003
        $this->assertSame(0.009, $result['summary']['total_cost']);
    }

    public function testDryRunSummaryHasDryRunTrue(): void
    {
        $rows = [$this->makeRow('c1')];
        $em = $this->createEmWithRows($rows);

        $replyHandler = $this->createMock(ReplyHandler::class);
        $generator = new CorpusGenerator($em, $replyHandler, new LanguageDetector(), new NullLogger());
        $result = $generator->generate(count: 1, dryRun: true, sleep: 0);

        $this->assertTrue($result['summary']['dry_run']);
    }

    public function testRealRunSummaryHasDryRunFalse(): void
    {
        // No orchestrator provided + no context -> entries empty but summary has dry_run=false
        $rows = [$this->makeRow('c1')];
        $em = $this->createEmWithRows($rows);

        $replyHandler = $this->createMock(ReplyHandler::class);
        $replyHandler->method('getConversationContext')->willReturn(null);

        $generator = new CorpusGenerator($em, $replyHandler, new LanguageDetector(), new NullLogger());
        $result = $generator->generate(count: 1, dryRun: false, sleep: 0);

        $this->assertFalse($result['summary']['dry_run']);
    }

    // ================================================================== //
    //  Merged from CorpusGeneratorEdgeCasesTest
    // ================================================================== //

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

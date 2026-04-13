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

/**
 * Additional unit tests for CorpusGenerator.
 *
 * Covers branches not exercised in the base CorpusGeneratorTest:
 * - Count limit enforcement (dry run)
 * - Dry run entry defaults with various scam types/personas
 * - Summary aggregation with multiple scam types
 * - Entries with non-numeric message_count
 * - Multiple conversations with null last_msg_id all skipped
 *
 * Note: ReplyOrchestrator is final and cannot be mocked in unit tests.
 * Tests requiring orchestrator must use integration tests.
 */
final class CorpusGeneratorAdditionalTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
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
}

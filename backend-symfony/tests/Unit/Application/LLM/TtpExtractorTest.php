<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\Prompt\PromptCatalog;
use App\Application\LLM\Prompt\PromptProvider;
use App\Application\LLM\TtpExtractor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The extractor must: call the LLM once when the first answer is fully valid,
 * retry exactly once with targeted feedback on format/vocabulary failures,
 * never retry on transport errors, compute evidence offsets server-side as
 * UTF-8 character offsets on the original text, dedup per code (higher
 * confidence wins), clamp confidence to [0, 1], and never throw.
 */
final class TtpExtractorTest extends TestCase
{
    private LLMClientInterface&MockObject $llmClient;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);
    }

    private function extractor(?LoggerInterface $logger = null): TtpExtractor
    {
        return new TtpExtractor(
            $this->llmClient,
            $logger ?? new NullLogger(),
            new PromptProvider('/nonexistent-prompt-dir', new NullLogger()),
        );
    }

    /**
     * @return list<array{code: string, definition: string}>
     */
    private function taxonomy(): array
    {
        return [
            ['code' => 'SB-T001', 'definition' => 'Authority impersonation'],
            ['code' => 'SB-T002', 'definition' => 'Urgency pressure'],
            ['code' => 'SB-T003', 'definition' => 'Advance fee request'],
        ];
    }

    /**
     * @param list<array{ttp_id: string, confidence: float, evidence: string}> $items
     */
    private function llmResponse(array $items): string
    {
        return json_encode($items, JSON_THROW_ON_ERROR);
    }

    public function testHappyMultiLabelComputesUtf8CharacterOffsetsOnTheOriginalText(): void
    {
        // 'è' is 1 character but 2 bytes: a byte-based offset would be shifted by one.
        $text = 'Chèr client, URGENT: reply within 24 hours or your account is closed. Send the $500 fee now.';
        self::assertSame(14, strpos($text, 'URGENT'), 'byte offset differs from character offset by construction');

        $this->llmClient
            ->expects(self::once())
            ->method('chat')
            ->willReturn($this->llmResponse([
                ['ttp_id' => 'SB-T002', 'confidence' => 0.9, 'evidence' => 'URGENT: reply within 24 hours'],
                ['ttp_id' => 'SB-T003', 'confidence' => 0.8, 'evidence' => 'Send the $500 fee now.'],
            ]));

        $result = $this->extractor()->extract($text, $this->taxonomy());

        self::assertCount(2, $result);
        self::assertSame('SB-T002', $result[0]['ttp_code']);
        self::assertSame(0.9, $result[0]['confidence']);
        self::assertSame(13, $result[0]['evidence_start']);
        self::assertSame(42, $result[0]['evidence_end']);
        self::assertSame('URGENT: reply within 24 hours', mb_substr($text, 13, 42 - 13));
        self::assertSame('SB-T003', $result[1]['ttp_code']);
        self::assertSame(70, $result[1]['evidence_start']);
        self::assertSame(92, $result[1]['evidence_end']);
        self::assertSame('Send the $500 fee now.', mb_substr($text, 70, 92 - 70));
    }

    public function testFullyValidFirstAttemptMakesExactlyOneCallWithTheExpectedPromptAndOptions(): void
    {
        $text = 'You must act now, the police will be informed.';

        $this->llmClient
            ->expects(self::once())
            ->method('chat')
            ->with(
                self::callback(function (array $messages) use ($text): bool {
                    $userContent = $messages[1]['content'] ?? '';

                    return str_contains($userContent, 'SB-T002 — Urgency pressure')
                        && str_contains($userContent, $text);
                }),
                self::callback(static fn (array $options): bool => $options['temperature'] === 0.1
                    && $options['max_tokens'] === 4000
                    && $options['purpose'] === 'ttp_extraction'),
            )
            ->willReturn($this->llmResponse([
                ['ttp_id' => 'SB-T001', 'confidence' => 0.7, 'evidence' => 'the police will be informed'],
            ]));

        $result = $this->extractor()->extract($text, $this->taxonomy());

        self::assertCount(1, $result);
        self::assertSame('SB-T001', $result[0]['ttp_code']);
    }

    public function testInvalidJsonFirstAttemptTriggersOneRetryWithExplicitFeedback(): void
    {
        $calls = [];

        $this->llmClient
            ->expects(self::exactly(2))
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$calls): string {
                $calls[] = $messages;

                return \count($calls) === 1
                    ? 'this is not JSON at all'
                    : $this->llmResponse([
                        ['ttp_id' => 'SB-T001', 'confidence' => 0.7, 'evidence' => 'a message from the police'],
                    ]);
            });

        $result = $this->extractor()->extract('This is a message from the police about you.', $this->taxonomy());

        self::assertCount(1, $result);
        self::assertSame('SB-T001', $result[0]['ttp_code']);
        self::assertStringNotContainsString('Your previous answer was invalid', $calls[0][1]['content']);
        self::assertStringContainsString('Your previous answer was invalid', $calls[1][1]['content']);
        self::assertStringContainsString('invalid JSON', $calls[1][1]['content']);
    }

    public function testTruncatedJsonFirstAttemptTriggersTheFeedbackRetry(): void
    {
        // A max_tokens truncation yields an unterminated JSON array — invalid JSON,
        // which must flow through the same parse-failure -> one-feedback-retry path
        // (the retry cannot itself un-truncate, hence the raised MAX_TOKENS ceiling).
        $calls = [];

        $this->llmClient
            ->expects(self::exactly(2))
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$calls): string {
                $calls[] = $messages;

                return \count($calls) === 1
                    ? '[{"ttp_id":"SB-T001","confidence":0.7,"evidence":"the police'
                    : $this->llmResponse([
                        ['ttp_id' => 'SB-T001', 'confidence' => 0.7, 'evidence' => 'a message from the police'],
                    ]);
            });

        $result = $this->extractor()->extract('This is a message from the police about you.', $this->taxonomy());

        self::assertCount(1, $result);
        self::assertSame('SB-T001', $result[0]['ttp_code']);
        self::assertStringContainsString('Your previous answer was invalid', $calls[1][1]['content']);
        self::assertStringContainsString('invalid JSON', $calls[1][1]['content']);
    }

    public function testMalformedUtf8InputIsScrubbedBeforeTheLlmCall(): void
    {
        // An undeclared JIS/Shift-JIS body can reach the extractor as invalid UTF-8;
        // it must be scrubbed so the request payload stays valid and never crashes
        // (the ~0.3% JSON-parse failures observed during the production backfill).
        $text = "Urgent \x80\xFF pay the beneficiary now";

        $this->llmClient
            ->expects(self::once())
            ->method('chat')
            ->willReturnCallback(function (array $messages): string {
                $user = $messages[1]['content'] ?? '';
                self::assertSame(1, preg_match('//u', $user), 'LLM payload must be valid UTF-8 after scrubbing');

                return $this->llmResponse([
                    ['ttp_id' => 'SB-T003', 'confidence' => 0.8, 'evidence' => 'pay the beneficiary now'],
                ]);
            });

        $result = $this->extractor()->extract($text, $this->taxonomy());

        self::assertCount(1, $result);
        self::assertSame('SB-T003', $result[0]['ttp_code']);
    }

    public function testOutOfTaxonomyCodeTriggersRetryThenStillInvalidItemsAreDroppedAndLogged(): void
    {
        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message, array $context = []) use (&$warnings): void {
            $warnings[] = ['message' => (string) $message, 'context' => $context];
        });

        $calls = [];

        $this->llmClient
            ->expects(self::exactly(2))
            ->method('chat')
            ->willReturnCallback(function (array $messages) use (&$calls): string {
                $calls[] = $messages;

                return \count($calls) === 1
                    ? $this->llmResponse([
                        ['ttp_id' => 'SB-T999', 'confidence' => 0.9, 'evidence' => 'pay the fee today'],
                    ])
                    : $this->llmResponse([
                        ['ttp_id' => 'SB-T003', 'confidence' => 0.8, 'evidence' => 'pay the fee today'],
                        ['ttp_id' => 'SB-T999', 'confidence' => 0.9, 'evidence' => 'pay the fee today'],
                    ]);
            });

        $result = $this->extractor($logger)->extract('Please pay the fee today.', $this->taxonomy());

        self::assertCount(1, $result);
        self::assertSame('SB-T003', $result[0]['ttp_code']);
        self::assertStringContainsString('unknown ttp_id "SB-T999"', $calls[1][1]['content']);

        $dropped = array_filter($warnings, static fn (array $w): bool => str_contains((string) ($w['context']['reason'] ?? ''), 'SB-T999'));
        self::assertCount(1, $dropped, 'the still-invalid item must be dropped with a logged warning');
    }

    public function testLlmExceptionReturnsEmptyWithoutASecondCall(): void
    {
        $this->llmClient
            ->expects(self::once())
            ->method('chat')
            ->willThrowException(new \RuntimeException('Connection timeout'));

        self::assertSame([], $this->extractor()->extract('Urgent payment required.', $this->taxonomy()));
    }

    public function testEmptyTextOrEmptyTaxonomyReturnsEmptyWithoutAnyLlmCall(): void
    {
        $this->llmClient->expects(self::never())->method('chat');

        $extractor = $this->extractor();

        self::assertSame([], $extractor->extract('', $this->taxonomy()));
        self::assertSame([], $extractor->extract("   \n\t ", $this->taxonomy()));
        self::assertSame([], $extractor->extract('Urgent payment required.', []));
    }

    public function testEvidenceNotFoundVerbatimKeepsTheItemWithNullOffsets(): void
    {
        $this->llmClient
            ->method('chat')
            ->willReturn($this->llmResponse([
                ['ttp_id' => 'SB-T002', 'confidence' => 0.6, 'evidence' => 'a paraphrase that is not in the message'],
            ]));

        $result = $this->extractor()->extract('Reply before tomorrow or your account is closed.', $this->taxonomy());

        self::assertCount(1, $result);
        self::assertSame('SB-T002', $result[0]['ttp_code']);
        self::assertNull($result[0]['evidence_start']);
        self::assertNull($result[0]['evidence_end']);
    }

    public function testDuplicateTtpCodeKeepsTheHigherConfidenceItem(): void
    {
        $this->llmClient
            ->method('chat')
            ->willReturn($this->llmResponse([
                ['ttp_id' => 'SB-T002', 'confidence' => 0.4, 'evidence' => 'act now'],
                ['ttp_id' => 'SB-T002', 'confidence' => 0.9, 'evidence' => 'last warning'],
            ]));

        $result = $this->extractor()->extract('You must act now, this is your last warning.', $this->taxonomy());

        self::assertCount(1, $result);
        self::assertSame('SB-T002', $result[0]['ttp_code']);
        self::assertSame(0.9, $result[0]['confidence']);
        self::assertSame('last warning', $result[0]['evidence']);
    }

    public function testConfidenceIsClampedToTheUnitInterval(): void
    {
        $this->llmClient
            ->method('chat')
            ->willReturn($this->llmResponse([
                ['ttp_id' => 'SB-T001', 'confidence' => -0.2, 'evidence' => 'the police'],
                ['ttp_id' => 'SB-T002', 'confidence' => 1.7, 'evidence' => 'act now'],
            ]));

        $result = $this->extractor()->extract('The police said you must act now.', $this->taxonomy());

        self::assertCount(2, $result);
        self::assertSame(0.0, $result[0]['confidence']);
        self::assertSame(1.0, $result[1]['confidence']);
    }

    public function testCatalogRequiredTokensMatchTheExtractorEnforcedTokens(): void
    {
        // Drift guard: the catalog (which the CLI/UI validate overrides against) must list
        // exactly the tokens the extractor enforces via PromptProvider::resolve (it passes
        // array_keys of its replacement map: the TTP list and the message).
        self::assertSame(['{{TTP_LIST}}', '{{MESSAGE}}'], PromptCatalog::requiredPlaceholders('ttp_extraction'));
    }

    public function testTransportErrorOnTheRetryCallReturnsEmptyWithoutThrowing(): void
    {
        // Attempt 1 fails on format (earning the feedback retry), attempt 2 dies on
        // transport: the extractor must swallow it and return [] — never throw.
        $calls = 0;
        $this->llmClient
            ->expects(self::exactly(2))
            ->method('chat')
            ->willReturnCallback(function () use (&$calls): string {
                if (++$calls === 1) {
                    return 'not json at all';
                }

                throw new \RuntimeException('connection reset');
            });

        self::assertSame([], $this->extractor()->extract('The police said pay now.', $this->taxonomy()));
    }

    public function testStructurallyMalformedItemsAreDroppedAfterTheRetry(): void
    {
        // Malformed items (wrong types, missing keys, empty evidence) trigger the retry;
        // when the retry repeats them, only the valid item survives.
        $malformed = json_encode([
            ['ttp_id' => 42, 'confidence' => 0.9, 'evidence' => 'the police'],
            ['ttp_id' => 'SB-T002', 'confidence' => 'not-a-number', 'evidence' => 'act now'],
            ['ttp_id' => 'SB-T003', 'confidence' => 0.8, 'evidence' => ''],
            'not-even-an-array',
            ['ttp_id' => 'SB-T001', 'confidence' => 0.7, 'evidence' => 'the police'],
        ], \JSON_THROW_ON_ERROR);

        $this->llmClient
            ->expects(self::exactly(2))
            ->method('chat')
            ->willReturn($malformed);

        $result = $this->extractor()->extract('The police said act now.', $this->taxonomy());

        self::assertCount(1, $result);
        self::assertSame('SB-T001', $result[0]['ttp_code']);
    }

    public function testOversizedTextIsTruncatedForTheLlmButOffsetsUseTheOriginalText(): void
    {
        // The prompt payload is byte-capped (mb_strcut keeps it valid UTF-8), while
        // evidence offsets are still computed on the untruncated original — including
        // evidence living entirely in the truncated-away tail.
        $tailEvidence = 'wire the funds to our beneficiary today';
        $text = str_repeat('é', 3000) . str_repeat('a', 2000) . $tailEvidence;

        $this->llmClient
            ->expects(self::once())
            ->method('chat')
            ->willReturnCallback(function (array $messages) use ($tailEvidence): string {
                $user = $messages[1]['content'];
                self::assertStringContainsString('... [truncated]', $user);
                self::assertStringNotContainsString($tailEvidence, $user);
                self::assertNotFalse(preg_match('//u', $user), 'prompt payload must stay valid UTF-8');

                return $this->llmResponse([
                    ['ttp_id' => 'SB-T003', 'confidence' => 0.9, 'evidence' => $tailEvidence],
                ]);
            });

        $result = $this->extractor()->extract($text, $this->taxonomy());

        self::assertCount(1, $result);
        self::assertSame(5000, $result[0]['evidence_start']);
        self::assertSame(5000 + mb_strlen($tailEvidence), $result[0]['evidence_end']);
    }
}

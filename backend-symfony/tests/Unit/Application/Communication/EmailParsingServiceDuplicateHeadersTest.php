<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\EmailParsingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Duplicate header handling, and the header-name behaviour it depends on.
 *
 * The parser splits on the first `:` and validates nothing, so header names
 * reach the backend as the sender wrote them (lowercased, not normalised). Both
 * facts below are load-bearing for the reply path — see ReplyRecipientPolicy.
 */
final class EmailParsingServiceDuplicateHeadersTest extends TestCase
{
    private EmailParsingService $service;

    protected function setUp(): void
    {
        $this->service = new EmailParsingService(new NullLogger());
    }

    /**
     * @return array<string, mixed>
     */
    private function headersOf(string $raw): array
    {
        $parsed = $this->service->parseEmail(base64_encode($raw));

        /** @var array<string, mixed> $headers */
        $headers = $parsed['headers'];

        return $headers;
    }

    /**
     * Pins the behaviour that made the reply-path bug reachable: a header name
     * the sender invents survives to the headers array verbatim.
     *
     * This is not a bug to fix here — it is why every consumer of this array
     * must treat its keys as attacker-controlled.
     */
    public function testUnnormalisedHeaderNamesSurvive(): void
    {
        $headers = $this->headersOf(
            "From: scammer@evil.example\r\n" .
            "To: honeypot@operator.example\r\n" .
            "Reply_To: victim@target.example\r\n" .
            "Subject: hello\r\n\r\nbody\r\n"
        );

        self::assertArrayHasKey('reply_to', $headers);
        self::assertSame('victim@target.example', $headers['reply_to']);
    }

    /**
     * RFC 5322 §3.6 allows one `From:`. A second is malformed and usually
     * forged; MTAs read the first, so the backend must too — reading the last
     * would make the backend disagree with everything upstream about who sent
     * the mail.
     */
    public function testDuplicateFromKeepsTheFirst(): void
    {
        $headers = $this->headersOf(
            "From: first@evil.example\r\n" .
            "From: second@evil.example\r\n" .
            "To: honeypot@operator.example\r\n" .
            "Subject: hello\r\n\r\nbody\r\n"
        );

        self::assertSame('first@evil.example', $headers['from']);
    }

    public function testDuplicationIsRecordedNotDropped(): void
    {
        $headers = $this->headersOf(
            "From: first@evil.example\r\n" .
            "From: second@evil.example\r\n" .
            "To: honeypot@operator.example\r\n" .
            "To: elsewhere@target.example\r\n" .
            "Subject: hello\r\n\r\nbody\r\n"
        );

        self::assertArrayHasKey('x-scambuster-duplicate-headers', $headers);
        self::assertSame('from,to', $headers['x-scambuster-duplicate-headers']);
    }

    /**
     * The marker says "this mail was forged". A sender who could set it would
     * manufacture that signal at will, so an inbound header of that name is
     * dropped before ours is written.
     */
    public function testSenderCannotForgeTheDuplicateMarker(): void
    {
        $headers = $this->headersOf(
            "From: scammer@evil.example\r\n" .
            "To: honeypot@operator.example\r\n" .
            "X-Scambuster-Duplicate-Headers: from,to,subject\r\n" .
            "Subject: hello\r\n\r\nbody\r\n"
        );

        self::assertArrayNotHasKey('x-scambuster-duplicate-headers', $headers);
    }

    /**
     * A forged marker must not survive alongside a real one either.
     */
    public function testForgedMarkerIsReplacedByTheRealOne(): void
    {
        $headers = $this->headersOf(
            "From: first@evil.example\r\n" .
            "From: second@evil.example\r\n" .
            "To: honeypot@operator.example\r\n" .
            "X-Scambuster-Duplicate-Headers: subject,date\r\n" .
            "Subject: hello\r\n\r\nbody\r\n"
        );

        self::assertSame('from', $headers['x-scambuster-duplicate-headers']);
    }

    public function testCleanMailCarriesNoDuplicateMarker(): void
    {
        $headers = $this->headersOf(
            "From: scammer@evil.example\r\n" .
            "To: honeypot@operator.example\r\n" .
            "Subject: hello\r\n\r\nbody\r\n"
        );

        self::assertArrayNotHasKey('x-scambuster-duplicate-headers', $headers);
    }

    /**
     * Headers RFC 5322 allows more than once must not be collapsed by the
     * singleton rule — only the listed ones are constrained.
     */
    public function testRepeatableHeadersAreNotTreatedAsDuplicates(): void
    {
        $headers = $this->headersOf(
            "From: scammer@evil.example\r\n" .
            "To: honeypot@operator.example\r\n" .
            "Received: from a.test\r\n" .
            "Received: from b.test\r\n" .
            "Subject: hello\r\n\r\nbody\r\n"
        );

        self::assertArrayNotHasKey('x-scambuster-duplicate-headers', $headers);
        // The repeatable header itself still resolves last-wins, as before.
        self::assertSame('from b.test', $headers['received']);
    }
}

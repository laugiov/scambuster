<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\EmailParsingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Backend-side attachment extraction (hybrid fallback)
 *
 * Unit tests for `EmailParsingService::extractAttachments()`.
 *
 * The method takes a base64-encoded RFC822 string (same input shape as
 * `parseEmail()`) and returns an array of attachment metadata in the format
 * expected by `IngestHandler::processAttachments()`:
 *
 *   [
 *     ['filename' => string, 'mime_type' => string, 'size_bytes' => int, 'sha256' => string],
 *     ...
 *   ]
 *
 * Inline images, multipart containers, text/plain and text/html parts (when
 * not flagged as attachment) MUST be excluded. The method MUST be defensive
 * and never throw on malformed input — it returns `[]` if the parser fails.
 */
final class EmailParsingServiceExtractAttachmentsTest extends TestCase
{
    private EmailParsingService $service; // @phpstan-ignore-line

    protected function setUp(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->service = new EmailParsingService($logger);
    }

    /**
     * Build a base64-encoded RFC822 from a raw string.
     */
    private function b64(string $raw): string
    {
        return base64_encode($raw);
    }

    // ─────────────────────────────────────────────────────────────────
    // Slice 1A — Empty cases
    // ─────────────────────────────────────────────────────────────────

    public function testReturnsEmptyArrayForPlainTextMail(): void
    {
        $raw = <<<MAIL
        From: alice@example.com
        To: bob@example.com
        Subject: Plain text only
        Date: Thu, 10 Apr 2026 10:00:00 +0000
        Message-ID: <plain@example.com>
        Content-Type: text/plain; charset=UTF-8

        Just a plain text body, nothing else.
        MAIL;

        $result = $this->service->extractAttachments($this->b64($raw));

        $this->assertSame([], $result);
    }

    public function testReturnsEmptyArrayForMultipartAlternativeWithoutAttachments(): void
    {
        $raw = <<<MAIL
        From: alice@example.com
        To: bob@example.com
        Subject: HTML mail
        Date: Thu, 10 Apr 2026 10:00:00 +0000
        Message-ID: <alt@example.com>
        MIME-Version: 1.0
        Content-Type: multipart/alternative; boundary="bnd"

        --bnd
        Content-Type: text/plain; charset=UTF-8

        Plain version.
        --bnd
        Content-Type: text/html; charset=UTF-8

        <p>HTML version.</p>
        --bnd--
        MAIL;

        $result = $this->service->extractAttachments($this->b64($raw));

        $this->assertSame([], $result);
    }

    public function testReturnsEmptyArrayForEmptyRawSource(): void
    {
        $result = $this->service->extractAttachments('');

        $this->assertSame([], $result);
    }

    public function testReturnsEmptyArrayForInvalidBase64(): void
    {
        // Not valid base64 — must not throw, must return empty
        $result = $this->service->extractAttachments('!!!not-base64!!!');

        $this->assertSame([], $result);
    }

    // ─────────────────────────────────────────────────────────────────
    // Slice 1B — Single attachment happy path
    // ─────────────────────────────────────────────────────────────────

    public function testExtractsSinglePdfAttachment(): void
    {
        $pdfBytes = "%PDF-1.4\nfake pdf content for test\n%%EOF";
        $pdfB64 = base64_encode($pdfBytes);
        $expectedSha256 = hash('sha256', $pdfBytes);
        $expectedSize = strlen($pdfBytes);

        $raw = <<<MAIL
        From: alice@example.com
        To: bob@example.com
        Subject: With one PDF
        Date: Thu, 10 Apr 2026 10:00:00 +0000
        Message-ID: <pdf@example.com>
        MIME-Version: 1.0
        Content-Type: multipart/mixed; boundary="bnd"

        --bnd
        Content-Type: text/plain; charset=UTF-8

        See the attached PDF.
        --bnd
        Content-Type: application/pdf
        Content-Disposition: attachment; filename="invoice.pdf"
        Content-Transfer-Encoding: base64

        {$pdfB64}
        --bnd--
        MAIL;

        $result = $this->service->extractAttachments($this->b64($raw));

        $this->assertCount(1, $result);
        $this->assertSame('invoice.pdf', $result[0]['filename']);
        $this->assertSame('application/pdf', $result[0]['mime_type']);
        $this->assertSame($expectedSize, $result[0]['size_bytes']);
        $this->assertSame($expectedSha256, $result[0]['sha256']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Slice 1C — Multiple mixed attachments
    // ─────────────────────────────────────────────────────────────────

    public function testExtractsThreeMixedAttachments(): void
    {
        $pdfBytes = "%PDF-1.4\npdf content\n%%EOF";
        $docxBytes = "PK\x03\x04docx fake bytes here";
        $zipBytes = "PK\x03\x04zip fake bytes here";

        $raw = <<<MAIL
        From: alice@example.com
        To: bob@example.com
        Subject: Three attachments
        Date: Thu, 10 Apr 2026 10:00:00 +0000
        Message-ID: <three@example.com>
        MIME-Version: 1.0
        Content-Type: multipart/mixed; boundary="bnd"

        --bnd
        Content-Type: text/plain; charset=UTF-8

        See attachments.
        --bnd
        Content-Type: application/pdf
        Content-Disposition: attachment; filename="report.pdf"
        Content-Transfer-Encoding: base64

        {$this->wrapB64($pdfBytes)}
        --bnd
        Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document
        Content-Disposition: attachment; filename="contract.docx"
        Content-Transfer-Encoding: base64

        {$this->wrapB64($docxBytes)}
        --bnd
        Content-Type: application/zip
        Content-Disposition: attachment; filename="archive.zip"
        Content-Transfer-Encoding: base64

        {$this->wrapB64($zipBytes)}
        --bnd--
        MAIL;

        $result = $this->service->extractAttachments($this->b64($raw));

        $this->assertCount(3, $result);

        $byName = [];

        foreach ($result as $att) {
            $byName[$att['filename']] = $att;
        }

        $this->assertArrayHasKey('report.pdf', $byName);
        $this->assertArrayHasKey('contract.docx', $byName);
        $this->assertArrayHasKey('archive.zip', $byName);

        $this->assertSame('application/pdf', $byName['report.pdf']['mime_type']);
        $this->assertSame(hash('sha256', $pdfBytes), $byName['report.pdf']['sha256']);
        $this->assertSame(strlen($pdfBytes), $byName['report.pdf']['size_bytes']);

        $this->assertSame(hash('sha256', $docxBytes), $byName['contract.docx']['sha256']);
        $this->assertSame(hash('sha256', $zipBytes), $byName['archive.zip']['sha256']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Slice 1D — Inline image must be ignored
    // ─────────────────────────────────────────────────────────────────

    public function testIgnoresInlineImage(): void
    {
        $pngBytes = "\x89PNG\r\n\x1a\nfake png bytes";

        $raw = <<<MAIL
        From: alice@example.com
        To: bob@example.com
        Subject: Inline image only
        Date: Thu, 10 Apr 2026 10:00:00 +0000
        Message-ID: <inline@example.com>
        MIME-Version: 1.0
        Content-Type: multipart/related; boundary="bnd"

        --bnd
        Content-Type: text/html; charset=UTF-8

        <p>See <img src="cid:logo123"> the logo</p>
        --bnd
        Content-Type: image/png
        Content-ID: <logo123>
        Content-Disposition: inline; filename="logo.png"
        Content-Transfer-Encoding: base64

        {$this->wrapB64($pngBytes)}
        --bnd--
        MAIL;

        $result = $this->service->extractAttachments($this->b64($raw));

        $this->assertSame([], $result);
    }

    // ─────────────────────────────────────────────────────────────────
    // Slice 1E — text/calendar must be persisted
    // ─────────────────────────────────────────────────────────────────

    public function testExtractsTextCalendarAsAttachment(): void
    {
        $icsBytes = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nSUMMARY:Phish meeting\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $raw = <<<MAIL
        From: alice@example.com
        To: bob@example.com
        Subject: Meeting invite
        Date: Thu, 10 Apr 2026 10:00:00 +0000
        Message-ID: <ics@example.com>
        MIME-Version: 1.0
        Content-Type: multipart/mixed; boundary="bnd"

        --bnd
        Content-Type: text/plain; charset=UTF-8

        Please join.
        --bnd
        Content-Type: text/calendar; method=REQUEST; charset=UTF-8
        Content-Disposition: attachment; filename="invite.ics"

        {$icsBytes}
        --bnd--
        MAIL;

        $result = $this->service->extractAttachments($this->b64($raw));

        $this->assertCount(1, $result);
        $this->assertSame('invite.ics', $result[0]['filename']);
        $this->assertStringStartsWith('text/calendar', $result[0]['mime_type']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Slice 1F — Deeply nested multipart
    // ─────────────────────────────────────────────────────────────────

    public function testExtractsAttachmentFromDeeplyNestedMultipart(): void
    {
        $pdfBytes = "%PDF-1.4\nnested pdf content\n%%EOF";

        $raw = <<<MAIL
        From: alice@example.com
        To: bob@example.com
        Subject: Nested
        Date: Thu, 10 Apr 2026 10:00:00 +0000
        Message-ID: <nested@example.com>
        MIME-Version: 1.0
        Content-Type: multipart/mixed; boundary="outer"

        --outer
        Content-Type: multipart/alternative; boundary="inner"

        --inner
        Content-Type: text/plain; charset=UTF-8

        Plain.
        --inner
        Content-Type: text/html; charset=UTF-8

        <p>HTML.</p>
        --inner--
        --outer
        Content-Type: application/pdf
        Content-Disposition: attachment; filename="nested.pdf"
        Content-Transfer-Encoding: base64

        {$this->wrapB64($pdfBytes)}
        --outer--
        MAIL;

        $result = $this->service->extractAttachments($this->b64($raw));

        $this->assertCount(1, $result);
        $this->assertSame('nested.pdf', $result[0]['filename']);
        $this->assertSame(hash('sha256', $pdfBytes), $result[0]['sha256']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Slice 1G — Malformed multipart defensive
    // ─────────────────────────────────────────────────────────────────

    public function testReturnsEmptyArrayOrSalvageOnMalformedMultipart(): void
    {
        // Truncated multipart — the boundary closing line is missing.
        $raw = <<<MAIL
        From: alice@example.com
        To: bob@example.com
        Subject: Broken
        Date: Thu, 10 Apr 2026 10:00:00 +0000
        Message-ID: <broken@example.com>
        MIME-Version: 1.0
        Content-Type: multipart/mixed; boundary="bnd"

        --bnd
        Content-Type: text/plain; charset=UTF-8

        Body that ends abruptly without closing boundary
        MAIL;

        // Must NOT throw a fatal exception — defensive behavior is the contract.
        // The library returns [] for this input (truncated boundary, body is
        // not a real attachment part). The contract is "no exception, no crash".
        $result = $this->service->extractAttachments($this->b64($raw));

        $this->assertSame([], $result);
    }

    public function testReturnsEmptyArrayOnRandomGarbage(): void
    {
        $result = $this->service->extractAttachments($this->b64('this is not an email at all, just random bytes'));

        $this->assertSame([], $result);
    }

    // ─────────────────────────────────────────────────────────────────
    // Slice 1H — Size limit enforcement
    // ─────────────────────────────────────────────────────────────────

    public function testSkipsAttachmentExceedingSizeLimit(): void
    {
        // Inject a tiny 100-byte limit so we can test with a small fixture
        // (avoids OOM when materializing 25MB+ in PHP memory).
        $logger = $this->createMock(LoggerInterface::class);
        $serviceWithSmallLimit = new EmailParsingService($logger, null, 100);

        // 200-byte attachment — exceeds the 100-byte injected limit
        $bigBytes = str_repeat('A', 200);

        $raw = <<<MAIL
        From: alice@example.com
        To: bob@example.com
        Subject: Big attachment
        Date: Thu, 10 Apr 2026 10:00:00 +0000
        Message-ID: <big@example.com>
        MIME-Version: 1.0
        Content-Type: multipart/mixed; boundary="bnd"

        --bnd
        Content-Type: text/plain; charset=UTF-8

        See big attachment.
        --bnd
        Content-Type: application/octet-stream
        Content-Disposition: attachment; filename="big.bin"
        Content-Transfer-Encoding: base64

        {$this->wrapB64($bigBytes)}
        --bnd--
        MAIL;

        $result = $serviceWithSmallLimit->extractAttachments($this->b64($raw));

        // The big attachment is skipped — no entries returned.
        $this->assertSame([], $result);
    }

    // ─────────────────────────────────────────────────────────────────
    // Helper: wrap base64 to 76-char lines as per RFC2045
    // ─────────────────────────────────────────────────────────────────

    private function wrapB64(string $bytes): string
    {
        return chunk_split(base64_encode($bytes), 76, "\n");
    }
}

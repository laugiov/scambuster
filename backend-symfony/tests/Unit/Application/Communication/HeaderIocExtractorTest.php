<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\HeaderIocExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HeaderIocExtractor service.
 *
 * Tests extraction of 5 header-based IOC types:
 * - message_id
 * - subject
 * - spf_result
 * - dkim_result
 * - dmarc_result
 */
final class HeaderIocExtractorTest extends TestCase
{
    private HeaderIocExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new HeaderIocExtractor();
    }

    public function testExtractMessageIdFromDirectField(): void
    {
        $headers = [
            'message-id' => '<test-123@example.com>',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $messageIdIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'message_id');
        $this->assertCount(1, $messageIdIoc);

        $messageIdIoc = array_values($messageIdIoc)[0];
        $this->assertSame('test-123@example.com', $messageIdIoc['value']);
        $this->assertSame('test-123@example.com', $messageIdIoc['value_norm']);
        $this->assertSame('headers', $messageIdIoc['source']);
    }

    public function testExtractMessageIdFromParsedHeaders(): void
    {
        $headers = [
            'parsed' => [
                'headers' => [
                    'message-id' => '<parsed-456@example.com>',
                ],
            ],
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $messageIdIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'message_id');
        $this->assertCount(1, $messageIdIoc);

        $messageIdIoc = array_values($messageIdIoc)[0];
        $this->assertSame('parsed-456@example.com', $messageIdIoc['value']);
    }

    public function testExtractMessageIdWithoutAngleBrackets(): void
    {
        $headers = [
            'message-id' => 'no-brackets@example.com',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $messageIdIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'message_id');
        $messageIdIoc = array_values($messageIdIoc)[0];

        $this->assertSame('no-brackets@example.com', $messageIdIoc['value']);
    }

    public function testExtractSubject(): void
    {
        $headers = [];
        $subject = 'URGENT: Payment Required';

        $iocs = $this->extractor->extractHeaderIocs($headers, $subject);

        $subjectIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'subject');
        $this->assertCount(1, $subjectIoc);

        $subjectIoc = array_values($subjectIoc)[0];
        $this->assertSame('URGENT: Payment Required', $subjectIoc['value']);
        $this->assertSame('URGENT: Payment Required', $subjectIoc['value_norm']);
        $this->assertSame('headers', $subjectIoc['source']);
    }

    public function testExtractSubjectWithLeadingTrailingSpaces(): void
    {
        $headers = [];
        $subject = '  Subject with spaces  ';

        $iocs = $this->extractor->extractHeaderIocs($headers, $subject);

        $subjectIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'subject');
        $subjectIoc = array_values($subjectIoc)[0];

        $this->assertSame('  Subject with spaces  ', $subjectIoc['value']);
        $this->assertSame('Subject with spaces', $subjectIoc['value_norm']);
    }

    public function testDoesNotExtractEmptySubject(): void
    {
        $headers = [];
        $subject = '';

        $iocs = $this->extractor->extractHeaderIocs($headers, $subject);

        $subjectIocs = array_filter($iocs, fn($ioc) => $ioc['type'] === 'subject');
        $this->assertCount(0, $subjectIocs);
    }

    public function testExtractSpfResultFromArcAuthenticationResults(): void
    {
        $headers = [
            'arc-authentication-results' => 'i=1; mx.google.com; dkim=pass header.i=@gmail.com; spf=pass smtp.mailfrom=test@example.com; dmarc=pass',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $spfIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'spf_result');
        $this->assertCount(1, $spfIoc);

        $spfIoc = array_values($spfIoc)[0];
        $this->assertSame('PASS', $spfIoc['value']);
        $this->assertSame('PASS', $spfIoc['value_norm']);
        $this->assertSame('headers', $spfIoc['source']);
    }

    public function testExtractDkimResultFail(): void
    {
        $headers = [
            'arc-authentication-results' => 'mx.test.com; dkim=fail header.i=@evil.com; spf=pass; dmarc=fail',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $dkimIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'dkim_result');
        $this->assertCount(1, $dkimIoc);

        $dkimIoc = array_values($dkimIoc)[0];
        $this->assertSame('FAIL', $dkimIoc['value']);
    }

    public function testExtractDmarcResultFail(): void
    {
        $headers = [
            'arc-authentication-results' => 'mx.test.com; spf=softfail; dkim=neutral; dmarc=fail (p=QUARANTINE)',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $dmarcIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'dmarc_result');
        $this->assertCount(1, $dmarcIoc);

        $dmarcIoc = array_values($dmarcIoc)[0];
        $this->assertSame('FAIL', $dmarcIoc['value']);
    }

    public function testExtractSpfSoftfail(): void
    {
        $headers = [
            'authentication-results' => 'spf=softfail (sender IP is 1.2.3.4)',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $spfIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'spf_result');
        $spfIoc = array_values($spfIoc)[0];

        $this->assertSame('SOFTFAIL', $spfIoc['value']);
    }

    public function testExtractSpfNeutral(): void
    {
        $headers = [
            'authentication-results' => 'spf=neutral',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $spfIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'spf_result');
        $spfIoc = array_values($spfIoc)[0];

        $this->assertSame('NEUTRAL', $spfIoc['value']);
    }

    public function testExtractSpfNone(): void
    {
        $headers = [
            'authentication-results' => 'spf=none',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $spfIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'spf_result');
        $spfIoc = array_values($spfIoc)[0];

        $this->assertSame('NONE', $spfIoc['value']);
    }

    public function testExtractSpfTemperror(): void
    {
        $headers = [
            'authentication-results' => 'spf=temperror (DNS timeout)',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $spfIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'spf_result');
        $spfIoc = array_values($spfIoc)[0];

        $this->assertSame('TEMPERROR', $spfIoc['value']);
    }

    public function testExtractSpfPermerror(): void
    {
        $headers = [
            'authentication-results' => 'spf=permerror (invalid SPF record)',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $spfIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'spf_result');
        $spfIoc = array_values($spfIoc)[0];

        $this->assertSame('PERMERROR', $spfIoc['value']);
    }

    public function testExtractAllFiveHeaderIocsAtOnce(): void
    {
        $headers = [
            'message-id' => '<full-test@example.com>',
            'arc-authentication-results' => 'spf=pass; dkim=pass; dmarc=pass',
        ];
        $subject = 'Test Subject';

        $iocs = $this->extractor->extractHeaderIocs($headers, $subject);

        $this->assertCount(5, $iocs);

        $types = array_column($iocs, 'type');
        $this->assertContains('message_id', $types);
        $this->assertContains('subject', $types);
        $this->assertContains('spf_result', $types);
        $this->assertContains('dkim_result', $types);
        $this->assertContains('dmarc_result', $types);
    }

    public function testReturnsEmptyArrayWhenNoHeadersAvailable(): void
    {
        $headers = [];
        $subject = '';

        $iocs = $this->extractor->extractHeaderIocs($headers, $subject);

        $this->assertCount(0, $iocs);
    }

    public function testIgnoresInvalidAuthResult(): void
    {
        $headers = [
            'authentication-results' => 'spf=unknown_value',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $spfIocs = array_filter($iocs, fn($ioc) => $ioc['type'] === 'spf_result');
        $this->assertCount(0, $spfIocs);
    }

    public function testCaseInsensitiveAuthResultParsing(): void
    {
        $headers = [
            'authentication-results' => 'SPF=PASS; DKIM=FAIL',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $spfIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'spf_result');
        $dkimIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'dkim_result');

        $this->assertCount(1, $spfIoc);
        $this->assertCount(1, $dkimIoc);

        $spfIoc = array_values($spfIoc)[0];
        $dkimIoc = array_values($dkimIoc)[0];

        $this->assertSame('PASS', $spfIoc['value']);
        $this->assertSame('FAIL', $dkimIoc['value']);
    }

    public function testExtractFromParsedHeadersAuthResults(): void
    {
        $headers = [
            'parsed' => [
                'headers' => [
                    'arc-authentication-results' => 'spf=pass; dkim=pass; dmarc=pass',
                ],
            ],
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $authIocs = array_filter($iocs, fn($ioc) => in_array($ioc['type'], ['spf_result', 'dkim_result', 'dmarc_result']));
        $this->assertCount(3, $authIocs);
    }

    public function testRealGmailAuthenticationResults(): void
    {
        // Real-world example from Gmail
        $headers = [
            'arc-authentication-results' => 'i=1; mx.google.com; dkim=pass header.i=@gmail.com header.s=20230601 header.b="IaQ/H0Sz"; spf=pass (google.com: domain of honeypot@example.com designates 209.85.220.65 as permitted sender) smtp.mailfrom=honeypot@example.com; dmarc=pass (p=NONE sp=QUARANTINE dis=NONE) header.from=gmail.com; dara=pass header.i=@gmail.com',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $spfIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'spf_result');
        $dkimIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'dkim_result');
        $dmarcIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'dmarc_result');

        $this->assertCount(1, $spfIoc);
        $this->assertCount(1, $dkimIoc);
        $this->assertCount(1, $dmarcIoc);

        $spfIoc = array_values($spfIoc)[0];
        $dkimIoc = array_values($dkimIoc)[0];
        $dmarcIoc = array_values($dmarcIoc)[0];

        $this->assertSame('PASS', $spfIoc['value']);
        $this->assertSame('PASS', $dkimIoc['value']);
        $this->assertSame('PASS', $dmarcIoc['value']);
    }

    public function testExtractFromEmailSimpleFormat(): void
    {
        $headers = [
            'from' => 'scammer@evil.com',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $fromIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'email' && $ioc['source'] === 'headers.from');
        $this->assertCount(1, $fromIoc);

        $fromIoc = array_values($fromIoc)[0];
        $this->assertSame('scammer@evil.com', $fromIoc['value']);
        $this->assertSame('scammer@evil.com', $fromIoc['value_norm']);
        $this->assertSame('headers.from', $fromIoc['source']);
    }

    public function testExtractFromEmailWithName(): void
    {
        $headers = [
            'from' => 'Scammer Name <scammer@evil.com>',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $fromIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'email' && $ioc['source'] === 'headers.from');
        $this->assertCount(1, $fromIoc);

        $fromIoc = array_values($fromIoc)[0];
        $this->assertSame('scammer@evil.com', $fromIoc['value']);
        $this->assertSame('scammer@evil.com', $fromIoc['value_norm']);
    }

    public function testExtractFromEmailWithOnlyAngleBrackets(): void
    {
        $headers = [
            'from' => '<scammer@evil.com>',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $fromIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'email' && $ioc['source'] === 'headers.from');
        $fromIoc = array_values($fromIoc)[0];

        $this->assertSame('scammer@evil.com', $fromIoc['value']);
    }

    public function testExtractFromEmailCaseNormalization(): void
    {
        $headers = [
            'from' => 'SCAMMER@EVIL.COM',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $fromIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'email' && $ioc['source'] === 'headers.from');
        $fromIoc = array_values($fromIoc)[0];

        $this->assertSame('scammer@evil.com', $fromIoc['value']);
        $this->assertSame('scammer@evil.com', $fromIoc['value_norm']);
    }

    public function testExtractReplyToEmail(): void
    {
        $headers = [
            'reply-to' => 'reply@scammer.com',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $replyToIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'email' && $ioc['source'] === 'headers.reply-to');
        $this->assertCount(1, $replyToIoc);

        $replyToIoc = array_values($replyToIoc)[0];
        $this->assertSame('reply@scammer.com', $replyToIoc['value']);
        $this->assertSame('headers.reply-to', $replyToIoc['source']);
    }

    public function testExtractReturnPathEmail(): void
    {
        $headers = [
            'return-path' => 'bounce@evil.com',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $returnPathIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'email' && $ioc['source'] === 'headers.return-path');
        $this->assertCount(1, $returnPathIoc);

        $returnPathIoc = array_values($returnPathIoc)[0];
        $this->assertSame('bounce@evil.com', $returnPathIoc['value']);
        $this->assertSame('headers.return-path', $returnPathIoc['source']);
    }

    public function testExtractAllThreeEmailHeaders(): void
    {
        $headers = [
            'from' => 'from@scammer.com',
            'reply-to' => 'reply@scammer.com',
            'return-path' => 'bounce@scammer.com',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $emailIocs = array_filter($iocs, fn($ioc) => $ioc['type'] === 'email');
        $this->assertCount(3, $emailIocs);

        $sources = array_column($emailIocs, 'source');
        $this->assertContains('headers.from', $sources);
        $this->assertContains('headers.reply-to', $sources);
        $this->assertContains('headers.return-path', $sources);
    }

    public function testDoesNotExtractInvalidEmail(): void
    {
        $headers = [
            'from' => 'not-an-email',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $fromIocs = array_filter($iocs, fn($ioc) => $ioc['type'] === 'email' && $ioc['source'] === 'headers.from');
        $this->assertCount(0, $fromIocs);
    }

    public function testDoesNotExtractEmptyEmailHeader(): void
    {
        $headers = [
            'from' => '',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $fromIocs = array_filter($iocs, fn($ioc) => $ioc['type'] === 'email' && $ioc['source'] === 'headers.from');
        $this->assertCount(0, $fromIocs);
    }

    public function testRealWorldGmailFromHeader(): void
    {
        // Real-world example from Gmail
        $headers = [
            'from' => 'mme.leblondelfrancoise09@gmail.com',
        ];

        $iocs = $this->extractor->extractHeaderIocs($headers, '');

        $fromIoc = array_filter($iocs, fn($ioc) => $ioc['type'] === 'email' && $ioc['source'] === 'headers.from');
        $this->assertCount(1, $fromIoc);

        $fromIoc = array_values($fromIoc)[0];
        $this->assertSame('mme.leblondelfrancoise09@gmail.com', $fromIoc['value']);
    }
}

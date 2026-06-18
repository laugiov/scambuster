<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IocNormalizer
 *
 * Tests normalization rules for all supported IOC types (40+ types from Sprint 3 spec)
 */
class IocNormalizerTest extends TestCase
{
    private IocNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new IocNormalizer();
    }

    // Email & Headers

    public function testNormalizeEmail(): void
    {
        $this->assertSame('user@example.com', $this->normalizer->normalize('email', 'USER@EXAMPLE.COM'));
        $this->assertSame('test@example.com', $this->normalizer->normalize('email', '  test@example.com  '));
        $this->assertSame('test+tag@example.com', $this->normalizer->normalize('email', 'Test+Tag@Example.Com'));
    }

    public function testNormalizeAuthenticationResults(): void
    {
        $this->assertSame('pass', $this->normalizer->normalize('spf_result', 'PASS'));
        $this->assertSame('fail', $this->normalizer->normalize('dkim_result', 'FAIL'));
        $this->assertSame('none', $this->normalizer->normalize('dmarc_result', 'NONE'));
    }

    // Infrastructure

    public function testNormalizeUrl(): void
    {
        // Defang + lowercase + remove trailing slash
        $this->assertSame(
            'hxxps://example[.]com/path',
            $this->normalizer->normalize('url', 'https://example.com/path/')
        );

        $this->assertSame(
            'hxxp://malicious[.]site[.]com',
            $this->normalizer->normalize('url', 'http://malicious.site.com')
        );

        $this->assertSame(
            'hxxps://subdomain[.]example[.]com/path?query=value',
            $this->normalizer->normalize('url', 'https://subdomain.example.com/path?query=value')
        );
    }

    public function testNormalizeDomain(): void
    {
        // Lowercase + defang
        $this->assertSame('example[.]com', $this->normalizer->normalize('domain', 'Example.Com'));
        $this->assertSame('malicious[.]site[.]org', $this->normalizer->normalize('domain', 'malicious.site.org'));
    }

    public function testNormalizeIp(): void
    {
        // IPv4: canonical format
        $this->assertSame('192.168.1.1', $this->normalizer->normalize('ipv4', '192.168.1.1'));

        // IPv6: expanded format
        $ipv6 = $this->normalizer->normalize('ipv6', '::1');
        $this->assertNotEmpty($ipv6);  // Should expand ::1 to full form
    }

    // Hashes

    public function testNormalizeHashes(): void
    {
        // Lowercase hex
        $this->assertSame(
            'd41d8cd98f00b204e9800998ecf8427e',
            $this->normalizer->normalize('md5', 'D41D8CD98F00B204E9800998ECF8427E')
        );

        $this->assertSame(
            'da39a3ee5e6b4b0d3255bfef95601890afd80709',
            $this->normalizer->normalize('sha1', 'DA39A3EE5E6B4B0D3255BFEF95601890AFD80709')
        );

        $this->assertSame(
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            $this->normalizer->normalize('sha256', 'E3B0C44298FC1C149AFBF4C8996FB92427AE41E4649B934CA495991B7852B855')
        );
    }

    // Finance

    public function testNormalizeIban(): void
    {
        // Remove spaces + uppercase
        $this->assertSame(
            'FR7612345678901234567890185',
            $this->normalizer->normalize('iban', 'fr76 1234 5678 9012 3456 7890 185')
        );

        $this->assertSame(
            'DE89370400440532013000',
            $this->normalizer->normalize('iban', 'de89 3704 0044 0532 0130 00')
        );
    }

    public function testNormalizeBic(): void
    {
        // Uppercase
        $this->assertSame('BNPAFRPP', $this->normalizer->normalize('bic', 'bnpafrpp'));
        $this->assertSame('BNPAFRPPXXX', $this->normalizer->normalize('bic', 'bnpafrppxxx'));
    }

    public function testNormalizeWallets(): void
    {
        // BTC: case sensitive (as-is)
        $btcAddress = '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa';
        $this->assertSame($btcAddress, $this->normalizer->normalize('wallet_btc', $btcAddress));

        // ETH: case sensitive (as-is)
        $ethAddress = '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb';
        $this->assertSame($ethAddress, $this->normalizer->normalize('wallet_eth', $ethAddress));

        // XMR: case sensitive (as-is)
        $xmrAddress = '48uVVPPuYk5p3ZRXiXjjjDFd5p1mAjXoN1xYqXQbSRVN8hXQx8HdFm9c9s1xYqXQbSRVN8hXQx8HdFm9c9s1xYqXQbSRVN8hXQx8HdFm9c9';
        $this->assertSame($xmrAddress, $this->normalizer->normalize('wallet_xmr', $xmrAddress));
    }

    public function testNormalizeCreditCard(): void
    {
        // Remove spaces and hyphens
        $this->assertSame('4532015112830366', $this->normalizer->normalize('credit_card', '4532 0151 1283 0366'));
        $this->assertSame('5425233430109903', $this->normalizer->normalize('credit_card', '5425-2334-3010-9903'));
    }

    // Contact channels

    public function testNormalizePhone(): void
    {
        // Remove spaces, hyphens, dots, parentheses
        $this->assertSame('+33612345678', $this->normalizer->normalize('phone', '+33 6 12 34 56 78'));
        $this->assertSame('5551234567', $this->normalizer->normalize('phone', '(555) 123-4567'));  // No leading +, so it's preserved as-is
        $this->assertSame('0612345678', $this->normalizer->normalize('phone', '06.12.34.56.78'));
        $this->assertSame('+15551234567', $this->normalizer->normalize('phone', '+1 (555) 123-4567'));  // With leading +
    }

    public function testNormalizeSocialUsernames(): void
    {
        // Lowercase
        $this->assertSame('@username', $this->normalizer->normalize('telegram_username', '@USERNAME'));
        $this->assertSame('user#1234', $this->normalizer->normalize('discord_username', 'USER#1234'));
        $this->assertSame('skype_user', $this->normalizer->normalize('skype_id', 'Skype_User'));
    }

    // Security identifiers

    public function testNormalizeCve(): void
    {
        // Uppercase
        $this->assertSame('CVE-2021-44228', $this->normalizer->normalize('cve', 'cve-2021-44228'));
        $this->assertSame('CVE-2023-12345', $this->normalizer->normalize('cve', 'CVE-2023-12345'));
    }

    public function testNormalizeMitreAttackId(): void
    {
        // Uppercase
        $this->assertSame('T1566', $this->normalizer->normalize('mitre_attack_id', 't1566'));
        $this->assertSame('T1566.001', $this->normalizer->normalize('mitre_attack_id', 't1566.001'));
    }

    // Files

    public function testNormalizeMimetype(): void
    {
        // Lowercase
        $this->assertSame('application/pdf', $this->normalizer->normalize('mimetype', 'Application/PDF'));
        $this->assertSame('image/png', $this->normalizer->normalize('mimetype', 'IMAGE/PNG'));
    }

    public function testNormalizeFilename(): void
    {
        // Keep as-is (case sensitive)
        $this->assertSame('Document.PDF', $this->normalizer->normalize('filename', 'Document.PDF'));
        $this->assertSame('invoice.xlsx', $this->normalizer->normalize('filename', 'invoice.xlsx'));
    }

    // Helper methods

    public function testDefang(): void
    {
        $this->assertSame('hxxp://example[.]com', $this->normalizer->defang('http://example.com'));
        $this->assertSame('hxxps://malicious[.]site[.]org', $this->normalizer->defang('https://malicious.site.org'));
        $this->assertSame('evil[.]com', $this->normalizer->defang('evil.com'));
    }

    public function testRefang(): void
    {
        $this->assertSame('http://example.com', $this->normalizer->refang('hxxp://example[.]com'));
        $this->assertSame('https://malicious.site.org', $this->normalizer->refang('hxxps://malicious[.]site[.]org'));
        $this->assertSame('evil.com', $this->normalizer->refang('evil[.]com'));
    }

    public function testDefangRefangRoundtrip(): void
    {
        $original = 'https://example.com/path';
        $defanged = $this->normalizer->defang($original);
        $refanged = $this->normalizer->refang($defanged);

        $this->assertSame('hxxps://example[.]com/path', $defanged);
        $this->assertSame($original, $refanged);
    }

    // Keep as-is types

    public function testNormalizeKeepAsIsTypes(): void
    {
        // Message ID: keep as-is
        $messageId = '<123456@example.com>';
        $this->assertSame($messageId, $this->normalizer->normalize('message_id', $messageId));

        // Subject: keep as-is
        $subject = 'URGENT: Action Required';
        $this->assertSame($subject, $this->normalizer->normalize('subject', $subject));

        // X-Mailer: keep as-is
        $xMailer = 'Microsoft Outlook 16.0';
        $this->assertSame($xMailer, $this->normalizer->normalize('x_mailer', $xMailer));

        // Registrar: keep as-is
        $registrar = 'GoDaddy.com, LLC';
        $this->assertSame($registrar, $this->normalizer->normalize('registrar', $registrar));

        // Malware family: keep as-is
        $malwareFamily = 'Emotet';
        $this->assertSame($malwareFamily, $this->normalizer->normalize('malware_family', $malwareFamily));
    }

    public function testNormalizeTrimsWhitespace(): void
    {
        $this->assertSame('test@example.com', $this->normalizer->normalize('email', '  test@example.com  '));
        $this->assertSame('example[.]com', $this->normalizer->normalize('domain', '  example.com  '));
        $this->assertSame('CVE-2021-44228', $this->normalizer->normalize('cve', '  CVE-2021-44228  '));
    }

    /**
     * Spec 109 — postal address normalization: lowercase + collapse
     * whitespace + strip trailing punctuation. Two messy variants of
     * the same address must dedup to the same canonical string.
     */
    public function testNormalizePostalAddress(): void
    {
        $a = $this->normalizer->normalize(
            'postal_address',
            "Plot No 1 & 2, Mamram Towers,  New Delhi 110096.",
        );
        $b = $this->normalizer->normalize(
            'postal_address',
            "plot no 1 & 2,  Mamram Towers,\tNew Delhi 110096",
        );

        $this->assertSame('plot no 1 & 2, mamram towers, new delhi 110096', $a);
        $this->assertSame($a, $b, 'Variants of the same address must dedup to one canonical form.');
    }

    public function testNormalizePostalAddressHandlesMultiline(): void
    {
        $multiline = "123 Main Street\nSpringfield, IL 62701\nUSA";
        $normalized = $this->normalizer->normalize('postal_address', $multiline);

        $this->assertSame('123 main street springfield, il 62701 usa', $normalized);
    }
}

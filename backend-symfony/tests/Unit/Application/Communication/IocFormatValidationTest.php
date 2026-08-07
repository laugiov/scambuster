<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocValidator;
use PHPUnit\Framework\TestCase;

/**
 * Epistemic validation: IOC format validation tests.
 *
 * Tests IocValidator::IOC_PATTERNS regex patterns against known-good
 * and known-bad values for each critical IOC type.
 *
 * @covers \App\Application\Communication\IocValidator
 */
final class IocFormatValidationTest extends TestCase
{
    private IocValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new IocValidator();
    }

    // ================================================================== //
    //  IBAN
    // ================================================================== //

    public function testIbanValidGerman(): void
    {
        $this->assertTrue(
            $this->validator->validate('iban', 'DE89370400440532013000'),
            'Valid German IBAN should pass',
        );
    }

    public function testIbanValidFrench(): void
    {
        $this->assertTrue(
            $this->validator->validate('iban', 'FR1420041010050500013M02606'),
            'Valid French IBAN should pass',
        );
    }

    public function testIbanInvalidAllZeroChecksum(): void
    {
        // Structurally well-formed but the ISO 7064 mod-97 check fails — a
        // fabricated IBAN must be rejected, not accepted on format alone.
        $this->assertFalse(
            $this->validator->validate('iban', 'DE00000000000000000000'),
            'IBAN with an invalid mod-97 checksum must be rejected',
        );
    }

    public function testIbanInvalidNoCountryCode(): void
    {
        $this->assertFalse(
            $this->validator->validate('iban', '1234567890'),
            'IBAN without country code prefix should fail',
        );
    }

    public function testIbanInvalidTooShort(): void
    {
        $this->assertFalse(
            $this->validator->validate('iban', 'DE89'),
            'IBAN with only country code + check digits should fail',
        );
    }

    // ================================================================== //
    //  Phone (E.164)
    // ================================================================== //

    public function testPhoneValidE164(): void
    {
        $this->assertTrue(
            $this->validator->validate('phone', '+33612345678'),
            'Valid E.164 phone number should pass',
        );
    }

    public function testPhoneValidWithSpaces(): void
    {
        $this->assertTrue(
            $this->validator->validate('phone', '+33 6 12 34 56 78'),
            'E.164 with spaces should pass (regex allows spaces)',
        );
    }

    public function testPhoneValidLocalFormat(): void
    {
        // The phone regex is intentionally loose: /^[\d\s\+\(\)\-\.]{7,20}$/
        // It accepts local formats (no country code) — this is by design
        // to avoid rejecting valid IOC observations from email bodies.
        $this->assertTrue(
            $this->validator->validate('phone', '0612345678'),
            'Local phone without country code passes (regex is intentionally loose)',
        );
    }

    public function testPhoneInvalidTooShort(): void
    {
        $this->assertFalse(
            $this->validator->validate('phone', '123'),
            'Phone number under 7 chars should fail',
        );
    }

    public function testPhoneInvalidTooLong(): void
    {
        $this->assertFalse(
            $this->validator->validate('phone', '+33612345678901234567890'),
            'Phone number over 20 chars should fail',
        );
    }

    // ================================================================== //
    //  BTC Wallet (bech32)
    // ================================================================== //

    public function testBtcValidBech32(): void
    {
        $this->assertTrue(
            $this->validator->validate('wallet_btc', 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4'),
            'Valid bech32 BTC address should pass',
        );
    }

    public function testBtcValidLegacyP2pkh(): void
    {
        $this->assertTrue(
            $this->validator->validate('wallet_btc', '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa'),
            'Valid legacy (1-prefix) BTC address should pass',
        );
    }

    public function testBtcValidP2sh(): void
    {
        $this->assertTrue(
            $this->validator->validate('wallet_btc', '3J98t1WpEZ73CNmQviecrnyiWrnqRhWNLy'),
            'Valid P2SH (3-prefix) BTC address should pass',
        );
    }

    public function testBtcInvalid(): void
    {
        $this->assertFalse(
            $this->validator->validate('wallet_btc', 'bc1invalid'),
            'Invalid short bech32 address should fail',
        );
    }

    public function testBtcInvalidPrefix(): void
    {
        $this->assertFalse(
            $this->validator->validate('wallet_btc', 'invalid-btc-address'),
            'BTC address without valid prefix should fail',
        );
    }

    // ================================================================== //
    //  ETH Wallet
    // ================================================================== //

    public function testEthValid(): void
    {
        $this->assertTrue(
            $this->validator->validate('wallet_eth', '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed'),
            'Valid 42-char ETH address should pass',
        );
    }

    public function testEthInvalidTooShort(): void
    {
        $this->assertFalse(
            $this->validator->validate('wallet_eth', '0xshort'),
            'ETH address under 42 chars should fail',
        );
    }

    public function testEthInvalidNoPrefix(): void
    {
        $this->assertFalse(
            $this->validator->validate('wallet_eth', '742d35Cc6634C0532925a3b844Bc9e7595f2bD21'),
            'ETH address without 0x prefix should fail',
        );
    }

    // ================================================================== //
    //  SHA256
    // ================================================================== //

    public function testSha256Valid(): void
    {
        $hash = str_repeat('a', 64);
        $this->assertTrue(
            $this->validator->validate('sha256', $hash),
            'Valid 64-char hex string should pass as SHA256',
        );
    }

    public function testSha256ValidMixedCase(): void
    {
        $this->assertTrue(
            $this->validator->validate('sha256', 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'),
            'Known empty-string SHA256 should pass',
        );
    }

    public function testSha256Invalid63Chars(): void
    {
        $hash = str_repeat('a', 63);
        $this->assertFalse(
            $this->validator->validate('sha256', $hash),
            '63-char hex string should fail SHA256 validation',
        );
    }

    public function testSha256Invalid65Chars(): void
    {
        $hash = str_repeat('a', 65);
        $this->assertFalse(
            $this->validator->validate('sha256', $hash),
            '65-char hex string should fail SHA256 validation',
        );
    }

    public function testSha256InvalidNonHex(): void
    {
        $hash = str_repeat('g', 64);
        $this->assertFalse(
            $this->validator->validate('sha256', $hash),
            'Non-hex 64-char string should fail SHA256 validation',
        );
    }

    // ================================================================== //
    //  Email
    // ================================================================== //

    public function testEmailValid(): void
    {
        $this->assertTrue(
            $this->validator->validate('email', 'test@domain.com'),
            'Simple valid email should pass',
        );
    }

    public function testEmailValidComplex(): void
    {
        $this->assertTrue(
            $this->validator->validate('email', 'user.name+tag@subdomain.example.co.uk'),
            'Complex valid email should pass',
        );
    }

    public function testEmailInvalidNoDomain(): void
    {
        $this->assertFalse(
            $this->validator->validate('email', '@nodomain'),
            'Email without local part should fail',
        );
    }

    public function testEmailInvalidNoAt(): void
    {
        $this->assertFalse(
            $this->validator->validate('email', 'plaintext'),
            'Email without @ should fail',
        );
    }

    // ================================================================== //
    //  URL
    // ================================================================== //

    public function testUrlValidHttps(): void
    {
        $this->assertTrue(
            $this->validator->validate('url', 'https://evil.test/phish'),
            'Valid HTTPS URL should pass',
        );
    }

    public function testUrlValidHttp(): void
    {
        $this->assertTrue(
            $this->validator->validate('url', 'http://evil.test/phish?param=1'),
            'Valid HTTP URL with query should pass',
        );
    }

    public function testUrlValidWww(): void
    {
        $this->assertTrue(
            $this->validator->validate('url', 'www.evil.test/phish'),
            'URL starting with www. should pass',
        );
    }

    public function testUrlInvalidNoScheme(): void
    {
        $this->assertFalse(
            $this->validator->validate('url', 'notaurl'),
            'String without scheme or www prefix should fail',
        );
    }

    public function testUrlInvalidFtp(): void
    {
        $this->assertFalse(
            $this->validator->validate('url', 'ftp://example.com'),
            'FTP URL should fail (only http/https/www accepted)',
        );
    }

    // ================================================================== //
    //  Domain
    // ================================================================== //

    public function testDomainValid(): void
    {
        $this->assertTrue(
            $this->validator->validate('domain', 'evil.test'),
            'Valid two-part domain should pass',
        );
    }

    public function testDomainValidSubdomain(): void
    {
        $this->assertTrue(
            $this->validator->validate('domain', 'sub.evil.test'),
            'Valid subdomain should pass',
        );
    }

    public function testDomainInvalidNoDot(): void
    {
        // Single-label domains without TLD fail the regex
        $this->assertFalse(
            $this->validator->validate('domain', 'nodomain'),
            'Single-label domain without TLD should fail',
        );
    }

    public function testDomainInvalidLeadingDot(): void
    {
        $this->assertFalse(
            $this->validator->validate('domain', '.nodomain'),
            'Domain with leading dot should fail',
        );
    }

    // ================================================================== //
    //  Fixture data validation
    // ================================================================== //

    /**
     * Validates that all IOC types used in ObservedIocFixtures are
     * recognized by IocValidator. The fixture uses an indicator_id
     * reference (not a typed IOC value), so we verify the validator
     * supports the types commonly used in the system.
     */
    public function testValidatorSupportsAllCommonIocTypes(): void
    {
        $commonTypes = [
            'email', 'url', 'domain', 'ipv4', 'ipv6', 'phone',
            'iban', 'bic', 'wallet_btc', 'wallet_eth', 'wallet_xmr',
            'sha256', 'sha1', 'md5', 'cve', 'mitre_attack_id',
            'credit_card', 'telegram_username', 'discord_username',
        ];

        foreach ($commonTypes as $type) {
            $this->assertTrue(
                $this->validator->isSupportedType($type),
                "IOC type '{$type}' should be supported by IocValidator",
            );
        }
    }

    /**
     * Validates representative known-good IOC values pass format validation.
     *
     * @dataProvider knownGoodIocProvider
     */
    public function testKnownGoodIocPassesValidation(string $type, string $value): void
    {
        $this->assertTrue(
            $this->validator->validate($type, $value),
            "Known-good IOC ({$type}: {$value}) should pass validation",
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function knownGoodIocProvider(): iterable
    {
        yield 'email_simple' => ['email', 'scammer@evil.test'];
        yield 'url_https' => ['url', 'https://phishing.test/login'];
        yield 'domain_simple' => ['domain', 'evil.test'];
        yield 'ipv4_public' => ['ipv4', '203.0.113.1'];
        yield 'iban_gb' => ['iban', 'GB29NWBK60161331926819'];
        yield 'wallet_btc_legacy' => ['wallet_btc', '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa'];
        yield 'wallet_eth_full' => ['wallet_eth', '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed'];
        yield 'sha256_known' => ['sha256', 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'];
        yield 'phone_international' => ['phone', '+441234567890'];
        yield 'cve_log4shell' => ['cve', 'CVE-2021-44228'];
        yield 'mitre_phishing' => ['mitre_attack_id', 'T1566.001'];
    }
}

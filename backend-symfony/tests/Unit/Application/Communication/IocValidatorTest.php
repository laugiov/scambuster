<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IocValidator
 *
 * Tests validation rules for all supported IOC types (40+ types from Sprint 3 spec)
 */
class IocValidatorTest extends TestCase
{
    private IocValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new IocValidator();
    }

    // Email & Headers

    public function testValidateEmail(): void
    {
        $this->assertTrue($this->validator->validate('email', 'user@example.com'));
        $this->assertTrue($this->validator->validate('email', 'test.user+tag@subdomain.example.co.uk'));
        $this->assertFalse($this->validator->validate('email', 'invalid'));
        $this->assertFalse($this->validator->validate('email', '@example.com'));
        $this->assertFalse($this->validator->validate('email', 'user@'));
    }

    public function testValidateMessageId(): void
    {
        $this->assertTrue($this->validator->validate('message_id', '<123456@example.com>'));
        $this->assertTrue($this->validator->validate('message_id', '<CABcDef123@mail.gmail.com>'));
        $this->assertFalse($this->validator->validate('message_id', '123456@example.com'));
        $this->assertFalse($this->validator->validate('message_id', '<>'));
    }

    public function testValidateSpfDkimDmarcResults(): void
    {
        $this->assertTrue($this->validator->validate('spf_result', 'pass'));
        $this->assertTrue($this->validator->validate('spf_result', 'fail'));
        $this->assertTrue($this->validator->validate('spf_result', 'softfail'));
        $this->assertFalse($this->validator->validate('spf_result', 'invalid'));

        $this->assertTrue($this->validator->validate('dkim_result', 'pass'));
        $this->assertTrue($this->validator->validate('dkim_result', 'fail'));
        $this->assertFalse($this->validator->validate('dkim_result', 'invalid'));

        $this->assertTrue($this->validator->validate('dmarc_result', 'pass'));
        $this->assertTrue($this->validator->validate('dmarc_result', 'fail'));
        $this->assertTrue($this->validator->validate('dmarc_result', 'none'));
        $this->assertFalse($this->validator->validate('dmarc_result', 'invalid'));
    }

    // Infrastructure

    public function testValidateIpv4(): void
    {
        $this->assertTrue($this->validator->validate('ipv4', '192.168.1.1'));
        $this->assertTrue($this->validator->validate('ipv4', '10.0.0.1'));
        $this->assertTrue($this->validator->validate('ipv4', '255.255.255.255'));
        $this->assertFalse($this->validator->validate('ipv4', '256.1.1.1'));
        $this->assertFalse($this->validator->validate('ipv4', '192.168'));
        $this->assertFalse($this->validator->validate('ipv4', 'not-an-ip'));
    }

    public function testValidateIpv6(): void
    {
        $this->assertTrue($this->validator->validate('ipv6', '2001:0db8:85a3:0000:0000:8a2e:0370:7334'));
        $this->assertTrue($this->validator->validate('ipv6', '::1'));
        $this->assertTrue($this->validator->validate('ipv6', 'fe80::'));
        $this->assertFalse($this->validator->validate('ipv6', '192.168.1.1'));
        $this->assertFalse($this->validator->validate('ipv6', 'not-an-ip'));
    }

    public function testValidateUrl(): void
    {
        $this->assertTrue($this->validator->validate('url', 'https://example.com'));
        $this->assertTrue($this->validator->validate('url', 'http://subdomain.example.com/path?query=value'));
        $this->assertTrue($this->validator->validate('url', 'www.example.com'));
        $this->assertFalse($this->validator->validate('url', 'not-a-url'));
        $this->assertFalse($this->validator->validate('url', 'ftp://example.com'));  // Only http/https/www
    }

    public function testValidateDomain(): void
    {
        $this->assertTrue($this->validator->validate('domain', 'example.com'));
        $this->assertTrue($this->validator->validate('domain', 'subdomain.example.co.uk'));
        $this->assertFalse($this->validator->validate('domain', 'invalid'));
        $this->assertFalse($this->validator->validate('domain', '.com'));
    }

    // Hashes

    public function testValidateMd5(): void
    {
        $this->assertTrue($this->validator->validate('md5', 'd41d8cd98f00b204e9800998ecf8427e'));
        $this->assertTrue($this->validator->validate('md5', 'D41D8CD98F00B204E9800998ECF8427E'));  // Case insensitive
        $this->assertFalse($this->validator->validate('md5', 'd41d8cd98f00b204e9800998ecf8427'));  // Too short
        $this->assertFalse($this->validator->validate('md5', 'not-a-hash'));
    }

    public function testValidateSha1(): void
    {
        $this->assertTrue($this->validator->validate('sha1', 'da39a3ee5e6b4b0d3255bfef95601890afd80709'));
        $this->assertFalse($this->validator->validate('sha1', 'da39a3ee5e6b4b0d3255bfef95601890'));  // Too short
    }

    public function testValidateSha256(): void
    {
        $this->assertTrue($this->validator->validate('sha256', 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'));
        $this->assertFalse($this->validator->validate('sha256', 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b8'));  // Too short
    }

    // Finance

    public function testValidateIban(): void
    {
        $this->assertTrue($this->validator->validate('iban', 'FR7612345678901234567890185'));
        $this->assertTrue($this->validator->validate('iban', 'DE89370400440532013000'));
        $this->assertFalse($this->validator->validate('iban', 'FR76'));  // Too short
        $this->assertFalse($this->validator->validate('iban', '1234567890'));  // No country code
    }

    public function testValidateBic(): void
    {
        $this->assertTrue($this->validator->validate('bic', 'BNPAFRPP'));  // 8 chars
        $this->assertTrue($this->validator->validate('bic', 'BNPAFRPPXXX'));  // 11 chars
        $this->assertFalse($this->validator->validate('bic', 'BNPA'));  // Too short
        $this->assertFalse($this->validator->validate('bic', 'bnpafrpp'));  // Lowercase
    }

    public function testValidateWalletBtc(): void
    {
        $this->assertTrue($this->validator->validate('wallet_btc', '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa'));
        $this->assertTrue($this->validator->validate('wallet_btc', '3J98t1WpEZ73CNmYviecrnyiWrnqRhWNLy'));
        $this->assertTrue($this->validator->validate('wallet_btc', 'bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq'));
        $this->assertFalse($this->validator->validate('wallet_btc', 'invalid-btc-address'));
    }

    public function testValidateWalletEth(): void
    {
        $this->assertTrue($this->validator->validate('wallet_eth', '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb0'));  // 40 hex chars
        $this->assertFalse($this->validator->validate('wallet_eth', '742d35Cc6634C0532925a3b844Bc9e7595f0bEb0'));  // Missing 0x
        $this->assertFalse($this->validator->validate('wallet_eth', '0x742d35Cc'));  // Too short
    }

    public function testValidateCreditCard(): void
    {
        // Valid Luhn checksum
        $this->assertTrue($this->validator->validate('credit_card', '4532015112830366'));  // Visa test card
        $this->assertTrue($this->validator->validate('credit_card', '5425233430109903'));  // Mastercard test card

        // Invalid Luhn checksum
        $this->assertFalse($this->validator->validate('credit_card', '1234567890123456'));

        // With spaces/hyphens (should be normalized before validation, but validator handles it)
        $this->assertTrue($this->validator->validate('credit_card', '4532 0151 1283 0366'));
    }

    // Contact channels

    public function testValidatePhone(): void
    {
        $this->assertTrue($this->validator->validate('phone', '+33612345678'));
        $this->assertTrue($this->validator->validate('phone', '06 12 34 56 78'));
        $this->assertTrue($this->validator->validate('phone', '(555) 123-4567'));
        $this->assertFalse($this->validator->validate('phone', '123'));  // Too short
    }

    public function testValidateTelegramUsername(): void
    {
        $this->assertTrue($this->validator->validate('telegram_username', '@username'));
        $this->assertTrue($this->validator->validate('telegram_username', '@test_user123'));
        $this->assertFalse($this->validator->validate('telegram_username', 'username'));  // Missing @
        $this->assertFalse($this->validator->validate('telegram_username', '@abc'));  // Too short (< 5 chars)
    }

    public function testValidateDiscordUsername(): void
    {
        $this->assertTrue($this->validator->validate('discord_username', 'user#1234'));  // Old format
        $this->assertTrue($this->validator->validate('discord_username', 'username'));  // New format
        $this->assertFalse($this->validator->validate('discord_username', 'u'));  // Too short
    }

    // Security identifiers

    public function testValidateCve(): void
    {
        $this->assertTrue($this->validator->validate('cve', 'CVE-2021-44228'));  // Log4Shell
        $this->assertTrue($this->validator->validate('cve', 'CVE-2023-12345'));
        $this->assertFalse($this->validator->validate('cve', 'CVE-2021'));  // Missing ID
        $this->assertFalse($this->validator->validate('cve', '2021-44228'));  // Missing CVE- prefix
    }

    public function testValidateMitreAttackId(): void
    {
        $this->assertTrue($this->validator->validate('mitre_attack_id', 'T1566'));  // Phishing
        $this->assertTrue($this->validator->validate('mitre_attack_id', 'T1566.001'));  // Spearphishing Attachment
        $this->assertFalse($this->validator->validate('mitre_attack_id', 'T156'));  // Wrong format
        $this->assertFalse($this->validator->validate('mitre_attack_id', '1566'));  // Missing T prefix
    }

    // Files

    public function testValidateMimetype(): void
    {
        $this->assertTrue($this->validator->validate('mimetype', 'application/pdf'));
        $this->assertTrue($this->validator->validate('mimetype', 'image/png'));
        $this->assertTrue($this->validator->validate('mimetype', 'text/html'));
        $this->assertFalse($this->validator->validate('mimetype', 'invalid'));
    }

    // Helper methods

    public function testGetSupportedTypes(): void
    {
        $types = $this->validator->getSupportedTypes();
        $this->assertIsArray($types);
        $this->assertContains('email', $types);
        $this->assertContains('ipv4', $types);
        $this->assertContains('url', $types);
        $this->assertContains('wallet_btc', $types);
        $this->assertContains('cve', $types);
        $this->assertGreaterThan(30, count($types));  // Should have 40+ types
    }

    public function testIsSupportedType(): void
    {
        $this->assertTrue($this->validator->isSupportedType('email'));
        $this->assertTrue($this->validator->isSupportedType('ipv4'));
        $this->assertTrue($this->validator->isSupportedType('wallet_btc'));
        $this->assertFalse($this->validator->isSupportedType('unknown_type'));
    }

    public function testValidateEmptyValue(): void
    {
        $this->assertFalse($this->validator->validate('email', ''));
        $this->assertFalse($this->validator->validate('email', '   '));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\MessageAnonymizer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Application\Communication\MessageAnonymizer
 */
class MessageAnonymizerTest extends TestCase
{
    private MessageAnonymizer $anonymizer;

    protected function setUp(): void
    {
        $this->anonymizer = new MessageAnonymizer();
    }

    public function testEmailReplaced(): void
    {
        $text = 'Please contact me at scammer@evil.com for details.';
        $result = $this->anonymizer->anonymize($text);

        $this->assertStringContainsString('[EMAIL]', $result);
        $this->assertStringNotContainsString('scammer@evil.com', $result);
    }

    public function testIbanReplaced(): void
    {
        $text = 'Send money to FR7630006000011234567890189 immediately.';
        $result = $this->anonymizer->anonymize($text);

        $this->assertStringContainsString('[IBAN]', $result);
        $this->assertStringNotContainsString('FR7630006000011234567890189', $result);
    }

    public function testPhoneReplaced(): void
    {
        $text = 'Call me at +33 6 12 34 56 78 for more info.';
        $result = $this->anonymizer->anonymize($text);

        $this->assertStringContainsString('[PHONE]', $result);
        $this->assertStringNotContainsString('+33 6 12 34 56 78', $result);
    }

    public function testBtcWalletReplaced(): void
    {
        $text = 'Pay to bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4 please.';
        $result = $this->anonymizer->anonymize($text);

        $this->assertStringContainsString('[WALLET]', $result);
        $this->assertStringNotContainsString('bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4', $result);
    }

    public function testEthWalletReplaced(): void
    {
        $text = 'Send ETH to 0x742d35Cc6634C0532925a3b844Bc9e7595f2bD80 now.';
        $result = $this->anonymizer->anonymize($text);

        $this->assertStringContainsString('[WALLET]', $result);
        $this->assertStringNotContainsString('0x742d35Cc6634C0532925a3b844Bc9e7595f2bD80', $result);
    }

    public function testUrlsKeptAsIs(): void
    {
        $text = 'Visit https://evil-phishing.com/login and enter your credentials.';
        $result = $this->anonymizer->anonymize($text);

        $this->assertStringContainsString('https://evil-phishing.com/login', $result);
    }

    public function testTextWithoutPiiUnchanged(): void
    {
        $text = 'Hello, I am interested in your offer. Please provide more details.';
        $result = $this->anonymizer->anonymize($text);

        $this->assertSame($text, $result);
    }

    public function testContainsPiiDetectsEmail(): void
    {
        $this->assertTrue($this->anonymizer->containsPii('Contact us at test@example.com'));
        $this->assertFalse($this->anonymizer->containsPii('No PII in this text'));
    }

    public function testMultiplePiiTypesReplaced(): void
    {
        $text = 'Email: victim@test.com, IBAN: DE89370400440532013000, Phone: +49 30 123456';
        $result = $this->anonymizer->anonymize($text);

        $this->assertStringContainsString('[EMAIL]', $result);
        $this->assertStringNotContainsString('victim@test.com', $result);
        $this->assertStringNotContainsString('DE89370400440532013000', $result);
    }
}

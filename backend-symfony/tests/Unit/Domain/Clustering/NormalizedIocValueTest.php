<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Clustering;

use App\Domain\Clustering\ValueObject\NormalizedIocValue;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IOC value normalization used in clustering.
 * Written FIRST (TDD red) — NormalizedIocValue class does not exist yet.
 */
final class NormalizedIocValueTest extends TestCase
{
    // ─── IBAN normalization ───

    public function testIbanWithSpacesEqualsWithout(): void
    {
        $withSpaces = NormalizedIocValue::normalize('iban', 'FR76 3000 6000 0112 3456 7890 189');
        $without = NormalizedIocValue::normalize('iban', 'FR7630006000011234567890189');

        $this->assertSame($withSpaces, $without);
    }

    public function testIbanWithDashesEqualsClean(): void
    {
        $withDashes = NormalizedIocValue::normalize('iban', 'FR76-3000-6000-0112-3456-7890-189');
        $clean = NormalizedIocValue::normalize('iban', 'FR7630006000011234567890189');

        $this->assertSame($withDashes, $clean);
    }

    public function testIbanUppercased(): void
    {
        $lower = NormalizedIocValue::normalize('iban', 'fr7630006000011234567890189');
        $this->assertSame('FR7630006000011234567890189', $lower);
    }

    // ─── Wallet normalization ───

    public function testEthWalletLowercased(): void
    {
        $mixed = NormalizedIocValue::normalize('wallet_eth', '0xABC123DEF456');
        $this->assertSame('0xabc123def456', $mixed);
    }

    public function testBtcWalletCaseSensitive(): void
    {
        $value = NormalizedIocValue::normalize('wallet_btc', '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa');
        $this->assertSame('1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa', $value);
    }

    public function testBtcWalletTrimmed(): void
    {
        $value = NormalizedIocValue::normalize('wallet_btc', '  1A1zP1eP5Q  ');
        $this->assertSame('1A1zP1eP5Q', $value);
    }

    public function testXmrWalletTrimmed(): void
    {
        $value = NormalizedIocValue::normalize('wallet_xmr', ' 4AdUndXHHZ6cfufTMvppY6JwXNouMBzSkbLYfpAV5Usx3 ');
        $this->assertSame('4AdUndXHHZ6cfufTMvppY6JwXNouMBzSkbLYfpAV5Usx3', $value);
    }

    // ─── Phone normalization ───

    public function testPhoneNormalized(): void
    {
        $phone = NormalizedIocValue::normalize('phone', '+33 6 12-34-56-78');
        $this->assertSame('+33612345678', $phone);
    }

    public function testPhoneStripsDashes(): void
    {
        $phone = NormalizedIocValue::normalize('phone', '+1-703-555-1324');
        $this->assertSame('+17035551324', $phone);
    }

    public function testPhoneStripsParens(): void
    {
        $phone = NormalizedIocValue::normalize('phone', '+1 (555) 123-4567');
        $this->assertSame('+15551234567', $phone);
    }

    // ─── BIC normalization ───

    public function testBicUppercased(): void
    {
        $bic = NormalizedIocValue::normalize('bic', 'nwbkgb2l');
        $this->assertSame('NWBKGB2L', $bic);
    }

    public function testBicTrimmed(): void
    {
        $bic = NormalizedIocValue::normalize('bic', '  BNPAFRPP  ');
        $this->assertSame('BNPAFRPP', $bic);
    }

    // ─── Bank account / credit card ───

    public function testBankAccountUppercased(): void
    {
        $value = NormalizedIocValue::normalize('bank_account', 'gb29nwbk60161331926819');
        $this->assertSame('GB29NWBK60161331926819', $value);
    }

    public function testCreditCardStripsSpaces(): void
    {
        $value = NormalizedIocValue::normalize('credit_card', '4111 1111 1111 1111');
        $this->assertSame('4111111111111111', $value);
    }

    // ─── Default normalization ───

    public function testDefaultLowercasesTrimmed(): void
    {
        $value = NormalizedIocValue::normalize('unknown_type', '  SomeValue  ');
        $this->assertSame('somevalue', $value);
    }

    // ─── Uniqueness ───

    public function testTwoDifferentValuesNeverEqual(): void
    {
        $a = NormalizedIocValue::normalize('iban', 'FR7630006000011234567890189');
        $b = NormalizedIocValue::normalize('iban', 'DE89370400440532013000');

        $this->assertNotSame($a, $b);
    }

    // ─── Hash ───

    public function testHashReturnsSha256(): void
    {
        $hash = NormalizedIocValue::hash('FR7630006000011234567890189');

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function testHashDeterministic(): void
    {
        $hash1 = NormalizedIocValue::hash('test_value');
        $hash2 = NormalizedIocValue::hash('test_value');

        $this->assertSame($hash1, $hash2);
    }

    public function testHashDifferentForDifferentValues(): void
    {
        $hash1 = NormalizedIocValue::hash('value_a');
        $hash2 = NormalizedIocValue::hash('value_b');

        $this->assertNotSame($hash1, $hash2);
    }
}

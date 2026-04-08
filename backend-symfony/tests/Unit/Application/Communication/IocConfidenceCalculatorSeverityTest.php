<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocConfidenceCalculator;
use PHPUnit\Framework\TestCase;

final class IocConfidenceCalculatorSeverityTest extends TestCase
{
    /**
     * @dataProvider highValueTypesProvider
     */
    public function testHighValueTypesAlwaysReturnHigh(string $type): void
    {
        $this->assertSame('HIGH', IocConfidenceCalculator::computeSeverity($type, 0, 0));
        $this->assertSame('HIGH', IocConfidenceCalculator::computeSeverity($type, 50, 0));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function highValueTypesProvider(): array
    {
        return [
            'iban' => ['iban'],
            'wallet_btc' => ['wallet_btc'],
            'wallet_eth' => ['wallet_eth'],
            'wallet_xmr' => ['wallet_xmr'],
            'phone' => ['phone'],
            'bic' => ['bic'],
            'bank_account' => ['bank_account'],
            'credit_card' => ['credit_card'],
        ];
    }

    /**
     * @dataProvider mediumValueTypesProvider
     */
    public function testMediumValueTypesReturnMediumWithoutEnrichment(string $type): void
    {
        $this->assertSame('MEDIUM', IocConfidenceCalculator::computeSeverity($type, 0, 0));
    }

    /**
     * @dataProvider mediumValueTypesProvider
     */
    public function testMediumValueTypesUpgradeToHighWithEnrichment(string $type): void
    {
        $this->assertSame('HIGH', IocConfidenceCalculator::computeSeverity($type, 5, 0));
        $this->assertSame('HIGH', IocConfidenceCalculator::computeSeverity($type, 0, 3));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function mediumValueTypesProvider(): array
    {
        return [
            'url' => ['url'],
            'domain' => ['domain'],
            'email' => ['email'],
            'ipv4' => ['ipv4'],
            'ipv6' => ['ipv6'],
            'sha256' => ['sha256'],
        ];
    }

    /**
     * @dataProvider lowValueTypesProvider
     */
    public function testMetadataTypesReturnLow(string $type): void
    {
        $this->assertSame('LOW', IocConfidenceCalculator::computeSeverity($type, 0, 0));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lowValueTypesProvider(): array
    {
        return [
            'subject' => ['subject'],
            'message_id' => ['message_id'],
            'dmarc_result' => ['dmarc_result'],
            'spf_result' => ['spf_result'],
            'unknown_type' => ['some_random_type'],
        ];
    }

    public function testCaseInsensitive(): void
    {
        $this->assertSame('HIGH', IocConfidenceCalculator::computeSeverity('WALLET_BTC', 0, 0));
        $this->assertSame('MEDIUM', IocConfidenceCalculator::computeSeverity('URL', 0, 0));
    }
}

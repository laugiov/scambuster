<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication;

use App\Domain\Communication\IocCategory;
use PHPUnit\Framework\TestCase;

/**
 * IocCategory pure-helper unit tests.
 *
 * Critical assertion: an UNKNOWN IOC type returns the explicit default
 * bucket ('other'), so future IOC types render with a sensible style
 * without code changes.
 */
final class IocCategoryTest extends TestCase
{
    public function testFinancialTypes_097S1(): void
    {
        foreach (['iban', 'bic', 'swift', 'bank_account', 'routing_number', 'wallet_btc', 'wallet_eth', 'wallet_xmr', 'wallet', 'credit_card'] as $type) {
            $this->assertSame(
                IocCategory::FINANCIAL,
                IocCategory::classify($type),
                "type '{$type}' must classify as financial",
            );
        }
    }

    public function testContactTypes_097S1(): void
    {
        foreach (['phone', 'email', 'whatsapp', 'telegram', 'skype', 'signal'] as $type) {
            $this->assertSame(IocCategory::CONTACT, IocCategory::classify($type));
        }
    }

    public function testInfrastructureTypes_097S1(): void
    {
        foreach (['url', 'domain', 'ipv4', 'ipv6', 'sha256', 'sha1', 'md5'] as $type) {
            $this->assertSame(IocCategory::INFRASTRUCTURE, IocCategory::classify($type));
        }
    }

    public function testUnknownTypeFallsBackToOtherBucket_097S1(): void
    {
        // CRITICAL: this guarantees future IOC types render gracefully.
        $this->assertSame(IocCategory::OTHER, IocCategory::classify('quantum_dna_signature'));
        $this->assertSame(IocCategory::OTHER, IocCategory::classify(''));
        $this->assertSame(IocCategory::OTHER, IocCategory::classify('   '));
    }

    public function testCaseInsensitiveAndTrimmed_097S1(): void
    {
        $this->assertSame(IocCategory::FINANCIAL, IocCategory::classify('BIC'));
        $this->assertSame(IocCategory::FINANCIAL, IocCategory::classify('  iban  '));
        $this->assertSame(IocCategory::CONTACT, IocCategory::classify('Phone'));
    }

    public function testAllConstantsAreDistinct_097S1(): void
    {
        $constants = [
            IocCategory::FINANCIAL,
            IocCategory::CONTACT,
            IocCategory::INFRASTRUCTURE,
            IocCategory::OTHER,
        ];
        $this->assertSame(count($constants), count(array_unique($constants)));
    }
}

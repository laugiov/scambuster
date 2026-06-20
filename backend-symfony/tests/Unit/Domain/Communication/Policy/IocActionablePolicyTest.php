<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication\Policy;

use App\Domain\Communication\Policy\IocActionablePolicy;
use PHPUnit\Framework\TestCase;

final class IocActionablePolicyTest extends TestCase
{
    /**
     * Spec 111 — pins the non-actionable list verbatim. The frontend
     * mirrors this list in `frontend-react/src/lib/iocActionable.ts`.
     * Any change here MUST be mirrored there in the same commit; the
     * frontend has a sibling parity test that flips the assertion.
     */
    public function testNonActionableTypesListIsPinned(): void
    {
        $expected = [
            // Header metadata
            'subject', 'message_id', 'x_mailer', 'return_path',
            // Auth results
            'spf_result', 'dkim_result', 'dmarc_result',
            // WHOIS metadata
            'whois_email', 'whois_registrar_name', 'registrar',
            // File metadata
            'filename', 'mimetype',
            // Reference identifiers
            'cve', 'malware_family', 'mitre_attack_id', 'tracking_number',
            // File hashes
            'md5', 'sha1', 'sha256',
        ];

        self::assertSame($expected, IocActionablePolicy::NON_ACTIONABLE_TYPES);
        self::assertSame($expected, IocActionablePolicy::nonActionableTypes());
    }

    public function testIsActionableReturnsFalseForEachNonActionableType(): void
    {
        foreach (IocActionablePolicy::NON_ACTIONABLE_TYPES as $type) {
            self::assertFalse(
                IocActionablePolicy::isActionable($type),
                sprintf('Type "%s" should be non-actionable', $type),
            );
        }
    }

    public function testIsActionableReturnsTrueForKnownActionableTypes(): void
    {
        // A representative sample of types that the threat model treats
        // as actionable: identifiers the scammer controls or financial
        // artefacts they use to extract value.
        $actionable = ['email', 'phone', 'iban', 'bic', 'url', 'domain',
            'ipv4', 'ipv6', 'wallet_btc', 'wallet_eth', 'wallet_xmr',
            'bank_account', 'credit_card', 'telegram_username',
            'discord_username', 'skype_id', 'postal_address'];

        foreach ($actionable as $type) {
            self::assertTrue(
                IocActionablePolicy::isActionable($type),
                sprintf('Type "%s" should be actionable', $type),
            );
        }
    }

    public function testIsActionableIsCaseInsensitive(): void
    {
        self::assertFalse(IocActionablePolicy::isActionable('Subject'));
        self::assertFalse(IocActionablePolicy::isActionable('MESSAGE_ID'));
        self::assertTrue(IocActionablePolicy::isActionable('IBAN'));
        self::assertTrue(IocActionablePolicy::isActionable('Email'));
    }

    public function testIsActionableReturnsTrueForUnknownTypes(): void
    {
        // Default-allow: unknown types are treated as actionable until
        // they're explicitly added to the non-actionable list.
        self::assertTrue(IocActionablePolicy::isActionable('totally_new_type'));
    }
}

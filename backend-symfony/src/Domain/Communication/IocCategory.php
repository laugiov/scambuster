<?php

declare(strict_types=1);

namespace App\Domain\Communication;

/**
 * Spec 097 — Pure helper mapping an IOC type string to a category bucket.
 *
 * The bucket is used by the Theater (and any future feature) to drive
 * categorical styling (financial highlighted, etc.) without leaking the
 * full IOC type taxonomy into the UI layer.
 *
 * Design constraint: **explicit default bucket**. A future IOC type added
 * to the platform must render with a sensible style without touching this
 * helper. The default bucket is `other`.
 */
final class IocCategory
{
    public const string FINANCIAL = 'financial';
    public const string CONTACT = 'contact';
    public const string INFRASTRUCTURE = 'infrastructure';
    public const string OTHER = 'other';

    /**
     * Map an IOC type string (e.g. 'bic', 'iban', 'phone', 'url') to its
     * category bucket. Case-insensitive, trimmed.
     */
    public static function classify(string $type): string
    {
        $normalized = strtolower(trim($type));

        return match ($normalized) {
            'iban',
            'bic',
            'swift',
            'bank_account',
            'routing_number',
            'wallet_btc',
            'wallet_eth',
            'wallet_xmr',
            'wallet',
            'credit_card' => self::FINANCIAL,
            'phone',
            'email',
            'whatsapp',
            'telegram',
            'skype',
            'signal' => self::CONTACT,
            'url',
            'domain',
            'ipv4',
            'ipv6',
            'sha256',
            'sha1',
            'md5' => self::INFRASTRUCTURE,
            default => self::OTHER,
        };
    }
}

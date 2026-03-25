<?php

declare(strict_types=1);

namespace App\Application\Communication;

/**
 * Half-life configuration for IOC temporal decay.
 *
 * Each IOC type has a half-life in days — the time after which
 * the IOC's effective score drops to 50% of its confidence.
 * Shorter half-lives for rapidly-rotating infrastructure (IPs, URLs),
 * longer for persistent indicators (hashes, financial IOCs).
 */
final class IocDecayConfig
{
    /** @var array<string, int> IOC type → half-life in days */
    private const HALF_LIVES = [
        'url'          => 14,
        'ipv4'         => 7,
        'ipv6'         => 7,
        'domain'       => 30,
        'email'        => 60,
        'whois_email'  => 60,
        'phone'        => 90,
        'iban'         => 180,
        'bic'          => 180,
        'wallet_btc'   => 180,
        'wallet_eth'   => 180,
        'wallet_xmr'   => 180,
        'bank_account' => 180,
        'credit_card'  => 180,
        'sha256'       => 365,
        'sha1'         => 365,
        'md5'          => 365,
        'filename'     => 90,
        'subject'      => 14,
        'message_id'   => 14,
        'registrar'    => 60,
    ];

    private const DEFAULT_HALF_LIFE = 30;

    public static function getHalfLifeDays(string $type): int
    {
        return self::HALF_LIVES[strtolower($type)] ?? self::DEFAULT_HALF_LIFE;
    }

    /**
     * @return array<string, int>
     */
    public static function getAllHalfLives(): array
    {
        return self::HALF_LIVES;
    }

    public static function getDefaultHalfLife(): int
    {
        return self::DEFAULT_HALF_LIFE;
    }
}

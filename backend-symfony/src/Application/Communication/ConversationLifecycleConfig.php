<?php

declare(strict_types=1);

namespace App\Application\Communication;

/**
 * Per-scam-type conversation lifecycle policies.
 *
 * Defines timeout, max turns, max duration, and reopen rules
 * for each scam category. Used by CloseStaleConversationsCommand
 * and IngestHandler (reopen window).
 *
 * Stored as PHP constants (not DB) because the number of scam types
 * is small and fixed. No CRUD needed.
 */
final class ConversationLifecycleConfig
{
    /**
     * @var array<string, array{timeout_hours: int, max_turns: int, max_duration_days: int, allow_reopen: bool, reopen_window_hours: int}>
     */
    private const POLICIES = [
        // Long-duration scams: high engagement, slow trust-building
        'ROMANCE'           => ['timeout_hours' => 336, 'max_turns' => 50, 'max_duration_days' => 60, 'allow_reopen' => true,  'reopen_window_hours' => 72],
        'ROMANCE_SCAM'      => ['timeout_hours' => 336, 'max_turns' => 50, 'max_duration_days' => 60, 'allow_reopen' => true,  'reopen_window_hours' => 72], // alias, kept for backward compat
        'INVESTMENT'        => ['timeout_hours' => 168, 'max_turns' => 40, 'max_duration_days' => 45, 'allow_reopen' => true,  'reopen_window_hours' => 48],

        // Medium-duration scams: business/financial pretexts
        'INVOICE_FRAUD'     => ['timeout_hours' => 72,  'max_turns' => 30, 'max_duration_days' => 21, 'allow_reopen' => false, 'reopen_window_hours' => 0],
        'CEO_FRAUD'         => ['timeout_hours' => 120, 'max_turns' => 25, 'max_duration_days' => 14, 'allow_reopen' => false, 'reopen_window_hours' => 0],
        'ADVANCE_FEE_419'   => ['timeout_hours' => 168, 'max_turns' => 40, 'max_duration_days' => 30, 'allow_reopen' => true,  'reopen_window_hours' => 48],

        // Short-duration scams: fast hit-and-run
        'PHISHING'          => ['timeout_hours' => 48,  'max_turns' => 15, 'max_duration_days' => 7,  'allow_reopen' => false, 'reopen_window_hours' => 0],
        'PHISH_CREDENTIALS' => ['timeout_hours' => 48,  'max_turns' => 15, 'max_duration_days' => 7,  'allow_reopen' => false, 'reopen_window_hours' => 0],
        'PHISH_MALWARE'     => ['timeout_hours' => 48,  'max_turns' => 15, 'max_duration_days' => 7,  'allow_reopen' => false, 'reopen_window_hours' => 0],
        'TECH_SUPPORT'      => ['timeout_hours' => 24,  'max_turns' => 20, 'max_duration_days' => 5,  'allow_reopen' => false, 'reopen_window_hours' => 0],

        // Casual/opportunistic scams: moderate engagement
        'LOTTERY'           => ['timeout_hours' => 72,  'max_turns' => 25, 'max_duration_days' => 14, 'allow_reopen' => false, 'reopen_window_hours' => 0],
        'JOB_OFFER'         => ['timeout_hours' => 72,  'max_turns' => 25, 'max_duration_days' => 14, 'allow_reopen' => false, 'reopen_window_hours' => 0],
        'CHARITY'           => ['timeout_hours' => 72,  'max_turns' => 25, 'max_duration_days' => 14, 'allow_reopen' => false, 'reopen_window_hours' => 0],

        // Fallback
        'UNKNOWN'           => ['timeout_hours' => 72,  'max_turns' => 25, 'max_duration_days' => 14, 'allow_reopen' => false, 'reopen_window_hours' => 0],
    ];

    /** @var array{timeout_hours: int, max_turns: int, max_duration_days: int, allow_reopen: bool, reopen_window_hours: int} */
    private const DEFAULT_POLICY = ['timeout_hours' => 72, 'max_turns' => 25, 'max_duration_days' => 14, 'allow_reopen' => false, 'reopen_window_hours' => 0];

    /**
     * @return array{timeout_hours: int, max_turns: int, max_duration_days: int, allow_reopen: bool, reopen_window_hours: int}
     */
    public static function getPolicy(string $scamTypeCode): array
    {
        return self::POLICIES[strtoupper($scamTypeCode)] ?? self::DEFAULT_POLICY;
    }

    public static function getTimeoutHours(string $scamTypeCode): int
    {
        return self::getPolicy($scamTypeCode)['timeout_hours'];
    }

    public static function getMaxTurns(string $scamTypeCode): int
    {
        return self::getPolicy($scamTypeCode)['max_turns'];
    }

    public static function getMaxDurationDays(string $scamTypeCode): int
    {
        return self::getPolicy($scamTypeCode)['max_duration_days'];
    }

    public static function allowsReopen(string $scamTypeCode): bool
    {
        return self::getPolicy($scamTypeCode)['allow_reopen'];
    }

    public static function getReopenWindowHours(string $scamTypeCode): int
    {
        return self::getPolicy($scamTypeCode)['reopen_window_hours'];
    }

    /**
     * @return array<string, array{timeout_hours: int, max_turns: int, max_duration_days: int, allow_reopen: bool, reopen_window_hours: int}>
     */
    public static function getAllPolicies(): array
    {
        return self::POLICIES;
    }
}

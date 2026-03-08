<?php

declare(strict_types=1);

namespace App\Application\User;

final class UserPasswordValidator
{
    private const MIN_LENGTH = 12;
    private const BLACKLIST = [
        // Top 10 sample, should be completed with top 1k in production
        'password', '123456', '123456789', 'qwerty', 'abc123', 'football', 'monkey', 'letmein', 'dragon', '111111',
    ];

    public static function validate(string $password): void
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            throw new \InvalidArgumentException('Password must be at least 12 characters long.');
        }
        $lower = mb_strtolower($password);

        foreach (self::BLACKLIST as $forbidden) {
            if (str_contains($lower, $forbidden)) {
                throw new \InvalidArgumentException('Password is too weak (blacklisted).');
            }
        }
    }
}

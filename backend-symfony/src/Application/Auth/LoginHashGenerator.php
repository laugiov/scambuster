<?php

declare(strict_types=1);

namespace App\Application\Auth;

class LoginHashGenerator
{
    private readonly string $salt;

    public function __construct()
    {
        /** @var string $salt */
        $salt = $_ENV['LOGIN_HASH_SALT'] ?? '';
        $this->salt = $salt;
    }

    public function generate(string $login): string
    {
        return hash('sha256', $login . $this->salt);
    }
}

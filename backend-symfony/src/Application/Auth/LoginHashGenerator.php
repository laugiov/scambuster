<?php

declare(strict_types=1);

namespace App\Application\Auth;

class LoginHashGenerator
{
    private readonly string $salt;

    public function __construct()
    {
        $this->salt = $_ENV['LOGIN_HASH_SALT'];
    }

    public function generate(string $login): string
    {
        return hash('sha256', $login . $this->salt);
    }
}

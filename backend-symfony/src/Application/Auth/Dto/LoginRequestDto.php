<?php

declare(strict_types=1);

namespace App\Application\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class LoginRequestDto
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $email;

    #[Assert\NotBlank]
    public string $password;

    public function __construct(string $email, string $password)
    {
        $this->email = $email;
        $this->password = $password;
    }
}

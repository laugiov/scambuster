<?php

declare(strict_types=1);

namespace App\Application\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class LoginRequestDto
{
    public function __construct(#[Assert\NotBlank]
        #[Assert\Email]
        public string $email, #[Assert\NotBlank]
        public string $password)
    {
    }
}

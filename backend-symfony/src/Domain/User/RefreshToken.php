<?php

declare(strict_types=1);

namespace App\Domain\User;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class RefreshToken
{
    #[ORM\Column(type: 'boolean')]
    private bool $valid = true;

    public function __construct(#[ORM\Id]
        #[ORM\Column(type: 'string', length: 128)]
        private string $token, #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user, #[ORM\Column(type: 'datetime')]
        private \DateTimeInterface $expiresAt)
    {
    }

    public function getToken(): string
    {
        return $this->token;
    }
    public function getUser(): User
    {
        return $this->user;
    }
    public function getExpiresAt(): \DateTimeInterface
    {
        return $this->expiresAt;
    }
    public function isValid(): bool
    {
        return $this->valid;
    }
    public function invalidate(): void
    {
        $this->valid = false;
    }
    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }
}

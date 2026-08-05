<?php

declare(strict_types=1);

namespace App\Domain\User;

use Doctrine\ORM\Mapping as ORM;

/**
 * Rotating refresh token, hardened against theft and at-rest disclosure.
 *
 * Security invariants:
 * - The raw token is NEVER persisted. The primary key is its SHA-256 (`token_hash`);
 *   a DB read therefore yields no replayable secret. Callers hash the presented token
 *   with {@see self::hash()} before looking it up.
 * - Every token belongs to a `family` (lineage) started at login and inherited across
 *   rotations, so replaying a rotated token can revoke the whole family (reuse detection).
 */
#[ORM\Entity]
#[ORM\Index(columns: ['family'], name: 'idx_refreshtoken_family')]
class RefreshToken
{
    #[ORM\Column(type: 'boolean')]
    private bool $valid = true;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'token_hash', type: 'string', length: 64)]
        private string $tokenHash,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(type: 'datetime')]
        private \DateTimeInterface $expiresAt,
        #[ORM\Column(type: 'string', length: 36)]
        private string $family,
    ) {
    }

    /**
     * Mint a token entity from a RAW token: stores only its hash, never the raw value.
     */
    public static function issue(string $rawToken, User $user, \DateTimeInterface $expiresAt, string $family): self
    {
        return new self(self::hash($rawToken), $user, $expiresAt, $family);
    }

    /**
     * One-way SHA-256 (hex) of a raw token. A fast hash is correct here: the token is
     * 512 bits of CSPRNG entropy, so pre-image brute force is infeasible and no slow KDF
     * is warranted.
     */
    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getExpiresAt(): \DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function getFamily(): string
    {
        return $this->family;
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

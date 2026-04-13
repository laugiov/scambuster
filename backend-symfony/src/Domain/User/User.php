<?php

declare(strict_types=1);

namespace App\Domain\User;

use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'app_users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface
{
    // --- PK ---
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    // Spec 065g — `tenant_id` column dropped. The previous `tenantId`
    // field was decoration only (random UUID per User, never filtered
    // by any repository). If a future spec re-introduces real
    // multi-tenancy, see Phase 7.8 of `docs/06_roadmap.md`.

    #[ORM\Column(length: 255, unique: true)]
    private string $email = '';

    // Same logic: we force snake_case
    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash = '';

    /** @var array<string> */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    /** @var array<string> */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $permissions = [];

    // Spec 065e — totp_secret is transparently encrypted at rest via
    // the EncryptedStringType custom Doctrine type (libsodium secretbox,
    // keyed by TOTP_ENCRYPTION_KEY env var). See docs/runbooks/totp-key-rotation.md.
    #[ORM\Column(name: 'totp_secret', type: 'encrypted_string', nullable: true)]
    private ?string $totpSecret = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    // --- Getters / setters ---
    public function getId(): Uuid
    {
        return $this->id;
    }
    public function getEmail(): string
    {
        return $this->email;
    }
    public function setEmail(string $e): self
    {
        $this->email = $e;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }
    public function setPassword(string $h): self
    {
        $this->passwordHash = $h;

        return $this;
    }

    public function getRoles(): array
    {
        return $this->roles ?: ['ROLE_USER'];
    }
    /** @param array<string> $r */
    public function setRoles(array $r): self
    {
        $this->roles = $r;

        return $this;
    }

    /** @return array<string> */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /** @param array<string> $permissions */
    public function setPermissions(array $permissions): self
    {
        $this->permissions = $permissions;

        return $this;
    }

    /**
     * Check if user has a specific permission.
     * Admins have all permissions implicitly.
     */
    public function hasPermission(Permission $permission): bool
    {
        if (in_array('ROLE_ADMIN', $this->getRoles(), true)) {
            return true;
        }

        return in_array($permission->value, $this->permissions, true);
    }

    // --- TOTP ---
    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function setTotpSecret(?string $secret): self
    {
        $this->totpSecret = $secret;

        return $this;
    }

    public function isTotpEnabled(): bool
    {
        return $this->totpSecret !== null;
    }

    // --- Scheb TwoFactorInterface (Spec 065e) ---
    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->totpSecret !== null;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->email;
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if ($this->totpSecret === null) {
            return null;
        }

        // The secret is stored encrypted (EncryptedStringType), but by the
        // time Doctrine hydrates it, it is already decrypted to the original
        // base32 string. Scheb expects base32 input.
        return new TotpConfiguration(
            $this->totpSecret,
            TotpConfiguration::ALGORITHM_SHA1,
            30,
            6,
        );
    }

    // --- UserInterface ---
    /** @return non-empty-string */
    public function getUserIdentifier(): string
    {
        return $this->email !== '' ? $this->email : 'unknown';
    }
    public function eraseCredentials(): void
    { /* nothing */
    }
}

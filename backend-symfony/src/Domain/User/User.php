<?php

declare(strict_types=1);

namespace App\Domain\User;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    // --- PK ---
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    // We force the physical name to match the database
    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(length: 255, unique: true)]
    private string $email = '';

    // Same logic: we force snake_case
    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash = '';

    /** @var array<string> */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    public function __construct()
    {
        $this->id       = Uuid::v4();
        $this->tenantId = Uuid::v4();      // dummy value for tests
    }

    // --- Getters / setters ---
    public function getId(): Uuid
    {
        return $this->id;
    }
    public function getTenantId(): Uuid
    {
        return $this->tenantId;
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

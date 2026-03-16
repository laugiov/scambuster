<?php

declare(strict_types=1);

namespace App\Domain\Communication;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mail_account')]
class MailAccount
{
    #[ORM\Id]
    #[ORM\Column(name: 'account_id', type: 'uuid', unique: true)]
    private string $accountId;

    #[ORM\Column(name: 'owner_id', type: 'uuid')]
    private string $ownerId;

    #[ORM\Column(type: 'string', length: 32)]
    private string $protocol;

    #[ORM\Column(type: 'string', length: 255)]
    private string $endpoint;

    #[ORM\Column(name: 'login_hash', type: 'string', length: 255)]
    private string $loginHash;

    /** @var array<int, string> */
    #[ORM\Column(name: 'oauth_scopes', type: 'json')]
    private array $oauthScopes;

    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'port', type: 'integer', nullable: true)]
    private ?int $port = null;

    #[ORM\Column(name: 'secure', type: 'boolean', nullable: true)]
    private ?bool $secure = null;

    /**
     * @param array<int, string> $oauthScopes
     */
    public function __construct(
        string $accountId,
        string $ownerId,
        string $protocol,
        string $endpoint,
        string $loginHash,
        array $oauthScopes,
        bool $isActive = true,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
        ?int $port = null,
        ?bool $secure = null
    ) {
        $this->accountId = $accountId;
        $this->ownerId = $ownerId;
        $this->protocol = $protocol;
        $this->endpoint = $endpoint;
        $this->loginHash = $loginHash;
        $this->oauthScopes = $oauthScopes;
        $this->isActive = $isActive;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
        $this->port = $port;
        $this->secure = $secure;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    public function getProtocol(): string
    {
        return $this->protocol;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getLoginHash(): string
    {
        return $this->loginHash;
    }

    /** @return array<int, string> */
    public function getOauthScopes(): array
    {
        return $this->oauthScopes;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getSecure(): ?bool
    {
        return $this->secure;
    }
}

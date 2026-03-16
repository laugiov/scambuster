<?php

declare(strict_types=1);

namespace App\Domain\Communication;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'persona')]
#[ORM\UniqueConstraint(name: 'uniq_persona_code', columns: ['persona_code'])]
#[ORM\Index(name: 'idx_persona_code', columns: ['persona_code'])]
#[ORM\Index(name: 'idx_persona_active', columns: ['is_active'])]
class Persona
{
    #[ORM\Id]
    #[ORM\Column(name: 'persona_id', type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    private int $personaId; // @phpstan-ignore-line

    #[ORM\Column(name: 'persona_code', type: 'string', length: 32, unique: true)]
    private string $personaCode;

    #[ORM\Column(name: 'persona_label', type: 'string', length: 128)]
    private string $personaLabel;

    #[ORM\Column(name: 'persona_tone', type: 'string', length: 256)]
    private string $personaTone;

    #[ORM\Column(name: 'system_prompt', type: 'text')]
    private string $systemPrompt;

    #[ORM\Column(name: 'created_by', type: 'string', length: 16)]
    private string $createdBy; // 'manual' | 'llm_auto'

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $isActive = true;

    public function __construct(
        string $personaCode,
        string $personaLabel,
        string $personaTone,
        string $systemPrompt,
        string $createdBy = 'manual',
        ?\DateTimeImmutable $createdAt = null,
        bool $isActive = true
    ) {
        $this->personaCode = $personaCode;
        $this->personaLabel = $personaLabel;
        $this->personaTone = $personaTone;
        $this->systemPrompt = $systemPrompt;
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->isActive = $isActive;
    }

    public function getPersonaId(): int
    {
        return $this->personaId;
    }

    public function getPersonaCode(): string
    {
        return $this->personaCode;
    }

    public function getPersonaLabel(): string
    {
        return $this->personaLabel;
    }

    public function getPersonaTone(): string
    {
        return $this->personaTone;
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function getCreatedBy(): string
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function setSystemPrompt(string $systemPrompt): void
    {
        $this->systemPrompt = $systemPrompt;
    }

    public function setPersonaLabel(string $personaLabel): void
    {
        $this->personaLabel = $personaLabel;
    }

    public function setPersonaTone(string $personaTone): void
    {
        $this->personaTone = $personaTone;
    }
}

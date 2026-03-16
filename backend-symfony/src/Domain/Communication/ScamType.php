<?php

declare(strict_types=1);

namespace App\Domain\Communication;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'lkp_scam_type')]
#[ORM\UniqueConstraint(columns: ['code'])]
class ScamType
{
    #[ORM\Id]
    #[ORM\Column(name: 'scam_type_id', type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    private int $scamTypeId; // @phpstan-ignore-line

    #[ORM\Column(type: 'string', length: 32, unique: true)]
    private string $code;

    #[ORM\Column(type: 'string', length: 128)]
    private string $label;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'misp_taxonomy', type: 'string', length: 128, nullable: true)]
    private ?string $mispTaxonomy = null;

    #[ORM\Column(name: 'attck_technique', type: 'string', length: 32, nullable: true)]
    private ?string $attckTechnique = null;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, Persona>
     */
    #[ORM\ManyToMany(targetEntity: Persona::class)]
    #[ORM\JoinTable(name: 'scam_type_persona')]
    #[ORM\JoinColumn(name: 'scam_type_id', referencedColumnName: 'scam_type_id')]
    #[ORM\InverseJoinColumn(name: 'persona_id', referencedColumnName: 'persona_id')]
    private Collection $personas;

    public function __construct(
        string $code,
        string $label,
        ?string $description = null,
        ?string $mispTaxonomy = null,
        ?string $attckTechnique = null,
        bool $active = true,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null
    ) {
        $this->code = $code;
        $this->label = $label;
        $this->description = $description;
        $this->mispTaxonomy = $mispTaxonomy;
        $this->attckTechnique = $attckTechnique;
        $this->active = $active;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
        $this->personas = new ArrayCollection();
    }

    public function getScamTypeId(): int
    {
        return $this->scamTypeId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getMispTaxonomy(): ?string
    {
        return $this->mispTaxonomy;
    }

    public function getAttckTechnique(): ?string
    {
        return $this->attckTechnique;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, Persona>
     */
    public function getPersonas(): Collection
    {
        return $this->personas;
    }

    public function addPersona(Persona $persona): void
    {
        if (!$this->personas->contains($persona)) {
            $this->personas->add($persona);
        }
    }

    public function removePersona(Persona $persona): void
    {
        $this->personas->removeElement($persona);
    }

    public function hasPersonas(): bool
    {
        return !$this->personas->isEmpty();
    }
}

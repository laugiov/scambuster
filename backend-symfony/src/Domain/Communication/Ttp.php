<?php

declare(strict_types=1);

namespace App\Domain\Communication;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'lkp_ttp')]
#[ORM\UniqueConstraint(columns: ['code'])]
#[ORM\Index(name: 'idx_lkp_ttp_phase', columns: ['phase'])]
class Ttp
{
    /**
     * Version of the TTP taxonomy this entity models. Stamped on every
     * observation so rows stay interpretable across taxonomy revisions.
     */
    public const TAXONOMY_VERSION = '1.1';

    #[ORM\Id]
    #[ORM\Column(name: 'ttp_id', type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    private int $ttpId = 0;

    /**
     * @param list<string>                                          $examples
     * @param list<string>                                          $stimulusAffinity
     * @param list<array{source_name: string, external_id: string}> $externalRefs
     */
    public function __construct(
        #[ORM\Column(type: 'string', length: 16, unique: true)]
        private string $code,
        #[ORM\Column(type: 'string', length: 128)]
        private string $label,
        #[ORM\Column(type: 'text')]
        private string $definition,
        #[ORM\Column(type: 'string', length: 32)]
        private string $phase,
        #[ORM\Column(type: 'json')]
        private array $examples,
        #[ORM\Column(name: 'stimulus_affinity', type: 'json')]
        private array $stimulusAffinity,
        #[ORM\Column(name: 'external_refs', type: 'json')]
        private array $externalRefs = [],
        #[ORM\Column(type: 'boolean')]
        private bool $active = true,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $updatedAt = new \DateTimeImmutable()
    ) {
    }

    public function getTtpId(): int
    {
        return $this->ttpId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDefinition(): string
    {
        return $this->definition;
    }

    public function getPhase(): string
    {
        return $this->phase;
    }

    /**
     * @return list<string>
     */
    public function getExamples(): array
    {
        return $this->examples;
    }

    /**
     * @return list<string>
     */
    public function getStimulusAffinity(): array
    {
        return $this->stimulusAffinity;
    }

    /**
     * @return list<array{source_name: string, external_id: string}>
     */
    public function getExternalRefs(): array
    {
        return $this->externalRefs;
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
}

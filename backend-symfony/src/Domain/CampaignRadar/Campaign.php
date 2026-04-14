<?php

declare(strict_types=1);

namespace App\Domain\CampaignRadar;

use App\Domain\Exception\DomainException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'campaign')]
#[ORM\Index(columns: ['status'], name: 'idx_campaign_status')]
#[ORM\Index(columns: ['first_seen'], name: 'idx_campaign_first_seen')]
#[ORM\Index(columns: ['dsl_hash'], name: 'idx_campaign_dsl_hash')]
class Campaign
{
    #[ORM\Id]
    #[ORM\Column(name: 'campaign_id', type: 'uuid', unique: true)]
    private Uuid $campaignId;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: CampaignStatus::class)]
    private CampaignStatus $status;

    #[ORM\Column(name: 'actor_guess', type: Types::TEXT, nullable: true)]
    private ?string $actorGuess = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $tlp = 'TLP:AMBER';

    #[ORM\Column(type: Types::SMALLINT)]
    private int $severity = 2;

    #[ORM\Column(name: 'dsl_hash', type: Types::STRING, length: 64)]
    private string $dslHash;

    #[ORM\Column(name: 'created_by', type: Types::TEXT)]
    private string $createdBy;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * Profil YAML généré par le LLM (CampaignProfiler).
     * Contient : campaign{summary, tactics, risk}, variants{subjects, display_names}, infra{...}
     */
    #[ORM\Column(name: 'profile_yaml', type: Types::TEXT, nullable: true)]
    private ?string $profileYaml = null;

    /**
     * Stocke le hash simhash représentatif de la campagne (centroid).
     * Utilisé pour calculer similarité dans clustering.
     */
    #[ORM\Column(name: 'centroid_simhash', type: Types::STRING, length: 32, nullable: true)]
    private ?string $centroidSimhash = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param Uuid|null $campaignId Si null, génère un nouveau UUID
     */
    public function __construct(
        string $createdBy,
        ?Uuid $campaignId = null,
        ?CampaignStatus $status = null,
        #[ORM\Column(name: 'first_seen', type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $firstSeen = new \DateTimeImmutable()
    ) {
        if (trim($createdBy) === '') {
            throw new DomainException('createdBy cannot be empty');
        }

        $this->campaignId = $campaignId ?? Uuid::v7();
        $this->status = $status ?? CampaignStatus::Shadow;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->dslHash = '';  // Sera défini lors de la création de règle
    }

    // === Getters ===

    public function getCampaignId(): Uuid
    {
        return $this->campaignId;
    }

    public function getFirstSeen(): \DateTimeImmutable
    {
        return $this->firstSeen;
    }

    public function getStatus(): CampaignStatus
    {
        return $this->status;
    }

    public function getActorGuess(): ?string
    {
        return $this->actorGuess;
    }

    public function getTlp(): string
    {
        return $this->tlp;
    }

    public function getSeverity(): int
    {
        return $this->severity;
    }

    public function getDslHash(): string
    {
        return $this->dslHash;
    }

    public function getCreatedBy(): string
    {
        return $this->createdBy;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getProfileYaml(): ?string
    {
        return $this->profileYaml;
    }

    public function getCentroidSimhash(): ?string
    {
        return $this->centroidSimhash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    // === Méthodes Métier ===

    /**
     * Promeut la campagne (shadow → promoted).
     *
     * @throws DomainException si déjà promue ou archivée
     */
    public function promote(): void
    {
        if ($this->status !== CampaignStatus::Shadow) {
            throw new DomainException("Cannot promote campaign with status {$this->status->value}");
        }

        $this->status = CampaignStatus::Promoted;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Archive la campagne.
     *
     * @throws DomainException si déjà archivée
     */
    public function archive(): void
    {
        if ($this->status === CampaignStatus::Archived) {
            throw new DomainException('Campaign is already archived');
        }

        $this->status = CampaignStatus::Archived;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Définit le hash DSL (empreinte de la règle).
     */
    public function setDslHash(string $dslHash): void
    {
        if ($dslHash === '') {
            throw new DomainException('dslHash cannot be empty');
        }

        $this->dslHash = $dslHash;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Définit la sévérité (1-5).
     *
     * @throws DomainException si hors limites
     */
    public function setSeverity(int $severity): void
    {
        if ($severity < 1 || $severity > 5) {
            throw new DomainException("Severity must be between 1 and 5, got {$severity}");
        }

        $this->severity = $severity;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Définit le TLP (Traffic Light Protocol).
     *
     * @throws DomainException si TLP invalide
     */
    public function setTlp(string $tlp): void
    {
        $validTlps = ['TLP:RED', 'TLP:AMBER', 'TLP:GREEN', 'TLP:WHITE'];

        if (!in_array($tlp, $validTlps, true)) {
            throw new DomainException("Invalid TLP: {$tlp}");
        }

        $this->tlp = $tlp;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Ajoute une note textuelle (pour l'analyste).
     */
    public function addNote(string $note): void
    {
        if ($this->notes === null) {
            $this->notes = $note;
        } else {
            $this->notes .= "\n---\n" . $note;
        }

        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Définit l'acteur suspect (attribution).
     */
    public function setActorGuess(string $actorGuess): void
    {
        $this->actorGuess = $actorGuess;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Définit le profil YAML généré par le LLM (CampaignProfiler).
     */
    public function setProfileYaml(string $yaml): void
    {
        $this->profileYaml = $yaml;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Définit le hash simhash centroid (représentatif de la campagne).
     * Utilisé pour calculer la similarité lors du clustering.
     */
    public function setCentroidSimhash(string $hash): void
    {
        if (strlen($hash) !== 32) {
            throw new DomainException('Centroid simhash must be 32 characters (MD5), got ' . strlen($hash));
        }

        $this->centroidSimhash = $hash;
        $this->updatedAt = new \DateTimeImmutable();
    }
}

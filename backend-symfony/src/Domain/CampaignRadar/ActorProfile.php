<?php

declare(strict_types=1);

namespace App\Domain\CampaignRadar;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'actor_profile')]
class ActorProfile
{
    #[ORM\Id]
    #[ORM\Column(name: 'actor_id', type: 'uuid', unique: true)]
    private Uuid $actorId;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'style_dna', type: Types::JSON)]
    private array $styleDna;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'infra_dna', type: Types::JSON)]
    private array $infraDna;

    /** @var array<int, string> */
    #[ORM\Column(type: Types::SIMPLE_ARRAY)]
    private array $campaigns = [];

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $styleDna
     * @param array<string, mixed> $infraDna
     */
    public function __construct(
        array $styleDna,
        array $infraDna,
        ?Uuid $actorId = null
    ) {
        $this->actorId = $actorId ?? Uuid::v7();
        $this->styleDna = $styleDna;
        $this->infraDna = $infraDna;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getActorId(): Uuid
    {
        return $this->actorId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStyleDna(): array
    {
        return $this->styleDna;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInfraDna(): array
    {
        return $this->infraDna;
    }

    /**
     * @return array<string> Liste des UUID de campagnes
     */
    public function getCampaigns(): array
    {
        return $this->campaigns;
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
     * Ajoute une campagne liée à cet acteur.
     */
    public function linkCampaign(Uuid $campaignId): void
    {
        $campaignIdStr = $campaignId->toRfc4122();

        if (!in_array($campaignIdStr, $this->campaigns, true)) {
            $this->campaigns[] = $campaignIdStr;
            $this->updatedAt = new \DateTimeImmutable();
        }
    }
}

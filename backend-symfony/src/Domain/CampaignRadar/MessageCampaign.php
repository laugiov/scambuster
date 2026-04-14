<?php

declare(strict_types=1);

namespace App\Domain\CampaignRadar;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'message_campaign')]
#[ORM\Index(columns: ['campaign_id'], name: 'idx_message_campaign_campaign')]
#[ORM\Index(columns: ['detected_at'], name: 'idx_message_campaign_detected')]
class MessageCampaign
{
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4)]
    private string $confidence;

    #[ORM\Column(name: 'detected_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $detectedAt;

    #[ORM\Column(name: 'detected_by', type: Types::STRING, length: 50)]
    private string $detectedBy;

    /**
     * Features extraites lors du clustering (JSONB).
     * Structure : {text: {simhash, ngrams}, infra: {domains, dkim, spf}, style: {punct_ratio, formality}}
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $features = null;

    /**
     * True si le message est un vrai positif (confirmé manuellement ou par règle de référence).
     * False si faux positif. Null si non encore validé.
     */
    #[ORM\Column(name: 'is_true_positive', type: Types::BOOLEAN, nullable: true)]
    private ?bool $isTruePositive = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'msg_id', type: 'uuid')]
        private Uuid $msgId,
        #[ORM\Id]
        #[ORM\Column(name: 'campaign_id', type: 'uuid')]
        private Uuid $campaignId,
        float $confidence,
        string $detectedBy
    ) {
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new \DomainException("Confidence must be between 0 and 1, got {$confidence}");
        }

        if (trim($detectedBy) === '') {
            throw new \DomainException('detectedBy cannot be empty');
        }
        $this->confidence = number_format($confidence, 4, '.', '');
        $this->detectedAt = new \DateTimeImmutable();
        $this->detectedBy = $detectedBy;
    }

    public function getMsgId(): Uuid
    {
        return $this->msgId;
    }

    public function getCampaignId(): Uuid
    {
        return $this->campaignId;
    }

    public function getConfidence(): float
    {
        return (float) $this->confidence;
    }

    public function getDetectedAt(): \DateTimeImmutable
    {
        return $this->detectedAt;
    }

    public function getDetectedBy(): string
    {
        return $this->detectedBy;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getFeatures(): ?array
    {
        return $this->features;
    }

    /**
     * @param array<string, mixed> $features
     */
    public function setFeatures(array $features): void
    {
        $this->features = $features;
    }

    public function getIsTruePositive(): ?bool
    {
        return $this->isTruePositive;
    }

    public function markAsTruePositive(): void
    {
        $this->isTruePositive = true;
    }

    public function markAsFalsePositive(): void
    {
        $this->isTruePositive = false;
    }
}

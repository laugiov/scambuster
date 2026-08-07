<?php

declare(strict_types=1);

namespace App\Domain\Communication;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'conversation')]
class Conversation
{
    #[ORM\ManyToOne(targetEntity: Persona::class)]
    #[ORM\JoinColumn(name: 'persona_id', referencedColumnName: 'persona_id', nullable: true)]
    private ?Persona $persona = null;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null; // @phpstan-ignore-line

    #[ORM\Column(name: 'delivery', type: 'string', length: 32)]
    private string $delivery = 'DELIVERY_UNKNOWN';

    #[ORM\Column(name: 'tlp', type: 'string', length: 16)]
    private string $tlp = 'TLP_AMBER';

    /** @var array<int, array{code: string, confidence: float}>|null */
    #[ORM\Column(name: 'secondary_scam_types', type: 'json', nullable: true)]
    private ?array $secondaryScamTypes = null;

    // Scambaiting adaptive metrics
    #[ORM\Column(name: 'engagement_duration_sec', type: 'integer', options: ['default' => 0])]
    private int $engagementDurationSec = 0;

    #[ORM\Column(name: 'turns_count', type: 'integer', options: ['default' => 0])]
    private int $turnsCount = 0;

    #[ORM\Column(name: 'reward_value', type: 'decimal', precision: 5, scale: 4, nullable: true)]
    private ?string $rewardValue = null;

    public function __construct(#[ORM\Id]
        #[ORM\Column(name: 'conv_id', type: 'uuid', unique: true)]
        private string $convId, #[ORM\ManyToOne(targetEntity: Channel::class)]
        #[ORM\JoinColumn(name: 'primary_channel_id', referencedColumnName: 'channel_id', nullable: false)]
        private Channel $primaryChannel, #[ORM\ManyToOne(targetEntity: ScamType::class, fetch: 'EAGER')]
        #[ORM\JoinColumn(name: 'scam_type_id', referencedColumnName: 'scam_type_id', nullable: false)]
        private ScamType $scamType, #[ORM\ManyToOne(targetEntity: MailAccount::class)]
        #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'account_id', nullable: false)]
        private MailAccount $account, #[ORM\Column(type: 'string', enumType: ConversationStatus::class)]
        private ConversationStatus $status, #[ORM\Column(name: 'score_risk', type: 'integer')]
        private int $scoreRisk, #[ORM\Column(name: 'ts_first', type: 'datetime_immutable')]
        private \DateTimeImmutable $tsFirst, #[ORM\Column(name: 'ts_last', type: 'datetime_immutable')]
        private \DateTimeImmutable $tsLast, #[ORM\Column(name: 'stix_id', type: 'string', length: 255, unique: true)]
        private string $stixId, #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(), #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $updatedAt = new \DateTimeImmutable())
    {
    }

    public function getConvId(): string
    {
        return $this->convId;
    }

    public function getPrimaryChannel(): Channel
    {
        return $this->primaryChannel;
    }

    public function getScamType(): ScamType
    {
        return $this->scamType;
    }

    public function getAccount(): MailAccount
    {
        return $this->account;
    }

    public function getStatus(): ConversationStatus
    {
        return $this->status;
    }

    public function getScoreRisk(): int
    {
        return $this->scoreRisk;
    }

    public function getTsFirst(): \DateTimeImmutable
    {
        return $this->tsFirst;
    }

    public function getTsLast(): \DateTimeImmutable
    {
        return $this->tsLast;
    }

    public function getStixId(): string
    {
        return $this->stixId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    /**
     * Soft-delete this conversation by stamping the deletion time.
     */
    public function softDelete(\DateTimeImmutable $deletedAt): void
    {
        $this->deletedAt = $deletedAt;
    }

    /**
     * Set the timestamp of the last message in this conversation.
     */
    public function setTsLast(\DateTimeImmutable $tsLast): void
    {
        $this->tsLast = $tsLast;
    }

    public function setScoreRisk(int $scoreRisk): void
    {
        $this->scoreRisk = $scoreRisk;
    }

    public function setStixId(string $stixId): void
    {
        $this->stixId = $stixId;
    }

    public function setScamType(ScamType $scamType): void
    {
        $this->scamType = $scamType;
    }

    /**
     * Update the risk score for this conversation.
     *
     * @param int $score Must be between 0 and 100
     *
     * @throws \InvalidArgumentException if score is out of range
     */
    public function updateRiskScore(int $score): void
    {
        if ($score < 0 || $score > 100) {
            throw new \InvalidArgumentException('Risk score must be between 0 and 100');
        }
        $this->scoreRisk = $score;
    }

    /**
     * Mark this conversation as abandoned (no response from scammer).
     */
    public function markAsAbandoned(): void
    {
        $this->status = ConversationStatus::ABANDONED;
    }

    /**
     * Close this conversation.
     */
    public function close(): void
    {
        $this->status = ConversationStatus::CLOSED;
    }

    /**
     * Reopen a closed or abandoned conversation.
     */
    public function reopen(): void
    {
        $this->status = ConversationStatus::OPEN;
    }

    /**
     * Mark this conversation as opened by mistake (not a real scammer thread).
     */
    public function markAsMistake(): void
    {
        $this->status = ConversationStatus::MISTAKE;
    }

    /**
     * Check if conversation is active (open status).
     */
    public function isActive(): bool
    {
        return $this->status === ConversationStatus::OPEN;
    }

    /**
     * Check if conversation is closed.
     */
    public function isClosed(): bool
    {
        return $this->status === ConversationStatus::CLOSED;
    }

    /**
     * Get the persona assigned to this conversation.
     */
    public function getPersona(): ?Persona
    {
        return $this->persona;
    }

    /**
     * Set the persona for this conversation.
     */
    public function setPersona(?Persona $persona): void
    {
        $this->persona = $persona;
    }

    /**
     * Get the delivery status for this conversation.
     * Values: DELIVERY_UNKNOWN, SENT, BOUNCED, DELIVERED, READ, REPLIED
     */
    public function getDelivery(): string
    {
        return $this->delivery;
    }

    /**
     * Set the delivery status for this conversation.
     * Valid values: DELIVERY_UNKNOWN, SENT, BOUNCED, DELIVERED, READ, REPLIED
     */
    public function setDelivery(string $delivery): void
    {
        $validDeliveryStatuses = ['DELIVERY_UNKNOWN', 'SENT', 'BOUNCED', 'DELIVERED', 'READ', 'REPLIED'];

        if (!in_array($delivery, $validDeliveryStatuses, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid delivery status: %s. Must be one of: %s', $delivery, implode(', ', $validDeliveryStatuses))
            );
        }
        $this->delivery = $delivery;
    }

    /**
     * Get the Traffic Light Protocol (TLP) level for threat intel sharing.
     * Values: TLP_WHITE, TLP_GREEN, TLP_AMBER, TLP_RED
     */
    public function getTlp(): string
    {
        return $this->tlp;
    }

    /**
     * Set the Traffic Light Protocol (TLP) level for threat intel sharing.
     * - TLP_WHITE: unlimited disclosure
     * - TLP_GREEN: community disclosure
     * - TLP_AMBER: limited disclosure (default)
     * - TLP_RED: personal/eyes-only
     */
    public function setTlp(string $tlp): void
    {
        $validTlpLevels = ['TLP_WHITE', 'TLP_GREEN', 'TLP_AMBER', 'TLP_AMBER_STRICT', 'TLP_RED'];

        if (!in_array($tlp, $validTlpLevels, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid TLP level: %s. Must be one of: %s', $tlp, implode(', ', $validTlpLevels))
            );
        }
        $this->tlp = $tlp;
    }

    /**
     * Get engagement duration in seconds (scambaiting metric).
     */
    public function getEngagementDurationSec(): int
    {
        return $this->engagementDurationSec;
    }

    /**
     * Set engagement duration in seconds (scambaiting metric).
     */
    public function setEngagementDurationSec(int $engagementDurationSec): void
    {
        $this->engagementDurationSec = $engagementDurationSec;
    }

    /**
     * Get turns count (number of message exchanges, scambaiting metric).
     */
    public function getTurnsCount(): int
    {
        return $this->turnsCount;
    }

    /**
     * Set turns count (scambaiting metric).
     */
    public function setTurnsCount(int $turnsCount): void
    {
        $this->turnsCount = $turnsCount;
    }

    /**
     * Get reward value (normalized 0-1, scambaiting metric).
     * Returns null if not yet calculated.
     */
    public function getRewardValue(): ?float
    {
        return $this->rewardValue !== null ? (float) $this->rewardValue : null;
    }

    /**
     * Set reward value (scambaiting metric).
     * Must be between 0.0 and 1.0.
     *
     * @throws \InvalidArgumentException if reward is out of bounds
     */
    public function setRewardValue(float $rewardValue): void
    {
        if ($rewardValue < 0.0 || $rewardValue > 1.0) {
            throw new \InvalidArgumentException(
                sprintf('Reward value must be between 0.0 and 1.0, got %.4f', $rewardValue)
            );
        }
        $this->rewardValue = number_format($rewardValue, 4, '.', '');
    }

    /**
     * Reset reward value to null.
     * Used when a conversation is reopened after being closed.
     */
    public function resetRewardValue(): void
    {
        $this->rewardValue = null;
    }

    /**
     * Get secondary scam type classifications.
     * Each element: ['code' => 'ROMANCE', 'confidence' => 0.6]
     *
     * @return array<int, array{code: string, confidence: float}>|null
     */
    public function getSecondaryScamTypes(): ?array
    {
        return $this->secondaryScamTypes;
    }

    /**
     * Set secondary scam type classifications.
     *
     * @param array<int, array{code: string, confidence: float}>|null $types
     */
    public function setSecondaryScamTypes(?array $types): void
    {
        $this->secondaryScamTypes = $types;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Communication;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ttp_observation')]
#[ORM\UniqueConstraint(name: 'uniq_ttp_observation_msg_ttp', columns: ['msg_id', 'ttp_id'])]
#[ORM\Index(name: 'idx_ttp_observation_conv', columns: ['conv_id'])]
#[ORM\Index(name: 'idx_ttp_observation_ttp', columns: ['ttp_id'])]
#[ORM\Index(name: 'idx_ttp_observation_status', columns: ['status'])]
class TtpObservation
{
    #[ORM\Column(name: 'confidence', type: 'decimal', precision: 4, scale: 3)]
    private string $confidence;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'obs_id', type: 'uuid', unique: true)]
        private string $obsId,
        #[ORM\ManyToOne(targetEntity: Message::class)]
        #[ORM\JoinColumn(name: 'msg_id', referencedColumnName: 'msg_id', nullable: false, onDelete: 'CASCADE')]
        private Message $message,
        #[ORM\ManyToOne(targetEntity: Conversation::class)]
        #[ORM\JoinColumn(name: 'conv_id', referencedColumnName: 'conv_id', nullable: false, onDelete: 'CASCADE')]
        private Conversation $conversation,
        #[ORM\ManyToOne(targetEntity: Ttp::class)]
        #[ORM\JoinColumn(name: 'ttp_id', referencedColumnName: 'ttp_id', nullable: false)]
        private Ttp $ttp,
        float $confidence,
        #[ORM\Column(name: 'evidence', type: 'text')]
        private string $evidence,
        #[ORM\Column(name: 'evidence_start', type: 'integer', nullable: true)]
        private ?int $evidenceStart,
        #[ORM\Column(name: 'evidence_end', type: 'integer', nullable: true)]
        private ?int $evidenceEnd,
        #[ORM\Column(name: 'status', type: 'string', length: 16)]
        private string $status,
        #[ORM\Column(name: 'taxonomy_version', type: 'string', length: 16)]
        private string $taxonomyVersion,
        #[ORM\Column(name: 'extraction_model', type: 'string', length: 64)]
        private string $extractionModel,
        #[ORM\Column(name: 'prompt_version', type: 'string', length: 16)]
        private string $promptVersion,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->confidence = (string) min($confidence, 1.0);
    }

    public function getObsId(): string
    {
        return $this->obsId;
    }

    public function getMessage(): Message
    {
        return $this->message;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getTtp(): Ttp
    {
        return $this->ttp;
    }

    public function getConfidence(): float
    {
        return (float) $this->confidence;
    }

    public function getEvidence(): string
    {
        return $this->evidence;
    }

    public function getEvidenceStart(): ?int
    {
        return $this->evidenceStart;
    }

    public function getEvidenceEnd(): ?int
    {
        return $this->evidenceEnd;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getTaxonomyVersion(): string
    {
        return $this->taxonomyVersion;
    }

    public function getExtractionModel(): string
    {
        return $this->extractionModel;
    }

    public function getPromptVersion(): string
    {
        return $this->promptVersion;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

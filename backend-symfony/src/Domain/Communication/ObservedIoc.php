<?php

declare(strict_types=1);

namespace App\Domain\Communication;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'observed_ioc')]
class ObservedIoc
{
    #[ORM\Column(name: 'confidence_score', type: 'decimal', precision: 4, scale: 3, nullable: true)]
    private ?string $confidenceScore = null;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'obs_id', type: 'uuid', unique: true)]
        private string $obsId,
        #[ORM\ManyToOne(targetEntity: Message::class)]
        #[ORM\JoinColumn(name: 'msg_id', referencedColumnName: 'msg_id', nullable: false, onDelete: 'CASCADE')]
        private Message $message,
        #[ORM\Column(name: 'indicator_id', type: 'uuid')]
        private string $indicatorId,
        #[ORM\Column(name: 'context_observation', type: 'json')]
        private array $context,
        #[ORM\Column(name: 'ts_observed', type: 'datetime_immutable')]
        private \DateTimeImmutable $tsObserved = new \DateTimeImmutable(),
        ?float $confidenceScore = null,
    ) {
        $this->confidenceScore = $confidenceScore !== null ? (string) $confidenceScore : null;
    }

    public function getObsId(): string
    {
        return $this->obsId;
    }

    public function getMessage(): Message
    {
        return $this->message;
    }

    public function getIndicatorId(): string
    {
        return $this->indicatorId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    public function getTsObserved(): \DateTimeImmutable
    {
        return $this->tsObserved;
    }

    /**
     * Update IOC context (e.g., new enrichment data from n8n)
     *
     * This method allows the Application layer to update the context
     * without using Reflection API, maintaining proper domain encapsulation.
     *
     * @param array<string, mixed> $context Updated context data
     */
    public function updateContext(array $context): void
    {
        $this->context = $context;
    }

    public function getConfidenceScore(): ?float
    {
        return $this->confidenceScore !== null ? (float) $this->confidenceScore : null;
    }

    public function setConfidenceScore(float $score): void
    {
        $this->confidenceScore = (string) min($score, 1.0);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Communication;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'observed_ioc')]
class ObservedIoc
{
    #[ORM\Id]
    #[ORM\Column(name: 'obs_id', type: 'uuid', unique: true)]
    private string $obsId;

    #[ORM\ManyToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(name: 'msg_id', referencedColumnName: 'msg_id', nullable: false, onDelete: 'CASCADE')]
    private Message $message;

    #[ORM\Column(name: 'indicator_id', type: 'uuid')]
    private string $indicatorId;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'context_observation', type: 'json')]
    private array $context;

    #[ORM\Column(name: 'ts_observed', type: 'datetime_immutable')]
    private \DateTimeImmutable $tsObserved;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $obsId,
        Message $message,
        string $indicatorId,
        array $context,
        ?\DateTimeImmutable $tsObserved = null
    ) {
        $this->obsId = $obsId;
        $this->message = $message;
        $this->indicatorId = $indicatorId;
        $this->context = $context;
        $this->tsObserved = $tsObserved ?? new \DateTimeImmutable();
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
}

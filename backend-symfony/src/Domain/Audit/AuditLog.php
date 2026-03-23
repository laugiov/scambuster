<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Doctrine\ORM\Mapping as ORM;

/**
 * Structured audit trail entry for security-relevant events.
 *
 * Follows the security-by-design audit schema:
 * event_type + actor + resource + action + outcome + details + trace_id.
 *
 * Immutable after creation (append-only log).
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(columns: ['event_type'], name: 'idx_audit_event_type')]
#[ORM\Index(columns: ['created_at'], name: 'idx_audit_created_at')]
#[ORM\Index(columns: ['actor_id'], name: 'idx_audit_actor_id')]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id = 0;

    #[ORM\Column(name: 'event_type', type: 'string', length: 50)]
    private string $eventType;

    #[ORM\Column(name: 'actor_type', type: 'string', length: 30)]
    private string $actorType;

    #[ORM\Column(name: 'actor_id', type: 'string', length: 255)]
    private string $actorId;

    #[ORM\Column(name: 'resource_type', type: 'string', length: 50, nullable: true)]
    private ?string $resourceType;

    #[ORM\Column(name: 'resource_id', type: 'string', length: 255, nullable: true)]
    private ?string $resourceId;

    #[ORM\Column(type: 'string', length: 20)]
    private string $action;

    #[ORM\Column(type: 'string', length: 20)]
    private string $outcome;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $details;

    #[ORM\Column(name: 'ip_address', type: 'string', length: 45, nullable: true)]
    private ?string $ipAddress;

    #[ORM\Column(name: 'trace_id', type: 'string', length: 64, nullable: true)]
    private ?string $traceId;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        AuditEventType $eventType,
        string $actorType,
        string $actorId,
        string $action,
        string $outcome,
        array $details = [],
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?string $ipAddress = null,
        ?string $traceId = null
    ) {
        $this->eventType = $eventType->value;
        $this->actorType = $actorType;
        $this->actorId = $actorId;
        $this->action = $action;
        $this->outcome = $outcome;
        $this->details = $details;
        $this->resourceType = $resourceType;
        $this->resourceId = $resourceId;
        $this->ipAddress = $ipAddress;
        $this->traceId = $traceId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getActorType(): string
    {
        return $this->actorType;
    }

    public function getActorId(): string
    {
        return $this->actorId;
    }

    public function getResourceType(): ?string
    {
        return $this->resourceType;
    }

    public function getResourceId(): ?string
    {
        return $this->resourceId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getTraceId(): ?string
    {
        return $this->traceId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->eventType,
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'action' => $this->action,
            'outcome' => $this->outcome,
            'details' => $this->details,
            'ip_address' => $this->ipAddress,
            'trace_id' => $this->traceId,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}

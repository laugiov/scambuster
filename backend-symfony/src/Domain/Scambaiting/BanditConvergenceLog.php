<?php

declare(strict_types=1);

namespace App\Domain\Scambaiting;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'bandit_convergence_log')]
#[ORM\Index(columns: ['scam_type_code'], name: 'idx_convergence_scam_type')]
#[ORM\Index(columns: ['logged_at'], name: 'idx_convergence_logged_at')]
class BanditConvergenceLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(name: 'scam_type_code', type: 'string', length: 32)]
    private string $scamTypeCode;

    #[ORM\Column(name: 'dominant_persona_code', type: 'string', length: 32)]
    private string $dominantPersonaCode;

    #[ORM\Column(name: 'dominant_pct', type: 'decimal', precision: 5, scale: 2)]
    private string $dominantPct;

    #[ORM\Column(name: 'sessions_count', type: 'integer')]
    private int $sessionsCount;

    #[ORM\Column(name: 'converged', type: 'boolean')]
    private bool $converged;

    #[ORM\Column(name: 'logged_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $loggedAt;

    public function __construct(
        string $scamTypeCode,
        string $dominantPersonaCode,
        float $dominantPct,
        int $sessionsCount,
        bool $converged,
        ?\DateTimeImmutable $loggedAt = null,
    ) {
        $this->scamTypeCode = $scamTypeCode;
        $this->dominantPersonaCode = $dominantPersonaCode;
        $this->dominantPct = (string) $dominantPct;
        $this->sessionsCount = $sessionsCount;
        $this->converged = $converged;
        $this->loggedAt = $loggedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getScamTypeCode(): string
    {
        return $this->scamTypeCode;
    }

    public function getDominantPersonaCode(): string
    {
        return $this->dominantPersonaCode;
    }

    public function getDominantPct(): float
    {
        return (float) $this->dominantPct;
    }

    public function getSessionsCount(): int
    {
        return $this->sessionsCount;
    }

    public function isConverged(): bool
    {
        return $this->converged;
    }

    public function getLoggedAt(): \DateTimeImmutable
    {
        return $this->loggedAt;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\CampaignRadar;

use App\Domain\Exception\DomainException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'campaign_rule')]
#[ORM\Index(columns: ['campaign_id'], name: 'idx_campaign_rule_campaign')]
#[ORM\Index(columns: ['enabled'], name: 'idx_campaign_rule_enabled')]
#[ORM\Index(columns: ['ppv'], name: 'idx_campaign_rule_ppv')]
class CampaignRule
{
    #[ORM\Id]
    #[ORM\Column(name: 'rule_id', type: 'uuid', unique: true)]
    private Uuid $ruleId;

    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(type: Types::TEXT)]
    private string $dsl;

    /**
     * Données compilées (SQL + params) au format JSON.
     * Structure : {sql: string, params: array<string, mixed>, tests: array}
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'compiled_sql', type: Types::JSON, nullable: true)]
    private ?array $compiledSql = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4)]
    private string $ppv = '0.0000';

    #[ORM\Column(name: 'hits_total', type: Types::INTEGER)]
    private int $hitsTotal = 0;

    #[ORM\Column(name: 'hits_true_pos', type: Types::INTEGER)]
    private int $hitsTruePos = 0;

    #[ORM\Column(name: 'hits_false_pos', type: Types::INTEGER)]
    private int $hitsFalsePos = 0;

    #[ORM\Column(name: 'lead_time_sec', type: Types::INTEGER, nullable: true)]
    private ?int $leadTimeSec = null;

    #[ORM\Column(name: 'promoted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $promotedAt = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(name: 'campaign_id', type: 'uuid')]
        private Uuid $campaignId,
        string $dsl,
        ?Uuid $ruleId = null
    ) {
        if (trim($dsl) === '') {
            throw new DomainException('DSL rule cannot be empty');
        }

        $this->ruleId = $ruleId ?? Uuid::v7();
        $this->dsl = $dsl;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    // === Getters ===

    public function getRuleId(): Uuid
    {
        return $this->ruleId;
    }

    public function getCampaignId(): Uuid
    {
        return $this->campaignId;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getDsl(): string
    {
        return $this->dsl;
    }

    /**
     * Récupère le SQL compilé brut (pour compatibilité legacy).
     *
     * @deprecated Utiliser getCompiledData() à la place
     */
    public function getCompiledSql(): ?string
    {
        if ($this->compiledSql === null) {
            return null;
        }

        $encoded = json_encode($this->compiledSql);

        return $encoded !== false ? $encoded : null;
    }

    /**
     * Récupère les données compilées (SQL + params).
     *
     * @return array<string, mixed>|null
     */
    public function getCompiledData(): ?array
    {
        return $this->compiledSql;
    }

    public function getPpv(): float
    {
        return (float) $this->ppv;
    }

    public function getHitsTotal(): int
    {
        return $this->hitsTotal;
    }

    public function getHitsTruePos(): int
    {
        return $this->hitsTruePos;
    }

    public function getHitsFalsePos(): int
    {
        return $this->hitsFalsePos;
    }

    public function getLeadTimeSec(): ?int
    {
        return $this->leadTimeSec;
    }

    public function getPromotedAt(): ?\DateTimeImmutable
    {
        return $this->promotedAt;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
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
     * Enregistre le code SQL transcompilé.
     */
    /**
     * Enregistre le SQL compilé brut (pour compatibilité legacy).
     *
     * @deprecated Utiliser setCompiledData() à la place
     */
    public function setCompiledSql(string $sql): void
    {
        if (trim($sql) === '') {
            throw new DomainException('Compiled SQL cannot be empty');
        }

        // Convertir la string JSON en array pour stockage
        try {
            $this->compiledSql = json_decode($sql, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Si ce n'est pas du JSON, créer une structure simple
            $this->compiledSql = ['sql' => $sql, 'params' => []];
        }

        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Enregistre les données compilées (SQL + paramètres).
     *
     * @param array<string, mixed> $compiledData
     */
    public function setCompiledData(array $compiledData): void
    {
        if (!isset($compiledData['sql']) || !isset($compiledData['params'])) {
            throw new DomainException('Compiled data must contain sql and params keys');
        }

        /** @var string $sql */
        $sql = $compiledData['sql'];
        if (trim($sql) === '') {
            throw new DomainException('Compiled SQL cannot be empty');
        }

        $this->compiledSql = $compiledData;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Met à jour les métriques de la règle après un test shadow.
     *
     * @param int $hits     Nombre de détections
     * @param int $truePos  Vrais positifs
     * @param int $falsePos Faux positifs
     */
    public function updateMetrics(int $hits, int $truePos, int $falsePos): void
    {
        if ($hits < 0 || $truePos < 0 || $falsePos < 0) {
            throw new DomainException('Metrics must be >= 0');
        }

        if ($truePos + $falsePos !== $hits) {
            throw new DomainException('truePos + falsePos must equal hits');
        }

        $this->hitsTotal += $hits;
        $this->hitsTruePos += $truePos;
        $this->hitsFalsePos += $falsePos;

        // Recalcul PPV
        $this->ppv = $this->hitsTotal > 0 ? number_format($this->hitsTruePos / $this->hitsTotal, 4, '.', '') : '0.0000';

        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Définit le lead-time (avance sur le pic).
     */
    public function setLeadTimeSec(int $leadTimeSec): void
    {
        if ($leadTimeSec < 0) {
            throw new DomainException('Lead-time must be >= 0');
        }

        $this->leadTimeSec = $leadTimeSec;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Définit la PPV de la règle.
     */
    public function setPpv(float $ppv): void
    {
        if ($ppv < 0 || $ppv > 1) {
            throw new DomainException('PPV must be between 0 and 1');
        }

        $this->ppv = number_format($ppv, 4, '.', '');
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Vérifie si la règle est éligible à la promotion.
     *
     * Critères SLA :
     * - PPV ≥ 0.85
     * - Hits total ≥ 5
     * - Enabled = true
     * - Pas encore promue (promotedAt = null)
     */
    public function isPromotable(): bool
    {
        return $this->getPpv() >= 0.85
            && $this->hitsTotal >= 5
            && $this->enabled
            && !$this->promotedAt instanceof \DateTimeImmutable;
    }

    /**
     * Promeut la règle.
     *
     * @throws DomainException si déjà promue ou critères non atteints
     */
    public function promote(): void
    {
        if (!$this->isPromotable()) {
            throw new DomainException("Rule is not promotable (PPV={$this->getPpv()}, hits={$this->hitsTotal})");
        }

        $this->promotedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Désactive la règle.
     */
    public function disable(): void
    {
        $this->enabled = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Réactive la règle.
     */
    public function enable(): void
    {
        $this->enabled = true;
        $this->updatedAt = new \DateTimeImmutable();
    }
}

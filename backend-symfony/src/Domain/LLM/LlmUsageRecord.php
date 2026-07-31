<?php

declare(strict_types=1);

namespace App\Domain\LLM;

use Doctrine\ORM\Mapping as ORM;

/**
 * Records a single LLM API call with token usage and estimated cost.
 *
 * Used for:
 * - Cost tracking and monthly budget enforcement
 * - Per-conversation and per-purpose cost analysis
 * - Provider comparison and optimization
 */
#[ORM\Entity]
#[ORM\Table(name: 'llm_usage')]
#[ORM\Index(columns: ['created_at'], name: 'idx_llm_usage_created_at')]
#[ORM\Index(columns: ['provider'], name: 'idx_llm_usage_provider')]
class LlmUsageRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id = 0;

    #[ORM\Column(name: 'prompt_tokens', type: 'integer')]
    private int $promptTokens;

    #[ORM\Column(name: 'completion_tokens', type: 'integer')]
    private int $completionTokens;

    #[ORM\Column(name: 'estimated_cost_usd', type: 'decimal', precision: 10, scale: 6)]
    private string $estimatedCostUsd;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(type: 'string', length: 32)]
        private string $provider,
        #[ORM\Column(type: 'string', length: 64)]
        private string $model,
        #[ORM\Column(type: 'string', length: 50)]
        private string $purpose,
        int $promptTokens,
        int $completionTokens,
        float $estimatedCostUsd,
        #[ORM\Column(name: 'conversation_id', type: 'string', length: 36, nullable: true)]
        private ?string $conversationId = null
    ) {
        if ($promptTokens < 0 || $completionTokens < 0) {
            throw new \InvalidArgumentException('Token counts cannot be negative');
        }

        if ($estimatedCostUsd < 0) {
            throw new \InvalidArgumentException('Estimated cost cannot be negative');
        }
        $this->promptTokens = $promptTokens;
        $this->completionTokens = $completionTokens;
        $this->estimatedCostUsd = number_format($estimatedCostUsd, 6, '.', '');
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    public function getEstimatedCostUsd(): float
    {
        return (float) $this->estimatedCostUsd;
    }

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

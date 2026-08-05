<?php

declare(strict_types=1);

namespace App\Domain\Prompt;

use Doctrine\ORM\Mapping as ORM;

/**
 * An operator-managed override of a generative LLM prompt, edited through the admin UI
 * and stored in the database. Resolved by PromptProvider ahead of the on-disk file and
 * the shipped default (DB → file → default), and only when {@see self::isEnabled()}.
 *
 * `promptKey` is one of the keys in PromptCatalog. The body is validated (required
 * placeholders) at write time and again at resolution, so a bad row can only fall
 * through to the file/default — never break generation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'prompt_override')]
#[ORM\UniqueConstraint(name: 'uniq_prompt_override_key', columns: ['prompt_key'])]
class PromptOverride
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private int $id = 0;

    #[ORM\Column(name: 'prompt_key', length: 64)]
    private string $promptKey;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'updated_by', length: 255, nullable: true)]
    private ?string $updatedBy;

    public function __construct(
        string $promptKey,
        string $body,
        bool $enabled = true,
        ?string $updatedBy = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->promptKey = $promptKey;
        $this->body = $body;
        $this->enabled = $enabled;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPromptKey(): string
    {
        return $this->promptKey;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }

    /**
     * Apply an operator edit (body and/or enabled), stamping the author and time.
     */
    public function update(string $body, bool $enabled, ?string $updatedBy): void
    {
        $this->body = $body;
        $this->enabled = $enabled;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = new \DateTimeImmutable();
    }
}

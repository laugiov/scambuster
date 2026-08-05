<?php

declare(strict_types=1);

namespace App\Domain\Prompt;

use Doctrine\ORM\Mapping as ORM;

/**
 * An asynchronous "validate this prompt" job: run the real-LLM canary over the fixture set
 * with an UNSAVED candidate override body, then compare against the frozen baseline. Created
 * by the API (status PENDING), drained by the dedicated canary worker, and polled by the UI.
 *
 * The candidate body is held here only to feed the one validation run — it is never activated
 * or promoted to a real {@see PromptOverride}. `verdict` is the {@see \App\Application\Guard\PromptCanaryService}
 * output (ok / fingerprint_ok / regressions). See {@see CanaryJobStatus} for the SUCCEEDED-vs-FAILED
 * distinction (a completed run that flags a regression is still SUCCEEDED with `verdict.ok=false`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'prompt_canary_job')]
#[ORM\Index(name: 'idx_prompt_canary_job_status_created', columns: ['status', 'created_at'])]
class PromptCanaryJob
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private int $id = 0;

    #[ORM\Column(name: 'prompt_key', length: 64)]
    private string $promptKey;

    #[ORM\Column(name: 'candidate_body', type: 'text')]
    private string $candidateBody;

    #[ORM\Column(type: 'string', length: 20, enumType: CanaryJobStatus::class)]
    private CanaryJobStatus $status;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $verdict = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(name: 'requested_by', length: 255, nullable: true)]
    private ?string $requestedBy;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(
        string $promptKey,
        string $candidateBody,
        ?string $requestedBy = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->promptKey = $promptKey;
        $this->candidateBody = $candidateBody;
        $this->requestedBy = $requestedBy;
        $this->status = CanaryJobStatus::PENDING;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function markRunning(?\DateTimeImmutable $at = null): void
    {
        $this->status = CanaryJobStatus::RUNNING;
        $this->startedAt = $at ?? new \DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $verdict
     */
    public function markSucceeded(array $verdict, ?\DateTimeImmutable $at = null): void
    {
        $this->status = CanaryJobStatus::SUCCEEDED;
        $this->verdict = $verdict;
        $this->error = null; // terminal states are mutually exclusive — never a stale counterpart
        $this->finishedAt = $at ?? new \DateTimeImmutable();
    }

    public function markFailed(string $error, ?\DateTimeImmutable $at = null): void
    {
        $this->status = CanaryJobStatus::FAILED;
        $this->error = $error;
        $this->verdict = null; // terminal states are mutually exclusive — never a stale counterpart
        $this->finishedAt = $at ?? new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPromptKey(): string
    {
        return $this->promptKey;
    }

    public function getCandidateBody(): string
    {
        return $this->candidateBody;
    }

    public function getStatus(): CanaryJobStatus
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getVerdict(): ?array
    {
        return $this->verdict;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getRequestedBy(): ?string
    {
        return $this->requestedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }
}

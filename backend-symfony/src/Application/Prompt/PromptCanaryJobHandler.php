<?php

declare(strict_types=1);

namespace App\Application\Prompt;

use App\Application\Prompt\Exception\CanaryJobNotFoundException;
use App\Domain\Prompt\PromptCanaryJob;
use App\Domain\Prompt\PromptCanaryJobRepositoryInterface;

/**
 * Application service behind the async "validate this prompt" API: enqueue a canary job for an
 * UNSAVED candidate body (validated by the same rules as a saved override, so a body that could
 * not be saved is never validated), and expose a job for the UI to poll. It never activates or
 * mutates the operator's real override — validation is read-only with respect to it.
 */
final readonly class PromptCanaryJobHandler
{
    public function __construct(
        private PromptCanaryJobRepositoryInterface $jobs,
        private PromptBodyValidator $validator,
    ) {
    }

    /**
     * Validate the candidate and enqueue a PENDING job. Returns the new job id.
     *
     * @throws Exception\UnknownPromptKeyException      unknown key
     * @throws Exception\InvalidPromptOverrideException empty/too-long body or a missing required placeholder
     */
    public function request(string $key, string $candidateBody, ?string $requestedBy): int
    {
        $this->validator->validate($key, $candidateBody);

        $job = new PromptCanaryJob($key, $candidateBody, $requestedBy);
        $this->jobs->save($job);

        return $job->getId();
    }

    /**
     * @throws CanaryJobNotFoundException
     *
     * @return array<string, mixed> the job as the UI polls it
     */
    public function view(int $jobId): array
    {
        $job = $this->jobs->find($jobId);

        if ($job === null) {
            throw new CanaryJobNotFoundException($jobId);
        }

        return $this->row($job);
    }

    /**
     * The most recent job for a key as the UI polls it, plus its candidate body, or null when the
     * key was never validated. On load the UI re-attaches: a running job resumes polling; a
     * terminal verdict is shown only when its candidate_body still equals the saved override, so a
     * verdict for a since-edited prompt is never presented as current.
     *
     * @return array<string, mixed>|null
     */
    public function latestForKey(string $key): ?array
    {
        $job = $this->jobs->findLatestByKey($key);

        if ($job === null) {
            return null;
        }

        return $this->row($job) + ['candidate_body' => $job->getCandidateBody()];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(PromptCanaryJob $job): array
    {
        return [
            'job_id' => $job->getId(),
            'prompt_key' => $job->getPromptKey(),
            'status' => $job->getStatus()->value,
            'verdict' => $job->getVerdict(),
            'error' => $job->getError(),
            'created_at' => $job->getCreatedAt()->format(\DATE_ATOM),
            'started_at' => $job->getStartedAt()?->format(\DATE_ATOM),
            'finished_at' => $job->getFinishedAt()?->format(\DATE_ATOM),
        ];
    }
}

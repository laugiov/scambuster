<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Prompt\CanaryJobStatus;
use App\Domain\Prompt\PromptCanaryJob;
use App\Domain\Prompt\PromptCanaryJobRepositoryInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePromptCanaryJobRepository implements PromptCanaryJobRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function find(int $id): ?PromptCanaryJob
    {
        return $this->em->getRepository(PromptCanaryJob::class)->find($id);
    }

    public function findLatestByKey(string $key): ?PromptCanaryJob
    {
        // Highest id = most recent (SERIAL PK is monotonic, and createdAt has second granularity
        // so two same-second jobs would tie — id is the unambiguous ordering).
        $job = $this->em->createQuery(
            'SELECT j FROM ' . PromptCanaryJob::class . ' j WHERE j.promptKey = :key ORDER BY j.id DESC'
        )
            ->setParameter('key', $key)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return $job instanceof PromptCanaryJob ? $job : null;
    }

    public function save(PromptCanaryJob $job): void
    {
        $this->em->persist($job);
        $this->em->flush();
    }

    public function claimOldestPending(): ?PromptCanaryJob
    {
        $claim = function (): ?PromptCanaryJob {
            $job = $this->em->createQuery(
                'SELECT j FROM ' . PromptCanaryJob::class . ' j WHERE j.status = :pending ORDER BY j.createdAt ASC'
            )
                ->setParameter('pending', CanaryJobStatus::PENDING->value)
                ->setMaxResults(1)
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getOneOrNullResult();

            if (!$job instanceof PromptCanaryJob) {
                return null;
            }

            // Claim it inside the same transaction as the FOR UPDATE lock, so a concurrent
            // worker can never take the same row.
            $job->markRunning();
            $this->em->flush();

            return $job;
        };

        /** @var PromptCanaryJob|null $claimed */
        $claimed = $this->em->wrapInTransaction($claim);

        return $claimed;
    }

    public function failStale(\DateTimeImmutable $threshold): int
    {
        // Bulk terminal-fail: a job RUNNING since before the threshold means its worker died.
        // We fail it (rather than requeue) so an expensive real-LLM run is never silently
        // re-spent; the operator sees the timeout and can resubmit.
        $affected = $this->em->createQuery(
            'UPDATE ' . PromptCanaryJob::class . ' j'
            . ' SET j.status = :failed, j.error = :error, j.finishedAt = :now'
            . ' WHERE j.status = :running AND j.startedAt < :threshold'
        )
            ->setParameter('failed', CanaryJobStatus::FAILED->value)
            ->setParameter('error', 'timed out — the worker did not finish (likely crashed); resubmit to retry')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('running', CanaryJobStatus::RUNNING->value)
            ->setParameter('threshold', $threshold)
            ->execute();

        return is_int($affected) ? $affected : 0;
    }
}

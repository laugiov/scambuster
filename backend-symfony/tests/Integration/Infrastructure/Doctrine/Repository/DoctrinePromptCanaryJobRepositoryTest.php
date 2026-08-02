<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Doctrine\Repository;

use App\Domain\Prompt\CanaryJobStatus;
use App\Domain\Prompt\PromptCanaryJob;
use App\Domain\Prompt\PromptCanaryJobRepositoryInterface;
use App\Infrastructure\Doctrine\Repository\DoctrinePromptCanaryJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for the canary-job store: persistence round-trip and the atomic
 * claim-oldest-pending the worker relies on (FIFO order, and a claimed job is never re-served).
 */
final class DoctrinePromptCanaryJobRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PromptCanaryJobRepositoryInterface $repo;

    /** @var list<int> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        // Instantiate directly: the Domain port alias is inlined away until a consumer injects
        // it (the worker/API in later slices) — the DI binding itself is covered by lint:container.
        $this->repo = new DoctrinePromptCanaryJobRepository($this->em);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds as $id) {
            $job = $this->em->getRepository(PromptCanaryJob::class)->find($id);

            if ($job !== null) {
                $this->em->remove($job);
            }
        }
        $this->em->flush();
        parent::tearDown();
    }

    private function persist(PromptCanaryJob $job): PromptCanaryJob
    {
        $this->repo->save($job);
        $this->createdIds[] = $job->getId();

        return $job;
    }

    public function testSaveAndFindRoundTrip(): void
    {
        $job = $this->persist(new PromptCanaryJob('reward_judge', 'candidate body', 'alice'));

        $this->em->clear();
        $found = $this->repo->find($job->getId());

        self::assertNotNull($found);
        self::assertSame('reward_judge', $found->getPromptKey());
        self::assertSame('candidate body', $found->getCandidateBody());
        self::assertSame(CanaryJobStatus::PENDING, $found->getStatus());
    }

    public function testVerdictJsonRoundTrips(): void
    {
        $job = $this->persist(new PromptCanaryJob('reward_judge', 'body'));
        $verdict = ['ok' => false, 'fingerprint_ok' => true, 'regressions' => [['signal' => 'crypto_wallet', 'delta' => 1.0]]];
        $job->markSucceeded($verdict);
        $this->repo->save($job);

        $this->em->clear();
        $found = $this->repo->find($job->getId());

        self::assertNotNull($found);
        self::assertSame(CanaryJobStatus::SUCCEEDED, $found->getStatus());
        self::assertSame($verdict, $found->getVerdict());
    }

    public function testFindLatestByKeyReturnsTheHighestIdForThatKeyOnly(): void
    {
        // Unique keys keep this isolated from any other rows in the shared test DB.
        $key = '__reattach_test_key';
        $other = '__reattach_other_key';

        $this->persist(new PromptCanaryJob($key, 'first'));
        $otherJob = $this->persist(new PromptCanaryJob($other, 'other'));
        $latest = $this->persist(new PromptCanaryJob($key, 'latest'));

        $found = $this->repo->findLatestByKey($key);

        self::assertNotNull($found);
        self::assertSame($latest->getId(), $found->getId(), 'the most recent (highest id) job for the key');
        self::assertSame('latest', $found->getCandidateBody());
        self::assertNotSame($otherJob->getId(), $found->getId(), 'scoped to the key — another key\'s newer job is ignored');
    }

    public function testFindLatestByKeyReturnsNullForAnUnusedKey(): void
    {
        self::assertNull($this->repo->findLatestByKey('__reattach_never_used_key'));
    }

    public function testClaimReturnsOldestPendingAndMarksItRunning(): void
    {
        $older = $this->persist(new PromptCanaryJob('reward_judge', 'older', null, new \DateTimeImmutable('2020-01-01 00:00:00')));
        $newer = $this->persist(new PromptCanaryJob('reward_judge', 'newer', null, new \DateTimeImmutable('2020-01-02 00:00:00')));

        $claimed = $this->repo->claimOldestPending();

        self::assertNotNull($claimed);
        self::assertSame($older->getId(), $claimed->getId(), 'FIFO: the oldest pending job is claimed first');
        self::assertSame(CanaryJobStatus::RUNNING, $claimed->getStatus());

        $this->em->clear();
        $newerReloaded = $this->repo->find($newer->getId());
        self::assertNotNull($newerReloaded);
        self::assertSame(CanaryJobStatus::PENDING, $newerReloaded->getStatus(), 'the newer job stays queued');
    }

    public function testAClaimedJobIsNotReclaimed(): void
    {
        $job = $this->persist(new PromptCanaryJob('reward_judge', 'only', null, new \DateTimeImmutable('2020-01-01 00:00:00')));

        $first = $this->repo->claimOldestPending();
        self::assertNotNull($first);
        self::assertSame($job->getId(), $first->getId());

        // A second claim must never hand back the already-running job. (Claiming a different,
        // unrelated job from the shared queue is fine.)
        $second = $this->repo->claimOldestPending();

        self::assertTrue($second === null || $second->getId() !== $job->getId());

        if ($second !== null) {
            $this->createdIds[] = $second->getId();
        }
    }

    public function testFailStaleTerminatesAbandonedRunningJobsOnly(): void
    {
        // A job stranded RUNNING since long ago (its worker crashed).
        $stale = new PromptCanaryJob('reward_judge', 'stale');
        $stale->markRunning(new \DateTimeImmutable('2020-01-01 00:00:00'));
        $this->persist($stale);

        // A job that only just started running — must be left alone.
        $fresh = new PromptCanaryJob('reward_judge', 'fresh');
        $fresh->markRunning(new \DateTimeImmutable('2020-06-01 12:00:00'));
        $this->persist($fresh);

        $failed = $this->repo->failStale(new \DateTimeImmutable('2020-06-01 00:00:00'));

        self::assertGreaterThanOrEqual(1, $failed);

        $this->em->clear();
        $staleReloaded = $this->repo->find($stale->getId());
        $freshReloaded = $this->repo->find($fresh->getId());

        self::assertNotNull($staleReloaded);
        self::assertNotNull($freshReloaded);
        self::assertSame(CanaryJobStatus::FAILED, $staleReloaded->getStatus(), 'the abandoned job is terminated');
        self::assertNotNull($staleReloaded->getError());
        self::assertSame(CanaryJobStatus::RUNNING, $freshReloaded->getStatus(), 'a recently-started job is untouched');
    }
}

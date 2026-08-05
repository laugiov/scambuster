<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Domain\Scambaiting\Repository\PersonaPerformanceStatsRepositoryInterface;
use App\Infrastructure\Doctrine\Entity\PersonaPerformanceStatsEntity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for PersonaPerformanceStatsEntity.
 * Manages access to persona performance statistics.
 *
 * @extends ServiceEntityRepository<PersonaPerformanceStatsEntity>
 */
class PersonaPerformanceStatsRepository extends ServiceEntityRepository implements PersonaPerformanceStatsRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonaPerformanceStatsEntity::class);
    }

    /**
     * Retrieves stats for a persona for a given scam_type.
     * Returns null if no stat exists (cold start).
     */
    public function findByPersonaAndScamType(Persona $persona, ScamType $scamType): ?PersonaPerformanceStatsEntity
    {
        return $this->findOneBy([
            'persona' => $persona,
            'scamType' => $scamType,
        ]);
    }

    /**
     * Retrieves all stats for a given scam_type.
     * Used by PersonaOptimizer to select the best persona.
     *
     * @return PersonaPerformanceStatsEntity[]
     */
    public function findAllByScamType(ScamType $scamType): array
    {
        return $this->findBy(
            ['scamType' => $scamType],
            ['rewardAvg' => 'DESC'] // Sort by reward descending
        );
    }

    /**
     * Retrieves all stats for a given persona.
     * Useful for displaying a persona's performance across all scam types.
     *
     * @return PersonaPerformanceStatsEntity[]
     */
    public function findAllByPersona(Persona $persona): array
    {
        return $this->findBy(
            ['persona' => $persona],
            ['rewardAvg' => 'DESC']
        );
    }

    /**
     * Delete all performance stats for a persona (across every scam type), so it
     * re-enters clean cold-start exploration. Used when a persona's system prompt
     * is edited — the accumulated reward was earned by the previous prompt and
     * would otherwise bias the bandit. Idempotent.
     *
     * @return int number of stat rows removed
     */
    public function deleteAllForPersona(Persona $persona): int
    {
        $removed = $this->getEntityManager()
            ->createQuery(
                'DELETE FROM ' . PersonaPerformanceStatsEntity::class . ' pps WHERE pps.persona = :persona'
            )
            ->setParameter('persona', $persona)
            ->execute();

        return is_int($removed) ? $removed : 0;
    }

    /**
     * Retrieves or creates a stats entity.
     * If the entity does not exist, it is created with default values (cold start).
     *
     * IMPORTANT: This method does NOT auto-persist().
     * You must call $em->persist() and $em->flush() afterwards.
     */
    public function findOrCreate(Persona $persona, ScamType $scamType): PersonaPerformanceStatsEntity
    {
        $stats = $this->findByPersonaAndScamType($persona, $scamType);

        if (!$stats instanceof \App\Infrastructure\Doctrine\Entity\PersonaPerformanceStatsEntity) {
            return new PersonaPerformanceStatsEntity(
                persona: $persona,
                scamType: $scamType,
                sessionsCount: 0,
                rewardSum: 0.0,
                rewardAvg: 0.0
            );
        }

        return $stats;
    }

    /**
     * Counts the number of personas in cold start for a scam_type.
     * A persona is in cold start if sessions_count < 3.
     *
     * @param int $coldStartThreshold (default: 3)
     */
    public function countColdStartPersonas(ScamType $scamType, int $coldStartThreshold = 3): int
    {
        $qb = $this->createQueryBuilder('pps');

        return (int) $qb
            ->select('COUNT(pps.persona)')
            ->where('pps.scamType = :scamType')
            ->andWhere('pps.sessionsCount < :threshold')
            ->setParameter('scamType', $scamType)
            ->setParameter('threshold', $coldStartThreshold)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retrieves the best persona (max reward_avg) for a scam_type.
     * Returns null if no stat exists.
     */
    public function findBestPerformingPersona(ScamType $scamType): ?PersonaPerformanceStatsEntity
    {
        $qb = $this->createQueryBuilder('pps');

        /** @var PersonaPerformanceStatsEntity|null $result */
        $result = $qb
            ->where('pps.scamType = :scamType')
            ->setParameter('scamType', $scamType)
            ->orderBy('pps.rewardAvg', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Retrieves the top N personas for a scam_type.
     *
     * @param int $limit (default: 5)
     *
     * @return PersonaPerformanceStatsEntity[]
     */
    public function findTopPerformingPersonas(ScamType $scamType, int $limit = 5): array
    {
        $qb = $this->createQueryBuilder('pps');

        /** @var PersonaPerformanceStatsEntity[] $result */
        $result = $qb
            ->where('pps.scamType = :scamType')
            ->setParameter('scamType', $scamType)
            ->orderBy('pps.rewardAvg', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Returns aggregated statistics for all scam types.
     * Format : [
     *   ['scam_type_code' => 'PHISHING', 'total_sessions' => 42, 'avg_reward' => 0.6543],
     *   ...
     * ]
     *
     * @return array<array{scam_type_code: string, total_sessions: int, avg_reward: float}>
     */
    public function getAggregatedStatsByScamType(): array
    {
        $qb = $this->createQueryBuilder('pps');

        /** @var array<array{scam_type_code: string, total_sessions: string, avg_reward: string}> $results */
        $results = $qb
            ->select(
                'st.code AS scam_type_code',
                'SUM(pps.sessionsCount) AS total_sessions',
                'AVG(pps.rewardAvg) AS avg_reward'
            )
            ->join('pps.scamType', 'st')
            ->groupBy('st.code')
            ->orderBy('avg_reward', 'DESC')
            ->getQuery()
            ->getResult();

        // Type conversion (Doctrine returns strings for aggregates)
        return array_map(
            /**
             * @param array{scam_type_code: string, total_sessions: string, avg_reward: string} $row
             *
             * @return array{scam_type_code: string, total_sessions: int, avg_reward: float}
             */
            static fn (array $row): array => [
                'scam_type_code' => $row['scam_type_code'],
                'total_sessions' => (int) $row['total_sessions'],
                'avg_reward' => (float) $row['avg_reward'],
            ],
            $results
        );
    }

}

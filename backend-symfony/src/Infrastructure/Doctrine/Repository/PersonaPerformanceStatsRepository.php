<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Infrastructure\Doctrine\Entity\PersonaPerformanceStatsEntity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour PersonaPerformanceStatsEntity.
 * Gère l'accès aux statistiques de performance des personas.
 *
 * @extends ServiceEntityRepository<PersonaPerformanceStatsEntity>
 */
class PersonaPerformanceStatsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonaPerformanceStatsEntity::class);
    }

    /**
     * Récupère les stats d'un persona pour un scam_type donné.
     * Retourne null si aucune stat n'existe (cold start).
     */
    public function findByPersonaAndScamType(Persona $persona, ScamType $scamType): ?PersonaPerformanceStatsEntity
    {
        return $this->findOneBy([
            'persona' => $persona,
            'scamType' => $scamType,
        ]);
    }

    /**
     * Récupère toutes les stats pour un scam_type donné.
     * Utilisé par PersonaOptimizer pour sélectionner le meilleur persona.
     *
     * @return PersonaPerformanceStatsEntity[]
     */
    public function findAllByScamType(ScamType $scamType): array
    {
        return $this->findBy(
            ['scamType' => $scamType],
            ['rewardAvg' => 'DESC'] // Tri par reward décroissant
        );
    }

    /**
     * Récupère toutes les stats pour un persona donné.
     * Utile pour afficher la performance d'un persona sur tous les scam types.
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
     * Récupère ou crée une entité stats.
     * Si l'entité n'existe pas, elle est créée avec des valeurs par défaut (cold start).
     *
     * ⚠️ IMPORTANT : Cette méthode ne fait PAS de persist() automatique.
     * Vous devez appeler $em->persist() et $em->flush() après.
     */
    public function findOrCreate(Persona $persona, ScamType $scamType): PersonaPerformanceStatsEntity
    {
        $stats = $this->findByPersonaAndScamType($persona, $scamType);

        if ($stats === null) {
            $stats = new PersonaPerformanceStatsEntity(
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
     * Compte le nombre de personas en cold start pour un scam_type.
     * Un persona est en cold start si sessions_count < 3.
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
     * Récupère le meilleur persona (reward_avg max) pour un scam_type.
     * Retourne null si aucune stat n'existe.
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
     * Récupère les N meilleurs personas pour un scam_type.
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
     * Retourne les statistiques agrégées pour tous les scam types.
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

        // Conversion des types (Doctrine retourne des strings pour les agrégats)
        return array_map(
            /**
             * @param array{scam_type_code: string, total_sessions: string, avg_reward: string} $row
             *
             * @return array{scam_type_code: string, total_sessions: int, avg_reward: float}
             */
            static function (array $row): array {
                return [
                    'scam_type_code' => $row['scam_type_code'],
                    'total_sessions' => (int) $row['total_sessions'],
                    'avg_reward' => (float) $row['avg_reward'],
                ];
            },
            $results
        );
    }

    /**
     * Sauvegarde une entité (raccourci pour persist + flush).
     *
     * @param bool $flush (default: true)
     */
    public function save(PersonaPerformanceStatsEntity $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Supprime une entité (raccourci pour remove + flush).
     *
     * @param bool $flush (default: true)
     */
    public function remove(PersonaPerformanceStatsEntity $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

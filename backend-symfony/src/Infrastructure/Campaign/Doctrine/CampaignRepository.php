<?php

declare(strict_types=1);

namespace App\Infrastructure\Campaign\Doctrine;

use App\Domain\CampaignRadar\Campaign;
use App\Domain\CampaignRadar\CampaignRepositoryInterface;
use App\Domain\CampaignRadar\CampaignStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Campaign>
 */
final class CampaignRepository extends ServiceEntityRepository implements CampaignRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Campaign::class);
    }

    /**
     * Récupère toutes les campagnes actives (shadow + promoted).
     *
     * @return array<Campaign>
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status IN (:statuses)')
            ->setParameter('statuses', [CampaignStatus::Shadow->value, CampaignStatus::Promoted->value])
            ->orderBy('c.firstSeen', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère une campagne par son ID.
     */
    public function findById(Uuid $campaignId): ?Campaign
    {
        return $this->find($campaignId);
    }

    /** @return array<Campaign> */
    public function findByStatus(string $status): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :status')
            ->setParameter('status', $status)
            ->orderBy('c.firstSeen', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les candidats à la promotion (PPV≥0.85, status=shadow).
     * Utilise la vue SQL v_campaign_promotion_candidates.
     *
     * @return array<Campaign>
     */
    public function findPromotionCandidates(): array
    {
        // Utiliser la vue SQL créée en Phase 1
        $sql = 'SELECT campaign_id FROM v_campaign_promotion_candidates ORDER BY ppv DESC, hits_total DESC';

        $conn = $this->getEntityManager()->getConnection();
        $campaignIds = $conn->executeQuery($sql)->fetchFirstColumn();

        if ($campaignIds === []) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->where('c.campaignId IN (:ids)')
            ->setParameter('ids', $campaignIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère tous les messages d'une campagne (via message_campaign).
     *
     * @param Uuid $campaignId ID de la campagne
     * @param int  $limit      Nombre max de messages (défaut 10, max 100)
     *
     * @return array<\App\Domain\Communication\Message>
     */
    public function findMessagesByCampaign(Uuid $campaignId, int $limit = 10): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException("Limit must be between 1 and 100, got {$limit}");
        }

        // Query SQL directe car on croise 2 entités distinctes (Message + MessageCampaign)
        $sql = <<<SQL
            SELECT m.*
            FROM message m
            INNER JOIN message_campaign mc ON m.msg_id = mc.msg_id
            WHERE mc.campaign_id = :campaign_id
            ORDER BY mc.detected_at DESC
            LIMIT :limit
        SQL;

        $conn = $this->getEntityManager()->getConnection();
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('campaign_id', $campaignId->toRfc4122());
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);

        $rows = $stmt->executeQuery()->fetchAllAssociative();

        if ($rows === []) {
            return [];
        }

        // Récupérer les Message entities via EntityManager
        $messageIds = array_map(fn ($row): \Symfony\Component\Uid\Uuid => Uuid::fromString($row['msg_id']), $rows);

        $messageRepo = $this->getEntityManager()->getRepository(\App\Domain\Communication\Message::class);

        return $messageRepo->createQueryBuilder('m')
            ->where('m.msgId IN (:ids)')
            ->setParameter('ids', $messageIds)
            ->getQuery()
            ->getResult();
    }
}

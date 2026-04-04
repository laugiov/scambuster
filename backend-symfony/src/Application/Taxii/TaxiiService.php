<?php

declare(strict_types=1);

namespace App\Application\Taxii;

use Doctrine\ORM\EntityManagerInterface;

/**
 * TAXII 2.1 data retrieval service.
 *
 * Serves discovery, API root, collection metadata and STIX objects
 * for two fixed collections: IOCs and Campaigns.
 */
final class TaxiiService
{
    private const COLLECTION_IOC_ID = 'a1b2c3d4-0001-4000-8000-000000000001';
    private const COLLECTION_CAMPAIGN_ID = 'a1b2c3d4-0002-4000-8000-000000000002';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * TAXII Discovery endpoint data.
     *
     * @return array<string, mixed>
     */
    public function getDiscovery(): array
    {
        return [
            'title' => 'ScamBuster TAXII Server',
            'description' => 'TAXII 2.1 server for ScamBuster threat intelligence',
            'contact' => 'scambuster@localhost',
            'default' => '/api/v1/taxii2/api/',
            'api_roots' => ['/api/v1/taxii2/api/'],
        ];
    }

    /**
     * API Root information.
     *
     * @return array<string, mixed>
     */
    public function getApiRoot(): array
    {
        return [
            'title' => 'ScamBuster API Root',
            'description' => 'Primary API root for ScamBuster threat intelligence',
            'versions' => ['application/taxii+json;version=2.1'],
            'max_content_length' => 10485760,
        ];
    }

    /**
     * List available collections.
     *
     * @return array<string, mixed>
     */
    public function getCollections(): array
    {
        return [
            'collections' => [
                [
                    'id' => self::COLLECTION_IOC_ID,
                    'title' => 'ScamBuster IOCs',
                    'description' => 'Indicators of Compromise collected by ScamBuster honeypot',
                    'can_read' => true,
                    'can_write' => false,
                    'media_types' => ['application/stix+json;version=2.1'],
                ],
                [
                    'id' => self::COLLECTION_CAMPAIGN_ID,
                    'title' => 'ScamBuster Campaigns',
                    'description' => 'Promoted scam campaigns tracked by ScamBuster',
                    'can_read' => true,
                    'can_write' => false,
                    'media_types' => ['application/stix+json;version=2.1'],
                ],
            ],
        ];
    }

    /**
     * Check if a collection ID is valid.
     */
    public function isValidCollection(string $collectionId): bool
    {
        return \in_array($collectionId, [self::COLLECTION_IOC_ID, self::COLLECTION_CAMPAIGN_ID], true);
    }

    /**
     * Retrieve STIX objects for a collection.
     *
     * @return array{envelope: array<string, mixed>, firstAdded: ?string, lastAdded: ?string}
     */
    public function getCollectionObjects(
        string $collectionId,
        ?\DateTimeImmutable $addedAfter = null,
        int $limit = 100,
        ?string $type = null,
    ): array {
        $limit = min(max(1, $limit), 1000);

        if ($collectionId === self::COLLECTION_IOC_ID) {
            return $this->getIocObjects($addedAfter, $limit, $type);
        }

        return $this->getCampaignObjects($addedAfter, $limit);
    }

    /**
     * @return array{envelope: array<string, mixed>, firstAdded: ?string, lastAdded: ?string}
     */
    private function getIocObjects(?\DateTimeImmutable $addedAfter, int $limit, ?string $type): array
    {
        $conn = $this->em->getConnection();

        $qb = $conn->createQueryBuilder()
            ->select('indicator_id', 'type', 'value', 'value_norm', 'first_seen', 'last_seen', 'occurrences', 'enrichment', 'score', 'tlp', 'created_at', 'updated_at')
            ->from('indicator')
            ->orderBy('updated_at', 'ASC')
            ->setMaxResults($limit + 1);

        if ($addedAfter !== null) {
            $qb->andWhere('updated_at > :added_after')
                ->setParameter('added_after', $addedAfter->format('Y-m-d H:i:s'));
        }

        if ($type !== null) {
            $qb->andWhere('type = :type')
                ->setParameter('type', $type);
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        $more = \count($rows) > $limit;

        if ($more) {
            $rows = \array_slice($rows, 0, $limit);
        }

        $objects = [];
        $firstAdded = null;
        $lastAdded = null;

        foreach ($rows as $row) {
            $updatedAt = \is_string($row['updated_at']) ? $row['updated_at'] : '';
            $createdAt = \is_string($row['created_at']) ? $row['created_at'] : '';

            if ($firstAdded === null) {
                $firstAdded = $updatedAt;
            }

            $lastAdded = $updatedAt;

            $objects[] = [
                'type' => 'indicator',
                'spec_version' => '2.1',
                'id' => 'indicator--' . (\is_string($row['indicator_id']) ? $row['indicator_id'] : ''),
                'created' => $this->formatIso8601($createdAt),
                'modified' => $this->formatIso8601($updatedAt),
                'name' => (\is_string($row['type']) ? $row['type'] : '') . ': ' . (\is_string($row['value_norm']) ? $row['value_norm'] : ''),
                'pattern' => $this->buildStixPattern(\is_string($row['type']) ? $row['type'] : '', \is_string($row['value_norm']) ? $row['value_norm'] : ''),
                'pattern_type' => 'stix',
                'valid_from' => $this->formatIso8601(\is_string($row['first_seen']) ? $row['first_seen'] : ''),
                'confidence' => $this->computeConfidence($row),
                'labels' => ['malicious-activity'],
            ];
        }

        return [
            'envelope' => [
                'more' => $more,
                'objects' => $objects,
            ],
            'firstAdded' => $firstAdded !== null ? $this->formatIso8601($firstAdded) : null,
            'lastAdded' => $lastAdded !== null ? $this->formatIso8601($lastAdded) : null,
        ];
    }

    /**
     * @return array{envelope: array<string, mixed>, firstAdded: ?string, lastAdded: ?string}
     */
    private function getCampaignObjects(?\DateTimeImmutable $addedAfter, int $limit): array
    {
        $conn = $this->em->getConnection();

        $qb = $conn->createQueryBuilder()
            ->select('campaign_id', 'status', 'severity', 'tlp', 'first_seen', 'profile_yaml', 'created_at')
            ->from('campaign')
            ->where('status = :status')
            ->setParameter('status', 'promoted')
            ->orderBy('created_at', 'ASC')
            ->setMaxResults($limit + 1);

        if ($addedAfter !== null) {
            $qb->andWhere('created_at > :added_after')
                ->setParameter('added_after', $addedAfter->format('Y-m-d H:i:s'));
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();

        $more = \count($rows) > $limit;

        if ($more) {
            $rows = \array_slice($rows, 0, $limit);
        }

        $objects = [];
        $firstAdded = null;
        $lastAdded = null;

        foreach ($rows as $row) {
            $createdAt = \is_string($row['created_at']) ? $row['created_at'] : '';

            if ($firstAdded === null) {
                $firstAdded = $createdAt;
            }

            $lastAdded = $createdAt;

            $objects[] = [
                'type' => 'campaign',
                'spec_version' => '2.1',
                'id' => 'campaign--' . (\is_string($row['campaign_id']) ? $row['campaign_id'] : ''),
                'created' => $this->formatIso8601($createdAt),
                'modified' => $this->formatIso8601($createdAt),
                'name' => 'ScamBuster Campaign ' . (\is_string($row['campaign_id']) ? $row['campaign_id'] : ''),
                'first_seen' => $this->formatIso8601(\is_string($row['first_seen']) ? $row['first_seen'] : ''),
                'labels' => ['scam'],
            ];
        }

        return [
            'envelope' => [
                'more' => $more,
                'objects' => $objects,
            ],
            'firstAdded' => $firstAdded !== null ? $this->formatIso8601($firstAdded) : null,
            'lastAdded' => $lastAdded !== null ? $this->formatIso8601($lastAdded) : null,
        ];
    }

    private function buildStixPattern(string $type, string $value): string
    {
        $escaped = str_replace("'", "\\'", $value);

        return match ($type) {
            'domain' => "[domain-name:value = '{$escaped}']",
            'url' => "[url:value = '{$escaped}']",
            'ipv4' => "[ipv4-addr:value = '{$escaped}']",
            'ipv6' => "[ipv6-addr:value = '{$escaped}']",
            'email', 'whois_email' => "[email-addr:value = '{$escaped}']",
            'sha256' => "[file:hashes.'SHA-256' = '{$escaped}']",
            'md5' => "[file:hashes.MD5 = '{$escaped}']",
            default => "[x-scambuster:value = '{$escaped}']",
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function computeConfidence(array $row): int
    {
        $occurrences = \is_numeric($row['occurrences'] ?? null) ? (int) $row['occurrences'] : 1;

        // Decode score JSON if present
        $scoreData = [];

        if (\is_string($row['score'] ?? null) && $row['score'] !== '') {
            $decoded = json_decode($row['score'], true);

            if (\is_array($decoded)) {
                $scoreData = $decoded;
            }
        } elseif (\is_array($row['score'] ?? null)) {
            $scoreData = $row['score'];
        }

        $aggScore = isset($scoreData['agg']) && is_numeric($scoreData['agg']) ? (int) $scoreData['agg'] : 0;

        // Base confidence from occurrences
        $confidence = min(50 + $occurrences * 5, 80);

        // Boost from enrichment score
        if ($aggScore > 0) {
            $confidence = min($confidence + (int) ($aggScore / 2), 100);
        }

        return max(0, min(100, $confidence));
    }

    private function formatIso8601(string $value): string
    {
        if ($value === '') {
            return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        }

        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        }
    }
}

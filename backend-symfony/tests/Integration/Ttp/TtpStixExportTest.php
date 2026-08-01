<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ttp;

use App\Application\Clustering\ClusterQueryService;
use App\Application\Stix\ClusteredThreatActorStixBuilder;
use App\Application\Stix\ScambusterStixExtensions;
use App\Application\Taxii\TaxiiService;
use App\Application\Ttp\TtpObservationUpsertService;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end guards for the TTP STIX/TAXII export layer, on the fixture dataset
 * (DAMA rolls every insert back):
 *
 * - the stored verbatim evidence must NEVER reach a generated bundle (FR-5);
 * - the HTTP cluster export and the TAXII feed must emit a byte-equal
 *   x_scambuster_ttp_sighting extension for the same cluster (both paths build
 *   clusterData['ttps'] from the same read model, so this pins the contract).
 */
final class TtpStixExportTest extends KernelTestCase
{
    private const CONV = '00000000-0000-0000-0000-000000000002';
    private const CLUSTER = 'dddddddd-0000-4000-8000-0000000000e1';
    private const THREAT_ACTORS_COLLECTION = 'a1b2c3d4-0003-4000-8000-000000000003';
    private const EVIDENCE_NEEDLE = 'DISTINCTIVE-EVIDENCE-NEEDLE-9F3A';

    private Connection $connection;

    private ClusterQueryService $clusterQueryService;

    private TaxiiService $taxiiService;

    private TtpObservationUpsertService $upsert;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->clusterQueryService = $container->get(ClusterQueryService::class);
        $this->taxiiService = $container->get(TaxiiService::class);
        $this->upsert = new TtpObservationUpsertService($this->connection, new NullLogger());

        $this->connection->executeStatement('DELETE FROM ttp_observation');
    }

    public function testStoredEvidenceNeverLeaksIntoTheClusterBundle(): void
    {
        $this->seedClusterWithConfirmedTtp('SB-T013', self::EVIDENCE_NEEDLE);

        $objects = $this->buildHttpClusterObjects();
        $json = json_encode($objects, \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(
            self::EVIDENCE_NEEDLE,
            $json,
            'ttp_observation.evidence must never appear in a generated STIX bundle.',
        );

        // Meaningful negative: the TTP layer WAS emitted (so the absence above is
        // not a false pass from an empty bundle).
        self::assertNotNull(
            $this->extractSightingExtension($objects, self::CLUSTER),
            'The cluster bundle must carry the TTP sighting for the seeded observation.',
        );
    }

    public function testTaxiiAndHttpEmitByteEqualTtpSightingExtension(): void
    {
        $this->seedClusterWithConfirmedTtp('SB-T013', 'evidence irrelevant to this assertion');

        // HTTP path: the cluster STIX export controller assembles the bundle from
        // getStixExportData() + a fresh ClusteredThreatActorStixBuilder.
        $httpExt = $this->extractSightingExtension($this->buildHttpClusterObjects(), self::CLUSTER);
        self::assertIsArray($httpExt, 'HTTP cluster export must emit the TTP sighting extension.');

        // TAXII path: the threat-actors collection.
        $taxii = $this->taxiiService->getCollectionObjects(self::THREAT_ACTORS_COLLECTION, null, 1000);
        $taxiiObjects = \is_array($taxii['envelope']['objects'] ?? null) ? $taxii['envelope']['objects'] : [];
        $taxiiExt = $this->extractSightingExtension($taxiiObjects, self::CLUSTER);
        self::assertIsArray($taxiiExt, 'TAXII export must emit the TTP sighting extension for the same cluster.');

        ksort($httpExt);
        ksort($taxiiExt);

        self::assertSame(
            $httpExt,
            $taxiiExt,
            'TAXII and HTTP must emit a byte-equal x_scambuster_ttp_sighting for the same cluster.',
        );

        self::assertSame(self::CLUSTER, $taxiiExt['cluster_id']);
        self::assertSame('1.0', $taxiiExt['schema_version']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildHttpClusterObjects(): array
    {
        $exportData = $this->clusterQueryService->getStixExportData(self::CLUSTER);
        self::assertIsArray($exportData);

        return (new ClusteredThreatActorStixBuilder())->buildBundle($exportData);
    }

    /**
     * @param list<array<string, mixed>> $objects
     *
     * @return array<string, mixed>|null
     */
    private function extractSightingExtension(array $objects, string $clusterId): ?array
    {
        foreach ($objects as $obj) {
            if (!\is_array($obj) || ($obj['type'] ?? '') !== 'sighting') {
                continue;
            }

            $ext = $obj['extensions'][ScambusterStixExtensions::TTP_SIGHTING_ID]['x_scambuster_ttp_sighting'] ?? null;

            if (\is_array($ext) && ($ext['cluster_id'] ?? null) === $clusterId) {
                /** @var array<string, mixed> $ext */
                return $ext;
            }
        }

        return null;
    }

    private function seedClusterWithConfirmedTtp(string $code, string $evidence): void
    {
        // A conversation is unique to one cluster: detach the fixture conversation
        // then link it to our test cluster (rolled back by DAMA).
        $this->connection->executeStatement(
            'DELETE FROM threat_actor_cluster_conversation WHERE conv_id = :conv',
            ['conv' => self::CONV],
        );

        $this->connection->executeStatement(
            "INSERT INTO threat_actor_cluster (cluster_id, stix_id, name, status, conversation_count, first_seen, last_seen)
             VALUES (:id, :stix, :name, 'active', 2, NOW(), NOW())",
            [
                'id' => self::CLUSTER,
                'stix' => 'threat-actor--' . self::CLUSTER,
                'name' => 'TTP STIX export test actor',
            ],
        );
        $this->connection->executeStatement(
            'INSERT INTO threat_actor_cluster_conversation (cluster_id, conv_id) VALUES (:id, :conv)',
            ['id' => self::CLUSTER, 'conv' => self::CONV],
        );

        $msgId = $this->connection->fetchOne(
            "SELECT m.msg_id
             FROM message m
             JOIN lkp_direction d ON d.dir_id = m.direction
             WHERE m.conv_id = :conv AND d.code = 'in' AND m.deleted_at IS NULL
             ORDER BY m.ts_msg ASC, m.msg_id ASC
             LIMIT 1",
            ['conv' => self::CONV],
        );
        self::assertIsString($msgId, 'Fixture conversation must have an inbound message.');

        $ttpId = $this->connection->fetchOne('SELECT ttp_id FROM lkp_ttp WHERE code = :code', ['code' => $code]);
        self::assertNotFalse($ttpId, "lkp_ttp must be seeded with {$code}");

        self::assertTrue($this->upsert->upsert([
            'msg_id' => $msgId,
            'conv_id' => self::CONV,
            'ttp_id' => (int) $ttpId,
            'confidence' => 0.9,
            'evidence' => $evidence,
            'evidence_start' => 0,
            'evidence_end' => 8,
            'status' => 'confirmed',
            'taxonomy_version' => '1.0',
            'extraction_model' => 'test-model',
            'prompt_version' => 'v1',
        ]));
    }
}

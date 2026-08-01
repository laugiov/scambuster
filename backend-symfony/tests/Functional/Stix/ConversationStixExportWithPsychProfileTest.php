<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stix;

use App\Application\Clustering\IocClusteringService;
use App\Application\Stix\ConversationStixExportHandler;
use App\Application\ThreatActor\IocFeedbackService;
use App\Domain\ThreatActor\AnalystVerdict;
use App\Tests\Fixtures\ClusteringFixtures;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The clustered threat-actor SDO must carry the x_scambuster_actor_psych extension
 * when the cluster has a persisted psychological profile, and gracefully omit it
 * when it does not.
 */
final class ConversationStixExportWithPsychProfileTest extends WebTestCase
{
    private Connection $conn;
    private ConversationStixExportHandler $handler;

    protected function setUp(): void
    {
        static::createClient();
        $this->conn = static::getContainer()->get(Connection::class);
        $this->handler = static::getContainer()->get(ConversationStixExportHandler::class);

        ClusteringFixtures::cleanup($this->conn);
        ClusteringFixtures::load($this->conn);

        $service = new IocClusteringService($this->conn, new NullLogger());
        $service->clusterConversation(sprintf('cccccccc-aaaa-4000-8000-%012d', 1));
        $service->clusterConversation(sprintf('cccccccc-bbbb-4000-8000-%012d', 1));
    }

    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM threat_actor_psych_profile');
        $this->conn->executeStatement('DELETE FROM ioc_analyst_feedback');
        ClusteringFixtures::cleanup($this->conn);
        parent::tearDown();
    }

    /**
     * @return array{conv: string, cluster: string}
     */
    private function clusteredConversation(): array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT tacc.conv_id, tacc.cluster_id
             FROM threat_actor_cluster_conversation tacc
             JOIN threat_actor_cluster tac ON tac.cluster_id = tacc.cluster_id
             WHERE tac.merged_into_id IS NULL
             LIMIT 1',
        );

        self::assertIsArray($row);
        self::assertIsString($row['conv_id']);
        self::assertIsString($row['cluster_id']);

        return ['conv' => $row['conv_id'], 'cluster' => $row['cluster_id']];
    }

    private function seedProfile(string $clusterId): void
    {
        $this->conn->executeStatement(
            "INSERT INTO threat_actor_psych_profile
                (cluster_id, dominant_lever, secondary_levers, behavioural_summary, escalation_pattern,
                 victim_targeting, dominant_stimulus, avg_urgency, hesitation_events, language_switches,
                 conversation_count, message_count, generated_at, generated_by_model, prompt_version)
             VALUES
                (:cid, 'Urgency', CAST('{Authority}' AS TEXT[]), 'Escalates.', 'rapid',
                 'Holders.', 'fear', 0.7, 1, 0, 2, 10, NOW(), 'gpt-4o-mini', 'v1')",
            ['cid' => $clusterId],
        );
    }

    /**
     * @param array<string, mixed> $bundle
     *
     * @return array<string, mixed>|null
     */
    private function findThreatActor(array $bundle): ?array
    {
        $objects = \is_array($bundle['objects'] ?? null) ? $bundle['objects'] : [];

        foreach ($objects as $obj) {
            if (\is_array($obj) && ($obj['type'] ?? null) === 'threat-actor') {
                return $obj;
            }
        }

        return null;
    }

    public function testThreatActorCarriesPsychExtensionWhenProfileExists(): void
    {
        $ids = $this->clusteredConversation();
        $this->seedProfile($ids['cluster']);

        $bundle = $this->handler->export($ids['conv']);
        $actor = $this->findThreatActor($bundle);

        self::assertNotNull($actor, 'clustered conversation should export a threat-actor');
        self::assertIsArray($actor['extensions'] ?? null);
        self::assertArrayHasKey(\App\Application\Stix\ScambusterStixExtensions::PSYCH_ID, $actor['extensions']);
        self::assertSame('Urgency', $actor['extensions'][\App\Application\Stix\ScambusterStixExtensions::PSYCH_ID]['x_scambuster_actor_psych']['dominant_lever']);
        // The pre-existing actor extension must survive alongside the new one.
        self::assertArrayHasKey(\App\Application\Stix\ScambusterStixExtensions::ACTOR_ID, $actor['extensions']);
    }

    public function testThreatActorOmitsPsychExtensionWhenNoProfile(): void
    {
        $ids = $this->clusteredConversation();

        $bundle = $this->handler->export($ids['conv']);
        $actor = $this->findThreatActor($bundle);

        self::assertNotNull($actor);
        self::assertIsArray($actor['extensions'] ?? null);
        self::assertArrayNotHasKey(\App\Application\Stix\ScambusterStixExtensions::PSYCH_ID, $actor['extensions']);
    }

    public function testExportedBundleContainsObservedDataWithResolvingScoRefs(): void
    {
        // A domain IOC maps to a standard STIX SCO, so its conversation must export
        // an observed-data + domain-name SCO.
        $convId = $this->conn->fetchOne(
            "SELECT DISTINCT tacc.conv_id
             FROM threat_actor_cluster_conversation tacc
             JOIN message m ON m.conv_id = tacc.conv_id
             JOIN observed_ioc oi ON oi.msg_id = m.msg_id
             JOIN indicator i ON i.indicator_id = oi.indicator_id
             WHERE i.type = 'domain'
             LIMIT 1",
        );
        self::assertIsString($convId, 'fixture should have a clustered conversation with a domain IOC');

        $bundle = $this->handler->export($convId);
        $objects = \is_array($bundle['objects'] ?? null) ? $bundle['objects'] : [];

        $scoTypes = ['email-addr', 'domain-name', 'url', 'ipv4-addr', 'ipv6-addr', 'file'];
        $scoIds = [];

        foreach ($objects as $obj) {
            if (\is_array($obj) && \in_array($obj['type'] ?? '', $scoTypes, true) && \is_string($obj['id'] ?? null)) {
                $scoIds[] = $obj['id'];
            }
        }

        $observed = array_values(array_filter(
            $objects,
            static fn ($o) => \is_array($o) && ($o['type'] ?? null) === 'observed-data',
        ));

        self::assertNotEmpty($observed, 'a domain IOC must produce an observed-data SDO');

        foreach ($observed as $od) {
            self::assertGreaterThanOrEqual(1, $od['number_observed']);

            foreach ($od['object_refs'] as $ref) {
                self::assertContains($ref, $scoIds, 'observed-data must reference a SCO present in the bundle');
            }
        }
    }

    public function testFalsePositiveVerdictDropsIndicatorConfidenceInExport(): void
    {
        $row = $this->conn->fetchAssociative(
            "SELECT tacc.conv_id, i.indicator_id
             FROM threat_actor_cluster_conversation tacc
             JOIN message m ON m.conv_id = tacc.conv_id
             JOIN observed_ioc oi ON oi.msg_id = m.msg_id
             JOIN indicator i ON i.indicator_id = oi.indicator_id
             WHERE i.type = 'domain'
             LIMIT 1",
        );
        self::assertIsArray($row);
        $convId = (string) $row['conv_id'];
        $indicatorId = (string) $row['indicator_id'];

        // Submit the verdict through the real service: it upserts the feedback AND persists the
        // confidence onto every observation. The export then reads the persisted score
        // — there is no in-memory export-time fold any more.
        (new IocFeedbackService($this->conn))->submit($indicatorId, AnalystVerdict::FalsePositive, null, 'analyst');

        $bundle = $this->handler->export($convId);
        $objects = \is_array($bundle['objects'] ?? null) ? $bundle['objects'] : [];

        $matched = null;

        foreach ($objects as $obj) {
            if (\is_array($obj) && ($obj['type'] ?? null) === 'indicator'
                && ($obj['external_references'][0]['external_id'] ?? null) === $indicatorId) {
                $matched = $obj;

                break;
            }
        }

        self::assertNotNull($matched, 'the flagged indicator should be in the bundle');
        // false_positive → 0.05 → STIX confidence 5.
        self::assertSame(5, $matched['confidence']);
    }

    public function testExportedBundleContainsSightingSdos(): void
    {
        $ids = $this->clusteredConversation();

        $bundle = $this->handler->export($ids['conv']);
        $objects = \is_array($bundle['objects'] ?? null) ? $bundle['objects'] : [];
        $sightings = array_filter(
            $objects,
            static fn ($o) => \is_array($o) && ($o['type'] ?? null) === 'sighting',
        );

        self::assertNotEmpty($sightings, 'a conversation with IOCs must export sighting SDOs');

        foreach ($sightings as $sighting) {
            self::assertIsInt($sighting['count']);
            self::assertGreaterThanOrEqual(1, $sighting['count']);
            self::assertStringStartsWith('indicator--', (string) $sighting['sighting_of_ref']);
        }
    }
}

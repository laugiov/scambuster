<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ttp;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use App\Domain\Communication\Ttp;

/**
 * Functional coverage of the TTP read endpoints: RBAC, 404 conventions, the
 * FakeLLMClient-driven happy paths, and the offsets-only guarantee — evidence
 * verbatims must never appear in any response body (quotes are reconstructed
 * client-side from message bodies). DB writes are rolled back per test.
 */
final class TtpReadControllersTest extends WebTestCase
{
    private const AUTH = ['HTTP_AUTHORIZATION' => 'Bearer fake-jwt', 'CONTENT_TYPE' => 'application/json'];

    // Fixture data: first conversation's inbound message, plus a soft-deleted
    // conversation and one without any TTP observations.
    private const MSG_INBOUND = '00000000-0000-0000-0000-000000000001';
    private const CONV_SOFT_DELETED = '00000000-0000-0000-0000-000000000004';
    private const CONV_WITHOUT_TTPS = '00000000-0000-0000-0000-000000000003';

    private const CLUSTER = 'dddddddd-0000-4000-8000-0000000000f1';
    private const CLUSTER_EMPTY = 'dddddddd-0000-4000-8000-0000000000f2';
    private const CLUSTER_MATRIX = 'dddddddd-0000-4000-8000-0000000000f3';

    // A deterministic IOC seeded on the inbound message for the co-occurrence pivots.
    private const PIVOT_INDICATOR = 'eeeeeeee-0000-4000-8000-0000000000a1';
    private const PIVOT_VALUE = 'FR76PIVOTTEST00000000000001';
    private const UNKNOWN_UUID = 'ffffffff-ffff-ffff-ffff-ffffffffffff';

    // Evidence verbatims produced by the FakeLLMClient ttp_extraction branch;
    // they are stored in the database and must never surface in any response.
    private const FAKE_EVIDENCE_CONFIRMED = 'act now';
    private const FAKE_EVIDENCE_REVIEW = 'no time for contracts';

    private KernelBrowser $client;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = static::getContainer()->get(Connection::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function get(string $uri, array $server = self::AUTH): void
    {
        $this->client->request('GET', $uri, [], [], $server);
    }

    private function rawResponse(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function assertNoEvidenceLeak(string $raw): void
    {
        self::assertStringNotContainsString(self::FAKE_EVIDENCE_CONFIRMED, $raw);
        self::assertStringNotContainsString(self::FAKE_EVIDENCE_REVIEW, $raw);
        self::assertStringNotContainsString('"evidence":', $raw);
    }

    /** Runs the real extraction pipeline (FakeLLMClient) on the fixture inbound message. */
    private function extractTtpsOnFixtureMessage(): string
    {
        $this->connection->executeStatement(
            'DELETE FROM ttp_observation WHERE msg_id = :msgId',
            ['msgId' => self::MSG_INBOUND]
        );

        $this->client->request(
            'POST',
            '/api/v1/communication/message/' . self::MSG_INBOUND . '/extract-ttps',
            [],
            [],
            self::AUTH,
            '{}'
        );
        $this->assertResponseIsSuccessful();

        $convId = $this->connection->fetchOne(
            'SELECT conv_id FROM message WHERE msg_id = :msgId',
            ['msgId' => self::MSG_INBOUND]
        );
        self::assertIsString($convId);

        return $convId;
    }

    /**
     * Seed a single deterministic IOC on the fixture inbound message, first
     * clearing any fixture-attached IOCs there (IocContextTestFixtures may bind
     * IOCs to this message heap-order-dependently) so pivot counts are exact.
     */
    private function seedIocOnFixtureInboundMessage(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM ioc_context WHERE obs_id IN (SELECT obs_id FROM observed_ioc WHERE msg_id = :msgId)',
            ['msgId' => self::MSG_INBOUND]
        );
        $this->connection->executeStatement(
            'DELETE FROM observed_ioc WHERE msg_id = :msgId',
            ['msgId' => self::MSG_INBOUND]
        );

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->connection->executeStatement(
            'INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)
             VALUES (:id, :type, :value, :valueNorm, :now, :now, 1, \'AMBER\', :now, :now)
             ON CONFLICT (indicator_id) DO NOTHING',
            ['id' => self::PIVOT_INDICATOR, 'type' => 'iban', 'value' => self::PIVOT_VALUE, 'valueNorm' => self::PIVOT_VALUE, 'now' => $now]
        );
        $this->connection->executeStatement(
            'INSERT INTO observed_ioc (obs_id, msg_id, indicator_id, context_observation, ts_observed)
             VALUES (:obsId, :msgId, :indicatorId, :context, :ts)',
            [
                'obsId' => 'bbbbbbbb-a000-4000-8000-000000000001',
                'msgId' => self::MSG_INBOUND,
                'indicatorId' => self::PIVOT_INDICATOR,
                'context' => json_encode(['type' => 'iban', 'value' => self::PIVOT_VALUE, 'value_norm' => self::PIVOT_VALUE]),
                'ts' => $now,
            ]
        );
    }

    // ─── RBAC ──────────────────────────────────────────────────────────

    public function testEndpointsRequireAuthentication(): void
    {
        $unauthenticated = ['CONTENT_TYPE' => 'application/json'];

        $this->get('/api/v1/conversations/' . self::CONV_WITHOUT_TTPS . '/ttps', $unauthenticated);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->get('/api/v1/clusters/' . self::CLUSTER . '/ttps', $unauthenticated);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->get('/api/v1/ttps', $unauthenticated);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // ─── 404 conventions ───────────────────────────────────────────────

    public function testUnknownConversationReturns404(): void
    {
        $this->get('/api/v1/conversations/ffffffff-ffff-ffff-ffff-ffffffffffff/ttps');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('Conversation not found', $this->json()['error']);
    }

    public function testSoftDeletedConversationReturns404(): void
    {
        $this->get('/api/v1/conversations/' . self::CONV_SOFT_DELETED . '/ttps');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('Conversation not found', $this->json()['error']);
    }

    public function testUnknownClusterReturns404(): void
    {
        $this->get('/api/v1/clusters/ffffffff-ffff-ffff-ffff-ffffffffffff/ttps');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('Cluster not found', $this->json()['error']);
    }

    // ─── conversation endpoint ─────────────────────────────────────────

    public function testConversationTtpsCarriesOffsetsAndTimelineWithoutEvidence(): void
    {
        $convId = $this->extractTtpsOnFixtureMessage();

        $this->get('/api/v1/conversations/' . $convId . '/ttps');
        $this->assertResponseIsSuccessful();

        $data = $this->json();
        self::assertSame($convId, $data['conv_id']);

        // FakeLLMClient yields SB-T017 (0.92, confirmed) + SB-T022 (0.4, review).
        $observations = $data['observations'];
        self::assertCount(2, $observations);
        self::assertSame(['SB-T017', 'SB-T022'], array_column($observations, 'ttp_code'));
        self::assertSame(['confirmed', 'review'], array_column($observations, 'status'));

        foreach ($observations as $observation) {
            self::assertArrayNotHasKey('evidence', $observation);
            // Offsets are present (null here: the fake evidence verbatims do
            // not occur in the fixture message text).
            self::assertArrayHasKey('evidence_start', $observation);
            self::assertArrayHasKey('evidence_end', $observation);
            self::assertIsFloat($observation['confidence']);
            self::assertNotSame('', $observation['ttp_label']);
            self::assertNotSame('', $observation['phase']);
        }

        // Timeline covers both directions in chronological order.
        $timeline = $data['timeline'];
        self::assertNotEmpty($timeline);
        $directions = array_unique(array_column($timeline, 'direction'));
        sort($directions);
        self::assertSame(['in', 'out'], $directions);

        $byMsg = array_column($timeline, null, 'msg_id');
        $inboundEntry = $byMsg[self::MSG_INBOUND];
        self::assertSame(['SB-T017', 'SB-T022'], array_column($inboundEntry['ttps'], 'ttp_code'));
        self::assertNull($inboundEntry['stimulus_type']);

        foreach ($timeline as $entry) {
            self::assertArrayHasKey('iocs_revealed', $entry);
            self::assertArrayHasKey('stimulus_type', $entry);
            self::assertArrayHasKey('subject', $entry);

            if ($entry['direction'] === 'out') {
                self::assertSame([], $entry['ttps']);
            }

            foreach ($entry['ttps'] as $ttp) {
                self::assertArrayNotHasKey('evidence', $ttp);
            }
        }

        // The stored verbatims must be absent from the raw payload.
        $this->assertNoEvidenceLeak($this->rawResponse());
    }

    // ─── cluster endpoint ──────────────────────────────────────────────

    public function testClusterTtpsAggregatesConfirmedOnlyWithoutEvidence(): void
    {
        $convId = $this->extractTtpsOnFixtureMessage();

        try {
            $this->connection->executeStatement(
                'INSERT INTO threat_actor_cluster (cluster_id, stix_id, name) VALUES (:cid, :stix, :name)',
                ['cid' => self::CLUSTER, 'stix' => 'threat-actor--' . self::CLUSTER, 'name' => 'TTP HTTP Actor']
            );
            $this->connection->executeStatement(
                'INSERT INTO threat_actor_cluster_conversation (cluster_id, conv_id) VALUES (:cid, :conv)',
                ['cid' => self::CLUSTER, 'conv' => $convId]
            );

            $this->get('/api/v1/clusters/' . self::CLUSTER . '/ttps');
            $this->assertResponseIsSuccessful();

            $data = $this->json();
            self::assertSame(self::CLUSTER, $data['cluster_id']);

            // Confirmed-only aggregates: the review row (SB-T022) is excluded.
            self::assertSame(['SB-T017'], array_column($data['ttps'], 'ttp_code'));

            $ttp = $data['ttps'][0];
            self::assertSame(1, $ttp['observation_count']);
            self::assertSame(1, $ttp['conversation_count']);
            self::assertSame(0.92, $ttp['avg_confidence']);
            self::assertNotNull($ttp['first_seen']);
            self::assertNotNull($ttp['last_seen']);
            self::assertArrayNotHasKey('evidence', $ttp);

            // A single confirmed observation yields no adjacent pair.
            self::assertSame([], $data['top_sequences']);

            $this->assertNoEvidenceLeak($this->rawResponse());
        } finally {
            $this->connection->executeStatement(
                'DELETE FROM threat_actor_cluster WHERE cluster_id = :cid',
                ['cid' => self::CLUSTER]
            );
        }
    }

    public function testClusterWithoutObservationsReturnsEmptyProfile(): void
    {
        try {
            $this->connection->executeStatement(
                'INSERT INTO threat_actor_cluster (cluster_id, stix_id, name) VALUES (:cid, :stix, :name)',
                ['cid' => self::CLUSTER_EMPTY, 'stix' => 'threat-actor--' . self::CLUSTER_EMPTY, 'name' => 'Empty TTP Actor']
            );
            $this->connection->executeStatement(
                'INSERT INTO threat_actor_cluster_conversation (cluster_id, conv_id) VALUES (:cid, :conv)',
                ['cid' => self::CLUSTER_EMPTY, 'conv' => self::CONV_WITHOUT_TTPS]
            );

            $this->get('/api/v1/clusters/' . self::CLUSTER_EMPTY . '/ttps');
            $this->assertResponseIsSuccessful();

            $data = $this->json();
            self::assertSame(self::CLUSTER_EMPTY, $data['cluster_id']);
            self::assertSame([], $data['ttps']);
            self::assertSame([], $data['top_sequences']);
        } finally {
            $this->connection->executeStatement(
                'DELETE FROM threat_actor_cluster WHERE cluster_id = :cid',
                ['cid' => self::CLUSTER_EMPTY]
            );
        }
    }

    // ─── taxonomy endpoint ─────────────────────────────────────────────

    public function testTaxonomyEndpointReturnsAllEntriesIncludingZeroCounts(): void
    {
        // Clean slate so zero-count entries are guaranteed (rolled back).
        $this->connection->executeStatement('DELETE FROM ttp_observation');

        $this->get('/api/v1/ttps');
        $this->assertResponseIsSuccessful();

        $data = $this->json();
        self::assertSame(Ttp::TAXONOMY_VERSION, $data['taxonomy_version']);

        $ttps = $data['ttps'];
        self::assertCount(27, $ttps);

        $codes = array_column($ttps, 'ttp_code');
        self::assertCount(27, array_unique($codes));
        self::assertContains('SB-T001', $codes);
        self::assertContains('SB-T027', $codes);

        foreach ($ttps as $entry) {
            self::assertSame(0, $entry['observation_count']);
            self::assertSame(0, $entry['review_count']);
            self::assertNull($entry['first_seen']);
            self::assertNotSame('', $entry['ttp_label']);
            self::assertNotSame('', $entry['definition']);
            self::assertIsArray($entry['examples']);
            self::assertIsArray($entry['external_refs']);
            self::assertArrayNotHasKey('evidence', $entry);
        }

        // The taxonomy legitimately ships example formulations, and the
        // FakeLLMClient review quote is a substring of an SB-T022 example —
        // so only that one verbatim is exempted here. The confirmed-evidence
        // verbatim and the evidence field itself must still never appear;
        // observation-bearing endpoints keep the full leak check.
        self::assertStringNotContainsString(self::FAKE_EVIDENCE_CONFIRMED, $this->rawResponse());
        self::assertStringNotContainsString('"evidence":', $this->rawResponse());
    }

    // ─── Wave 2b: cluster matrix + IOC<->TTP pivots ────────────────────

    public function testWave2bEndpointsRequireAuthentication(): void
    {
        $unauthenticated = ['CONTENT_TYPE' => 'application/json'];

        $this->get('/api/v1/ttps/cluster-matrix', $unauthenticated);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->get('/api/v1/ttps/SB-T017/iocs', $unauthenticated);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->get('/api/v1/iocs/' . self::PIVOT_INDICATOR . '/ttps', $unauthenticated);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testUnknownTtpCodeReturns404(): void
    {
        $this->get('/api/v1/ttps/SB-T999/iocs');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('TTP not found', $this->json()['error']);
    }

    public function testUnknownIocReturns404(): void
    {
        $this->get('/api/v1/iocs/' . self::UNKNOWN_UUID . '/ttps');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('Indicator not found', $this->json()['error']);
    }

    public function testClusterMatrixResolvesAndAggregatesConfirmedOnly(): void
    {
        $convId = $this->extractTtpsOnFixtureMessage();

        try {
            $this->connection->executeStatement(
                'INSERT INTO threat_actor_cluster (cluster_id, stix_id, name) VALUES (:cid, :stix, :name)',
                ['cid' => self::CLUSTER_MATRIX, 'stix' => 'threat-actor--' . self::CLUSTER_MATRIX, 'name' => 'Matrix Actor']
            );
            $this->connection->executeStatement(
                'INSERT INTO threat_actor_cluster_conversation (cluster_id, conv_id) VALUES (:cid, :conv)',
                ['cid' => self::CLUSTER_MATRIX, 'conv' => $convId]
            );

            $this->get('/api/v1/ttps/cluster-matrix');
            $this->assertResponseIsSuccessful();

            $data = $this->json();

            // Route-resolution proof: the matrix payload shape is present and the
            // taxonomy-list shape is absent, so cluster-matrix is not shadowed by
            // GET /api/v1/ttps.
            self::assertArrayHasKey('clusters', $data);
            self::assertArrayHasKey('cells', $data);
            self::assertArrayHasKey('truncated', $data);
            self::assertArrayHasKey('total_clusters', $data);
            self::assertArrayNotHasKey('taxonomy_version', $data);

            self::assertContains(self::CLUSTER_MATRIX, array_column($data['clusters'], 'cluster_id'));

            // Confirmed-only: SB-T017 is a column, the review-status SB-T022 is not.
            $codes = array_column($data['ttps'], 'ttp_code');
            self::assertContains('SB-T017', $codes);
            self::assertNotContains('SB-T022', $codes);

            // Each cluster row carries its per-conversation denominator, and each
            // cell its distinct-conversation count (the normalization inputs).
            $ourCluster = array_values(array_filter(
                $data['clusters'],
                static fn (array $c): bool => $c['cluster_id'] === self::CLUSTER_MATRIX
            ));
            self::assertCount(1, $ourCluster);
            self::assertArrayHasKey('conversation_total', $ourCluster[0]);
            self::assertSame(1, $ourCluster[0]['conversation_total']);

            $ourCells = array_values(array_filter(
                $data['cells'],
                static fn (array $c): bool => $c['cluster_id'] === self::CLUSTER_MATRIX && $c['ttp_code'] === 'SB-T017'
            ));
            self::assertCount(1, $ourCells);
            self::assertSame(1, $ourCells[0]['count']);
            self::assertSame(1, $ourCells[0]['conversation_count']);

            $this->assertNoEvidenceLeak($this->rawResponse());
        } finally {
            $this->connection->executeStatement(
                'DELETE FROM threat_actor_cluster WHERE cluster_id = :cid',
                ['cid' => self::CLUSTER_MATRIX]
            );
        }
    }

    public function testTtpIocsPivotReturnsCoOccurringIocsWithoutEvidence(): void
    {
        $this->extractTtpsOnFixtureMessage();
        $this->seedIocOnFixtureInboundMessage();

        $this->get('/api/v1/ttps/SB-T017/iocs');
        $this->assertResponseIsSuccessful();

        $data = $this->json();
        self::assertSame('SB-T017', $data['ttp_code']);
        self::assertSame([self::PIVOT_VALUE], array_column($data['iocs'], 'value_norm'));
        self::assertSame(1, $data['iocs'][0]['co_occurrence_count']);
        self::assertSame(1, $data['iocs'][0]['conversation_count']);
        self::assertSame('iban', $data['iocs'][0]['type']);

        // The review-status SB-T022 sits on the same message but yields nothing.
        $this->get('/api/v1/ttps/SB-T022/iocs');
        $this->assertResponseIsSuccessful();
        self::assertSame([], $this->json()['iocs']);

        $this->assertNoEvidenceLeak($this->rawResponse());
    }

    public function testIocTtpsPivotReturnsCoOccurringTtpsWithoutEvidence(): void
    {
        $this->extractTtpsOnFixtureMessage();
        $this->seedIocOnFixtureInboundMessage();

        $this->get('/api/v1/iocs/' . self::PIVOT_INDICATOR . '/ttps');
        $this->assertResponseIsSuccessful();

        $data = $this->json();
        self::assertSame(self::PIVOT_INDICATOR, $data['ioc']);

        // Confirmed-only inverse: SB-T017 only (SB-T022 is review-status).
        self::assertSame(['SB-T017'], array_column($data['ttps'], 'ttp_code'));
        self::assertSame(1, $data['ttps'][0]['co_occurrence_count']);
        self::assertSame(1, $data['ttps'][0]['conversation_count']);
        self::assertNotSame('', $data['ttps'][0]['ttp_label']);

        $this->assertNoEvidenceLeak($this->rawResponse());
    }
}

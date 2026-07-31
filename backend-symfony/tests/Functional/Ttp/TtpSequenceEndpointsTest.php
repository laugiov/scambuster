<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ttp;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP coverage of the TTP sequence surfaces: the per-group top-sequences
 * endpoint (cluster and scam-type grouping, cross-boundary fold, self-pair
 * exclusion, conversation-based minimum-support filtering, invalid-group
 * rejection) and the global phase-transition aggregate. Response shapes, the
 * 401 unauthenticated path (the test harness has no ioc:read-less principal,
 * so a 403 is not exercised) and the offsets-only guarantee (evidence text
 * never leaves the database) are asserted end-to-end. Every row is seeded
 * through DBAL inside the test and rolled back.
 */
final class TtpSequenceEndpointsTest extends WebTestCase
{
    private const AUTH = ['HTTP_AUTHORIZATION' => 'Bearer fake-jwt', 'CONTENT_TYPE' => 'application/json'];

    // Fixture conversations (live) and the inbound message used as a template.
    private const CONV_A = '00000000-0000-0000-0000-000000000002';
    private const CONV_B = '00000000-0000-0000-0000-000000000003';
    private const CONV_C = '00000000-0000-0000-0000-000000000005';

    private const MSG_A_IN = '00000000-0000-0000-0000-000000000002';

    private const CLUSTER_MAIN = 'acacacac-0000-4000-8000-0000000000c1';
    private const CLUSTER_SOLO = 'acacacac-0000-4000-8000-0000000000c2';

    private KernelBrowser $client;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = static::getContainer()->get(Connection::class);

        // Clean slate on the test database (DAMA rolls everything back).
        $this->connection->executeStatement('DELETE FROM ttp_observation');
    }

    // ─── RBAC + validation ─────────────────────────────────────────────

    public function testEndpointsRequireAuthentication(): void
    {
        $unauthenticated = ['CONTENT_TYPE' => 'application/json'];

        $this->get('/api/v1/ttps/sequences', $unauthenticated);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->get('/api/v1/ttps/phase-transitions', $unauthenticated);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testSequencesRejectAnInvalidGroup(): void
    {
        $this->get('/api/v1/ttps/sequences?group=persona');
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('Invalid group', $this->json()['error']);
    }

    // ─── sequences (cluster grouping, the default) ─────────────────────

    public function testSequencesDefaultToClusterGroupingWithCrossBoundaryFold(): void
    {
        // Two conversations of one cluster, each with the same shape:
        // M1 {T001, T011} (+ a review row), M2 {T003, T009}, M3 {T003}.
        // Every cross-boundary pair therefore reaches support 2 across 2
        // conversations; self-pairs and intra-message pairs must never appear.
        foreach ([self::CONV_A => 'acacacac-1000', self::CONV_B => 'acacacac-2000'] as $convId => $prefix) {
            $m1 = $prefix . '-4000-8000-000000000001';
            $m2 = $prefix . '-4000-8000-000000000002';
            $m3 = $prefix . '-4000-8000-000000000003';
            $this->seedMessage($m1, $convId, '2098-06-01 10:00:00');
            $this->seedMessage($m2, $convId, '2098-06-01 11:00:00');
            $this->seedMessage($m3, $convId, '2098-06-01 12:00:00');
            $this->seedObservation($m1, $convId, 'SB-T001', 'confirmed');
            $this->seedObservation($m1, $convId, 'SB-T011', 'confirmed');
            $this->seedObservation($m1, $convId, 'SB-T005', 'review');
            $this->seedObservation($m2, $convId, 'SB-T003', 'confirmed');
            $this->seedObservation($m2, $convId, 'SB-T009', 'confirmed');
            $this->seedObservation($m3, $convId, 'SB-T003', 'confirmed');
        }

        $this->insertCluster(self::CLUSTER_MAIN, 'Sequence Actor', 'active');
        $this->linkConv(self::CLUSTER_MAIN, self::CONV_A);
        $this->linkConv(self::CLUSTER_MAIN, self::CONV_B);

        // A second cluster whose only pair stays below the support threshold:
        // its group is omitted entirely.
        $c1 = 'acacacac-3000-4000-8000-000000000001';
        $c2 = 'acacacac-3000-4000-8000-000000000002';
        $this->seedMessage($c1, self::CONV_C, '2098-06-02 10:00:00');
        $this->seedMessage($c2, self::CONV_C, '2098-06-02 11:00:00');
        $this->seedObservation($c1, self::CONV_C, 'SB-T017', 'confirmed');
        $this->seedObservation($c2, self::CONV_C, 'SB-T024', 'confirmed');
        $this->insertCluster(self::CLUSTER_SOLO, 'Solo Actor', 'active');
        $this->linkConv(self::CLUSTER_SOLO, self::CONV_C);

        $this->get('/api/v1/ttps/sequences');
        $this->assertResponseIsSuccessful();

        $data = $this->json();
        self::assertSame(2, $data['min_support']);
        self::assertFalse($data['truncated']);

        // Only the cluster whose pairs clear the threshold forms a group.
        self::assertSame([self::CLUSTER_MAIN], array_column($data['groups'], 'key'));

        $group = $data['groups'][0];
        self::assertSame('Sequence Actor', $group['label']);

        // Full cross-product of the two multi-TTP adjacent sets (minus the
        // self-pair) plus the M2 → M3 pairs, each seen once per conversation.
        self::assertSame(
            [
                ['sequence' => ['SB-T001', 'SB-T003'], 'count' => 2, 'conversation_count' => 2],
                ['sequence' => ['SB-T001', 'SB-T009'], 'count' => 2, 'conversation_count' => 2],
                ['sequence' => ['SB-T009', 'SB-T003'], 'count' => 2, 'conversation_count' => 2],
                ['sequence' => ['SB-T011', 'SB-T003'], 'count' => 2, 'conversation_count' => 2],
                ['sequence' => ['SB-T011', 'SB-T009'], 'count' => 2, 'conversation_count' => 2],
            ],
            $group['sequences']
        );

        $keys = array_map(
            static fn (array $entry): string => implode('>', $entry['sequence']),
            $group['sequences']
        );
        // Self-pair (T003 in both adjacent sets) excluded as noise.
        self::assertNotContains('SB-T003>SB-T003', $keys);
        // Intra-message co-occurrences never become pairs.
        self::assertNotContains('SB-T001>SB-T011', $keys);
        self::assertNotContains('SB-T011>SB-T001', $keys);
        self::assertNotContains('SB-T003>SB-T009', $keys);
        // The review-status row contributes nothing.
        self::assertStringNotContainsString('SB-T005', (string) json_encode($keys));

        // Offsets-only guarantee: the stored verbatim never leaves the DB.
        $raw = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('"evidence":', $raw);
        self::assertStringNotContainsString('seeded queue evidence', $raw);

        // The explicit ?group=cluster answer is byte-identical to the default.
        $this->get('/api/v1/ttps/sequences?group=cluster');
        $this->assertResponseIsSuccessful();
        self::assertSame($data, $this->json());
    }

    // ─── sequences (scam-type grouping) ────────────────────────────────

    public function testSequencesGroupByScamTypeDropSingleConversationRepeats(): void
    {
        // One conversation alternating T001/T003 across four messages: the
        // T001 → T003 pair recurs across non-adjacent boundaries (occurrences
        // 2) but is confined to this single conversation, so conversation-based
        // support (1) never clears the threshold and no group surfaces.
        $codes = ['SB-T001', 'SB-T003', 'SB-T001', 'SB-T003'];
        foreach ($codes as $i => $code) {
            $msgId = sprintf('acacacac-4000-4000-8000-%012d', $i + 1);
            $this->seedMessage($msgId, self::CONV_C, sprintf('2098-06-03 %02d:00:00', 10 + $i));
            $this->seedObservation($msgId, self::CONV_C, $code, 'confirmed');
        }

        $this->get('/api/v1/ttps/sequences?group=scam_type');
        $this->assertResponseIsSuccessful();

        $data = $this->json();
        self::assertSame(2, $data['min_support']);
        self::assertSame([], $data['groups']);
    }

    public function testSequencesGroupByScamTypeCountExceedsConversationCount(): void
    {
        // CONV_C: {T001},{T003},{T001},{T003} → T001 → T003 twice in one conv.
        $codes = ['SB-T001', 'SB-T003', 'SB-T001', 'SB-T003'];
        foreach ($codes as $i => $code) {
            $msgId = sprintf('acacacac-4000-4000-8000-%012d', $i + 1);
            $this->seedMessage($msgId, self::CONV_C, sprintf('2098-06-03 %02d:00:00', 10 + $i));
            $this->seedObservation($msgId, self::CONV_C, $code, 'confirmed');
        }

        // CONV_A shares the same scam type (all fixtures are UNKNOWN) and
        // contributes T001 → T003 once, lifting the pair to 2 conversations so
        // it clears the threshold while its occurrence count (3) exceeds its
        // conversation support (2).
        $a1 = 'acacacac-4100-4000-8000-000000000001';
        $a2 = 'acacacac-4100-4000-8000-000000000002';
        $this->seedMessage($a1, self::CONV_A, '2098-06-03 10:00:00');
        $this->seedMessage($a2, self::CONV_A, '2098-06-03 11:00:00');
        $this->seedObservation($a1, self::CONV_A, 'SB-T001', 'confirmed');
        $this->seedObservation($a2, self::CONV_A, 'SB-T003', 'confirmed');

        $scamType = $this->connection->fetchAssociative(
            'SELECT st.code, st.label
             FROM conversation c
             JOIN lkp_scam_type st ON st.scam_type_id = c.scam_type_id
             WHERE c.conv_id = :conv',
            ['conv' => self::CONV_C]
        );
        self::assertIsArray($scamType);

        $this->get('/api/v1/ttps/sequences?group=scam_type');
        $this->assertResponseIsSuccessful();

        $data = $this->json();
        self::assertSame(2, $data['min_support']);
        self::assertFalse($data['truncated']);
        self::assertSame([$scamType['code']], array_column($data['groups'], 'key'));

        $group = $data['groups'][0];
        self::assertSame($scamType['label'], $group['label']);
        // Divergence: occurrences (2 in CONV_C + 1 in CONV_A) exceed the
        // conversation support (2); T003>T001 (support 1) is dropped.
        self::assertSame(
            [['sequence' => ['SB-T001', 'SB-T003'], 'count' => 3, 'conversation_count' => 2]],
            $group['sequences']
        );
    }

    public function testSequencesAnswerEmptyGroupsWithoutObservations(): void
    {
        // setUp wiped ttp_observation and nothing is seeded here.
        $this->get('/api/v1/ttps/sequences');
        $this->assertResponseIsSuccessful();
        self::assertSame(['groups' => [], 'min_support' => 2, 'truncated' => false], $this->json());

        $this->get('/api/v1/ttps/sequences?group=scam_type');
        $this->assertResponseIsSuccessful();
        self::assertSame(['groups' => [], 'min_support' => 2, 'truncated' => false], $this->json());
    }

    // ─── phase transitions ─────────────────────────────────────────────

    public function testPhaseTransitionsAggregateConfirmedBigramsByPhase(): void
    {
        // CONV_A: {T001} → {T017} → {T001} = hook→escalation + escalation→hook.
        $a1 = 'acacacac-5000-4000-8000-000000000001';
        $a2 = 'acacacac-5000-4000-8000-000000000002';
        $a3 = 'acacacac-5000-4000-8000-000000000003';
        $this->seedMessage($a1, self::CONV_A, '2098-06-04 10:00:00');
        $this->seedMessage($a2, self::CONV_A, '2098-06-04 11:00:00');
        $this->seedMessage($a3, self::CONV_A, '2098-06-04 12:00:00');
        $this->seedObservation($a1, self::CONV_A, 'SB-T001', 'confirmed');
        $this->seedObservation($a2, self::CONV_A, 'SB-T017', 'confirmed');
        $this->seedObservation($a3, self::CONV_A, 'SB-T001', 'confirmed');

        // CONV_B: {T001} → {T003} = hook→hook, plus two rows that must not
        // count: a review observation on the first message (would fabricate a
        // channel-switch transition) and a confirmed one on a soft-deleted
        // message (would fabricate hook→escalation).
        $b1 = 'acacacac-5000-4000-8000-000000000004';
        $b2 = 'acacacac-5000-4000-8000-000000000005';
        $b3 = 'acacacac-5000-4000-8000-000000000006';
        $this->seedMessage($b1, self::CONV_B, '2098-06-05 10:00:00');
        $this->seedMessage($b2, self::CONV_B, '2098-06-05 11:00:00');
        $this->seedMessage($b3, self::CONV_B, '2098-06-05 12:00:00', deletedAt: '2098-06-06 00:00:00');
        $this->seedObservation($b1, self::CONV_B, 'SB-T001', 'confirmed');
        $this->seedObservation($b1, self::CONV_B, 'SB-T024', 'review');
        $this->seedObservation($b2, self::CONV_B, 'SB-T003', 'confirmed');
        $this->seedObservation($b3, self::CONV_B, 'SB-T017', 'confirmed');

        $this->get('/api/v1/ttps/phase-transitions');
        $this->assertResponseIsSuccessful();

        $data = $this->json();
        self::assertSame(3, $data['total_pairs']);
        self::assertSame(
            [
                ['from_phase' => 'hook', 'to_phase' => 'hook', 'count' => 1],
                ['from_phase' => 'hook', 'to_phase' => 'escalation', 'count' => 1],
                ['from_phase' => 'escalation', 'to_phase' => 'hook', 'count' => 1],
            ],
            $data['transitions']
        );
    }

    public function testPhaseTransitionsAnswerEmptyWithoutObservations(): void
    {
        // setUp wiped ttp_observation and nothing is seeded here.
        $this->get('/api/v1/ttps/phase-transitions');
        $this->assertResponseIsSuccessful();
        self::assertSame(['transitions' => [], 'total_pairs' => 0], $this->json());
    }

    // ─── helpers ───────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * @param array<string, string> $server
     */
    private function get(string $uri, array $server = self::AUTH): void
    {
        $this->client->request('GET', $uri, [], [], $server);
    }

    /**
     * Insert a message into a fixture conversation, cloning channel and
     * direction from the fixture inbound message.
     */
    private function seedMessage(string $msgId, string $convId, string $tsMsg, ?string $deletedAt = null): void
    {
        $template = $this->connection->fetchAssociative(
            'SELECT channel_id, direction FROM message WHERE msg_id = :msgId',
            ['msgId' => self::MSG_A_IN]
        );
        self::assertIsArray($template);

        $this->connection->executeStatement(
            'INSERT INTO message (msg_id, conv_id, channel_id, direction, lang_detect, subject, body_text, headers, composite_hash, ts_msg, ts_ingest, deleted_at)
             VALUES (:msgId, :convId, :channelId, :direction, :lang, :subject, :body, :headers, :hash, :ts, :ts, :deletedAt)',
            [
                'msgId' => $msgId,
                'convId' => $convId,
                'channelId' => $template['channel_id'],
                'direction' => $template['direction'],
                'lang' => 'en',
                'subject' => 'Seeded sequence inbound',
                'body' => 'Seeded sequence body',
                'headers' => '{}',
                'hash' => bin2hex(random_bytes(32)),
                'ts' => $tsMsg,
                'deletedAt' => $deletedAt,
            ]
        );
    }

    private function seedObservation(string $msgId, string $convId, string $code, string $status): void
    {
        $this->connection->executeStatement(
            "INSERT INTO ttp_observation (msg_id, conv_id, ttp_id, confidence, evidence, evidence_start, evidence_end, status, taxonomy_version, extraction_model, prompt_version)
             VALUES (:msgId, :convId, :ttpId, 0.9, 'seeded queue evidence', NULL, NULL, :status, '1.0', 'seed-model', 'v1')",
            [
                'msgId' => $msgId,
                'convId' => $convId,
                'ttpId' => $this->ttpId($code),
                'status' => $status,
            ]
        );
    }

    private function insertCluster(string $clusterId, string $name, string $status): void
    {
        $this->connection->executeStatement(
            'INSERT INTO threat_actor_cluster (cluster_id, stix_id, name, status) VALUES (:id, :stix, :name, :status)',
            ['id' => $clusterId, 'stix' => 'threat-actor--' . $clusterId, 'name' => $name, 'status' => $status]
        );
    }

    private function linkConv(string $clusterId, string $convId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO threat_actor_cluster_conversation (cluster_id, conv_id) VALUES (:id, :conv)',
            ['id' => $clusterId, 'conv' => $convId]
        );
    }

    private function ttpId(string $code): int
    {
        $ttpId = $this->connection->fetchOne('SELECT ttp_id FROM lkp_ttp WHERE code = :code', ['code' => $code]);
        self::assertNotFalse($ttpId, sprintf('lkp_ttp must be seeded with %s', $code));

        return (int) $ttpId;
    }
}

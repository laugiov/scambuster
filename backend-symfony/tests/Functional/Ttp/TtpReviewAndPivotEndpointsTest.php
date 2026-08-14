<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ttp;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP coverage of the TTP review-queue, phase-trend and per-TTP
 * cluster/conversation pivots, plus the taxonomy payload extension
 * (examples/external refs) and the deterministic message ordering tiebreak:
 * RBAC, response shapes, caps, pagination bounds, merged-cluster exclusion
 * and the offsets-only guarantee (evidence text never leaves the database).
 * Every row is seeded through DBAL inside the test and rolled back.
 */
final class TtpReviewAndPivotEndpointsTest extends WebTestCase
{
    private const AUTH = ['HTTP_AUTHORIZATION' => 'Bearer fake-jwt', 'CONTENT_TYPE' => 'application/json'];

    // Fixture conversations: three live ones, plus a soft-deleted one.
    private const CONV_A = '00000000-0000-0000-0000-000000000002';
    private const CONV_B = '00000000-0000-0000-0000-000000000003';
    private const CONV_C = '00000000-0000-0000-0000-000000000005';
    private const CONV_SOFT_DELETED = '00000000-0000-0000-0000-000000000004';

    // Fixture inbound messages of those conversations.
    private const MSG_A_IN = '00000000-0000-0000-0000-000000000002';
    private const MSG_SOFT_DELETED_CONV_IN = '00000000-0000-0000-0000-000000000004';

    // Caps; must stay in sync with the TtpQueryService constants.
    private const REVIEW_QUEUE_CAP = 500;
    private const CONVERSATIONS_PAGE_MAX = 100;

    private const TAXONOMY_SIZE = 27;

    private const CANONICAL_PHASES = ['hook', 'trust-building', 'payment-request', 'escalation', 'channel-switch', 'exit'];

    private KernelBrowser $client;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = static::getContainer()->get(Connection::class);

        // Clean slate on the test database (DAMA rolls everything back).
        $this->connection->executeStatement('DELETE FROM ttp_observation');
    }

    // ─── RBAC + 404 conventions ────────────────────────────────────────

    public function testEndpointsRequireAuthentication(): void
    {
        $unauthenticated = ['CONTENT_TYPE' => 'application/json'];

        $this->get('/api/v1/ttps/review-queue', $unauthenticated);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->get('/api/v1/ttps/phase-trend', $unauthenticated);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->get('/api/v1/ttps/SB-T001/clusters', $unauthenticated);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->get('/api/v1/ttps/SB-T001/conversations', $unauthenticated);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testUnknownTtpCodeReturns404OnPivots(): void
    {
        $this->get('/api/v1/ttps/SB-T999/clusters');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('TTP not found', $this->json()['error']);

        $this->get('/api/v1/ttps/SB-T999/conversations');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('TTP not found', $this->json()['error']);
    }

    // ─── review queue ──────────────────────────────────────────────────

    public function testReviewQueueListsOnlyLiveReviewRowsNewestFirst(): void
    {
        $msgOlder = 'abababab-0000-4000-8000-000000000001';
        $msgNewer = 'abababab-0000-4000-8000-000000000002';
        $msgPurged = 'abababab-0000-4000-8000-000000000003';

        $this->seedMessage($msgOlder, self::CONV_A, '2098-01-01 10:00:00');
        $this->seedObservation($msgOlder, self::CONV_A, 'SB-T005', 'review', 0.4, 0, 8, 'model-one');

        $this->seedMessage($msgNewer, self::CONV_A, '2098-01-01 11:00:00');
        $this->seedObservation($msgNewer, self::CONV_A, 'SB-T003', 'review', 0.35, null, null, 'model-two');

        // Excluded: confirmed status, soft-deleted message, soft-deleted conversation.
        $this->seedObservation(self::MSG_A_IN, self::CONV_A, 'SB-T001', 'confirmed');
        $this->seedMessage($msgPurged, self::CONV_A, '2098-01-01 12:00:00', deletedAt: '2098-01-02 00:00:00');
        $this->seedObservation($msgPurged, self::CONV_A, 'SB-T007', 'review');
        $this->seedObservation(self::MSG_SOFT_DELETED_CONV_IN, self::CONV_SOFT_DELETED, 'SB-T008', 'review');

        $this->get('/api/v1/ttps/review-queue');
        $this->assertResponseIsSuccessful();

        $data = $this->json();
        self::assertSame(2, $data['total']);

        $items = $data['items'];
        self::assertCount(2, $items);

        // Newest message first.
        self::assertSame(['SB-T003', 'SB-T005'], array_column($items, 'ttp_code'));

        $older = $items[1];
        self::assertSame('SB-T005', $older['ttp_code']);
        self::assertNotSame('', $older['ttp_label']);
        self::assertSame('trust-building', $older['phase']);
        self::assertSame(0.4, $older['confidence']);
        self::assertSame(self::CONV_A, $older['conv_id']);
        self::assertSame($msgOlder, $older['msg_id']);
        self::assertStringStartsWith('2098-01-01T10:00:00', (string) $older['ts_msg']);
        self::assertSame(0, $older['evidence_start']);
        self::assertSame(8, $older['evidence_end']);
        self::assertSame('model-one', $older['extraction_model']);

        $newer = $items[0];
        self::assertNull($newer['evidence_start']);
        self::assertNull($newer['evidence_end']);
        self::assertSame('model-two', $newer['extraction_model']);

        // Offsets only — the stored verbatim never leaves the database.
        $raw = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('"evidence":', $raw);
        self::assertStringNotContainsString('seeded queue evidence', $raw);
    }

    public function testReviewQueueCapsItemsAndReportsFullTotal(): void
    {
        $rowCount = self::REVIEW_QUEUE_CAP + 1;
        $this->seedBulkReviewObservations($rowCount);

        $this->get('/api/v1/ttps/review-queue');
        $this->assertResponseIsSuccessful();

        $data = $this->json();
        self::assertCount(self::REVIEW_QUEUE_CAP, $data['items']);
        self::assertSame($rowCount, $data['total']);
    }

    // ─── phase trend ───────────────────────────────────────────────────

    public function testPhaseTrendZeroFillsAllWeeksAndPhases(): void
    {
        // setUp wiped ttp_observation and nothing is seeded here.
        $expectedBefore = $this->weekStart(0)->format('Y-m-d');
        $this->get('/api/v1/ttps/phase-trend');
        $expectedAfter = $this->weekStart(0)->format('Y-m-d');
        $this->assertResponseIsSuccessful();

        $weeks = $this->json()['weeks'];
        self::assertCount(8, $weeks);

        $previous = null;

        foreach ($weeks as $entry) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $entry['week']);

            if ($previous !== null) {
                self::assertGreaterThan($previous, $entry['week'], 'weeks must ascend');
            }

            $previous = $entry['week'];

            self::assertSame(self::CANONICAL_PHASES, array_keys($entry['counts']));
            self::assertSame(array_fill_keys(self::CANONICAL_PHASES, 0), $entry['counts']);
        }

        // The newest bucket is the current ISO week (tolerating a week
        // rollover between request and assertion).
        self::assertContains($weeks[7]['week'], [$expectedBefore, $expectedAfter]);
    }

    public function testPhaseTrendBucketsConfirmedOnlyOnMessageTimestamp(): void
    {
        $msgCurrentWeek = 'abababab-2000-4000-8000-000000000001';
        $msgCurrentWeekReview = 'abababab-2000-4000-8000-000000000002';
        $msgFiveWeeksAgo = 'abababab-2000-4000-8000-000000000003';
        $msgTenWeeksAgo = 'abababab-2000-4000-8000-000000000004';

        $currentWeekKey = $this->weekStart(0)->format('Y-m-d');
        $fiveWeeksAgoKey = $this->weekStart(5)->format('Y-m-d');

        $this->seedMessage($msgCurrentWeek, self::CONV_A, $this->weekStart(0)->modify('+2 days')->format('Y-m-d 10:00:00'));
        $this->seedObservation($msgCurrentWeek, self::CONV_A, 'SB-T001', 'confirmed');

        // Same hook phase but review status: must not count.
        $this->seedMessage($msgCurrentWeekReview, self::CONV_A, $this->weekStart(0)->modify('+2 days')->format('Y-m-d 11:00:00'));
        $this->seedObservation($msgCurrentWeekReview, self::CONV_A, 'SB-T003', 'review');

        $this->seedMessage($msgFiveWeeksAgo, self::CONV_A, $this->weekStart(5)->modify('+1 day')->format('Y-m-d 10:00:00'));
        $this->seedObservation($msgFiveWeeksAgo, self::CONV_A, 'SB-T017', 'confirmed');

        // Outside the 8-week window: silently ignored.
        $this->seedMessage($msgTenWeeksAgo, self::CONV_A, $this->weekStart(10)->modify('+1 day')->format('Y-m-d 10:00:00'));
        $this->seedObservation($msgTenWeeksAgo, self::CONV_A, 'SB-T001', 'confirmed');

        $this->get('/api/v1/ttps/phase-trend');
        $this->assertResponseIsSuccessful();

        $weeks = $this->json()['weeks'];
        $byWeek = array_column($weeks, 'counts', 'week');

        self::assertArrayHasKey($currentWeekKey, $byWeek);
        self::assertSame(1, $byWeek[$currentWeekKey]['hook']);
        self::assertSame(0, $byWeek[$currentWeekKey]['escalation']);

        self::assertArrayHasKey($fiveWeeksAgoKey, $byWeek);
        self::assertSame(1, $byWeek[$fiveWeeksAgoKey]['escalation']);

        // Across all buckets: only the in-window confirmed hook observation
        // counts (the review row and the 10-week-old one never appear).
        $totalHook = array_sum(array_map(static fn (array $entry): int => $entry['counts']['hook'], $weeks));
        self::assertSame(1, $totalHook);
    }

    // ─── clusters pivot ────────────────────────────────────────────────

    public function testClustersForTtpConfirmedOnlyExcludesMergedAndFallsBackLabel(): void
    {
        $clusterActive = 'dededede-0000-4000-8000-000000000001';
        $clusterMerged = 'dededede-0000-4000-8000-000000000002';
        $clusterNameless = 'dededede-0000-4000-8000-000000000003';

        // CONV_A: two confirmed + one review observation of SB-T001.
        $msgA1 = 'abababab-3000-4000-8000-000000000001';
        $msgA2 = 'abababab-3000-4000-8000-000000000002';
        $msgA3 = 'abababab-3000-4000-8000-000000000003';
        $this->seedMessage($msgA1, self::CONV_A, '2098-02-01 10:00:00');
        $this->seedObservation($msgA1, self::CONV_A, 'SB-T001', 'confirmed');
        $this->seedMessage($msgA2, self::CONV_A, '2098-02-03 10:00:00');
        $this->seedObservation($msgA2, self::CONV_A, 'SB-T001', 'confirmed');
        $this->seedMessage($msgA3, self::CONV_A, '2098-02-05 10:00:00');
        $this->seedObservation($msgA3, self::CONV_A, 'SB-T001', 'review');

        // CONV_B carries a confirmed observation but belongs to a merged cluster.
        $msgB1 = 'abababab-3000-4000-8000-000000000004';
        $this->seedMessage($msgB1, self::CONV_B, '2098-02-02 10:00:00');
        $this->seedObservation($msgB1, self::CONV_B, 'SB-T001', 'confirmed');

        // CONV_C belongs to an unnamed cluster (label falls back to the id prefix).
        $msgC1 = 'abababab-3000-4000-8000-000000000005';
        $this->seedMessage($msgC1, self::CONV_C, '2098-02-04 10:00:00');
        $this->seedObservation($msgC1, self::CONV_C, 'SB-T001', 'confirmed');

        $this->insertCluster($clusterActive, 'Pivot Actor', 'active');
        $this->linkConv($clusterActive, self::CONV_A);
        $this->insertCluster($clusterMerged, 'Merged Actor', 'merged');
        $this->linkConv($clusterMerged, self::CONV_B);
        $this->insertCluster($clusterNameless, '', 'active');
        $this->linkConv($clusterNameless, self::CONV_C);

        $this->get('/api/v1/ttps/SB-T001/clusters');
        $this->assertResponseIsSuccessful();

        $data = $this->json();
        self::assertFalse($data['truncated']);

        // Merged cluster excluded; conversation-count tie broken by
        // observation count (2 vs 1).
        self::assertSame([$clusterActive, $clusterNameless], array_column($data['items'], 'cluster_id'));

        $active = $data['items'][0];
        self::assertSame('Pivot Actor', $active['label']);
        self::assertSame(2, $active['observation_count']);
        self::assertSame(1, $active['conversation_count']);
        self::assertSame('2098-02-01T10:00:00+00:00', $active['first_seen']);
        // The review row (2098-02-05) must not extend last_seen.
        self::assertSame('2098-02-03T10:00:00+00:00', $active['last_seen']);

        $nameless = $data['items'][1];
        self::assertSame(substr($clusterNameless, 0, 8), $nameless['label']);
        self::assertSame(1, $nameless['observation_count']);
    }

    // ─── conversations pivot ───────────────────────────────────────────

    public function testConversationsForTtpPaginatesWithCountsSubjectAndScamType(): void
    {
        // CONV_A: one confirmed + one review observation (review is the newest).
        $msgA1 = 'abababab-4000-4000-8000-000000000001';
        $msgA2 = 'abababab-4000-4000-8000-000000000002';
        $this->seedMessage($msgA1, self::CONV_A, '2098-03-01 10:00:00');
        $this->seedObservation($msgA1, self::CONV_A, 'SB-T001', 'confirmed');
        $this->seedMessage($msgA2, self::CONV_A, '2098-03-05 10:00:00');
        $this->seedObservation($msgA2, self::CONV_A, 'SB-T001', 'review');

        // CONV_B: confirmed only; a later soft-deleted message must not
        // extend last_seen.
        $msgB1 = 'abababab-4000-4000-8000-000000000003';
        $msgB2 = 'abababab-4000-4000-8000-000000000004';
        $this->seedMessage($msgB1, self::CONV_B, '2098-03-02 10:00:00');
        $this->seedObservation($msgB1, self::CONV_B, 'SB-T001', 'confirmed');
        $this->seedMessage($msgB2, self::CONV_B, '2098-03-10 10:00:00', deletedAt: '2098-03-11 00:00:00');
        $this->seedObservation($msgB2, self::CONV_B, 'SB-T001', 'confirmed');

        // CONV_C: review-only — stays visible with a zero confirmed count.
        $msgC1 = 'abababab-4000-4000-8000-000000000005';
        $this->seedMessage($msgC1, self::CONV_C, '2098-03-03 10:00:00');
        $this->seedObservation($msgC1, self::CONV_C, 'SB-T001', 'review');

        // Soft-deleted conversation: excluded entirely.
        $this->seedObservation(self::MSG_SOFT_DELETED_CONV_IN, self::CONV_SOFT_DELETED, 'SB-T001', 'confirmed');

        $this->get('/api/v1/ttps/SB-T001/conversations');
        $this->assertResponseIsSuccessful();

        $data = $this->json();
        self::assertSame(3, $data['total']);
        self::assertSame(20, $data['limit']);
        self::assertSame(0, $data['offset']);

        // Most recent observation first.
        self::assertSame(
            [self::CONV_A, self::CONV_C, self::CONV_B],
            array_column($data['items'], 'conv_id')
        );

        $convA = $data['items'][0];
        self::assertSame(1, $convA['observation_count']);
        self::assertSame(1, $convA['review_count']);
        self::assertSame('2098-03-05T10:00:00+00:00', $convA['last_seen']);
        // Subject of the conversation's first non-deleted message.
        self::assertSame('Inbound message', $convA['subject']);
        self::assertSame('UNKNOWN', $convA['scam_type_code']);

        $convC = $data['items'][1];
        self::assertSame(0, $convC['observation_count']);
        self::assertSame(1, $convC['review_count']);

        $convB = $data['items'][2];
        self::assertSame('2098-03-02T10:00:00+00:00', $convB['last_seen']);

        // Offset pagination returns the next slice.
        $this->get('/api/v1/ttps/SB-T001/conversations?limit=1&offset=1');
        $this->assertResponseIsSuccessful();

        $page = $this->json();
        self::assertSame([self::CONV_C], array_column($page['items'], 'conv_id'));
        self::assertSame(3, $page['total']);
        self::assertSame(1, $page['limit']);
        self::assertSame(1, $page['offset']);

        // An oversized limit is clamped to the page maximum.
        $this->get('/api/v1/ttps/SB-T001/conversations?limit=500');
        $this->assertResponseIsSuccessful();

        $clamped = $this->json();
        self::assertSame(self::CONVERSATIONS_PAGE_MAX, $clamped['limit']);
        self::assertCount(3, $clamped['items']);
    }

    // ─── taxonomy payload extension ────────────────────────────────────

    public function testTaxonomyRowsCarryExamplesAndExternalRefs(): void
    {
        $this->get('/api/v1/ttps');
        $this->assertResponseIsSuccessful();

        $ttps = $this->json()['ttps'];
        self::assertCount(self::TAXONOMY_SIZE, $ttps);

        foreach ($ttps as $entry) {
            self::assertIsArray($entry['examples']);
            self::assertIsArray($entry['external_refs']);
        }

        $byCode = array_column($ttps, null, 'ttp_code');

        $t001 = $byCode['SB-T001'];
        self::assertNotEmpty($t001['examples']);

        foreach ($t001['examples'] as $example) {
            self::assertIsString($example);
            self::assertNotSame('', $example);
        }

        self::assertSame('mitre-attack', $t001['external_refs'][0]['source_name']);
        self::assertSame('T1566', $t001['external_refs'][0]['external_id']);

        // SB-T017 used to be the "no mapping" example. The F3 mapping gave it
        // two references, so it now pins the opposite: that F3 references are
        // actually served by this endpoint.
        $t017 = $byCode['SB-T017'];
        self::assertNotEmpty($t017['examples']);
        self::assertSame(
            ['mitre-f3', 'mitre-f3'],
            array_column($t017['external_refs'], 'source_name'),
        );

        // A taxonomy entry we found no mapping for keeps an empty list. That
        // empty array records "we found no match", not "the catalogue has a
        // hole" — see docs/standards-track.md. Chosen dynamically: naming a
        // code here is what made this test wrong in the first place.
        $unmapped = array_values(array_filter(
            $ttps,
            static fn (array $entry): bool => $entry['external_refs'] === [],
        ));
        self::assertNotSame(
            [],
            $unmapped,
            'The taxonomy is expected to contain entries with no external mapping.',
        );
        self::assertNotEmpty($unmapped[0]['examples']);
    }

    // ─── message ordering tiebreak ─────────────────────────────────────

    public function testConversationMessagesOrderTiesByMessageId(): void
    {
        // Insert the higher msg_id first so heap order contradicts the
        // expected deterministic order.
        $msgTieLow = 'abababab-5000-4000-8000-000000000001';
        $msgTieHigh = 'abababab-5000-4000-8000-000000000002';
        $this->seedMessage($msgTieHigh, self::CONV_B, '2098-04-01 10:00:00');
        $this->seedMessage($msgTieLow, self::CONV_B, '2098-04-01 10:00:00');

        $this->get('/api/v1/communication/conversation/' . self::CONV_B . '/messages?limit=50');
        $this->assertResponseIsSuccessful();

        $ids = array_column($this->json(), 'message_id');

        $posLow = array_search($msgTieLow, $ids, true);
        $posHigh = array_search($msgTieHigh, $ids, true);
        self::assertIsInt($posLow);
        self::assertIsInt($posHigh);
        self::assertSame($posHigh - 1, $posLow, 'tied timestamps must order by msg_id ASC');
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
                'subject' => 'Seeded pivot inbound',
                'body' => 'Seeded pivot body',
                'headers' => '{}',
                'hash' => bin2hex(random_bytes(32)),
                'ts' => $tsMsg,
                'deletedAt' => $deletedAt,
            ]
        );
    }

    private function seedObservation(
        string $msgId,
        string $convId,
        string $code,
        string $status,
        float $confidence = 0.9,
        ?int $evidenceStart = null,
        ?int $evidenceEnd = null,
        string $extractionModel = 'seed-model',
    ): void {
        $this->connection->executeStatement(
            "INSERT INTO ttp_observation (msg_id, conv_id, ttp_id, confidence, evidence, evidence_start, evidence_end, status, taxonomy_version, extraction_model, prompt_version)
             VALUES (:msgId, :convId, :ttpId, :confidence, 'seeded queue evidence', :evidenceStart, :evidenceEnd, :status, '1.0', :extractionModel, 'v1')",
            [
                'msgId' => $msgId,
                'convId' => $convId,
                'ttpId' => $this->ttpId($code),
                'confidence' => $confidence,
                'evidenceStart' => $evidenceStart,
                'evidenceEnd' => $evidenceEnd,
                'status' => $status,
                'extractionModel' => $extractionModel,
            ]
        );
    }

    /**
     * Bulk-seed review observations across generated messages (27 taxonomy
     * codes per message, capped at the requested row count).
     */
    private function seedBulkReviewObservations(int $rowCount): void
    {
        $messageCount = intdiv($rowCount, self::TAXONOMY_SIZE) + 1;

        $template = $this->connection->fetchAssociative(
            'SELECT channel_id, direction FROM message WHERE msg_id = :msgId',
            ['msgId' => self::MSG_A_IN]
        );
        self::assertIsArray($template);

        $this->connection->executeStatement(
            "INSERT INTO message (msg_id, conv_id, channel_id, direction, lang_detect, subject, body_text, headers, composite_hash, ts_msg, ts_ingest)
             SELECT ('abababab-1000-4000-8000-' || lpad(n::text, 12, '0'))::uuid,
                    :convId, :channelId, :direction, 'en', 'Bulk review inbound', 'Bulk review body', '{}',
                    md5('bulk-review-a' || n::text) || md5('bulk-review-b' || n::text),
                    TIMESTAMP '2098-05-01 00:00:00' + make_interval(secs => n),
                    TIMESTAMP '2098-05-01 00:00:00' + make_interval(secs => n)
             FROM generate_series(1, :messageCount) AS n",
            [
                'convId' => self::CONV_A,
                'channelId' => $template['channel_id'],
                'direction' => $template['direction'],
                'messageCount' => $messageCount,
            ],
            ['messageCount' => ParameterType::INTEGER]
        );

        $inserted = $this->connection->executeStatement(
            "INSERT INTO ttp_observation (msg_id, conv_id, ttp_id, confidence, evidence, status, taxonomy_version, extraction_model, prompt_version)
             SELECT b.msg_id, :convId, t.ttp_id, 0.4, 'seeded queue evidence', 'review', '1.0', 'bulk-model', 'v1'
             FROM (SELECT msg_id, row_number() OVER (ORDER BY msg_id) AS rn
                   FROM message
                   WHERE conv_id = :convId AND subject = 'Bulk review inbound') b
             CROSS JOIN lkp_ttp t
             ORDER BY b.rn ASC, t.ttp_id ASC
             LIMIT :rowCount",
            ['convId' => self::CONV_A, 'rowCount' => $rowCount],
            ['rowCount' => ParameterType::INTEGER]
        );
        self::assertSame($rowCount, $inserted);
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

    /**
     * Start of the current ISO week (Monday 00:00 UTC) minus the given number
     * of weeks — mirrors how the trend endpoint buckets message timestamps.
     */
    private function weekStart(int $weeksAgo): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $monday = $now->setTime(0, 0)->modify(sprintf('-%d days', (int) $now->format('N') - 1));

        return $monday->modify(sprintf('-%d weeks', $weeksAgo));
    }
}

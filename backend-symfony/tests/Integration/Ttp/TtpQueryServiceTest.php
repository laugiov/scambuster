<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ttp;

use App\Application\Ttp\TtpObservationUpsertService;
use App\Application\Ttp\TtpQueryService;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration coverage of the TTP read models on the fixture dataset:
 * conversation observation ordering, timeline assembly (directions, revealed
 * IOCs, modal stimulus attribution), confirmed-only cluster aggregates with
 * bigram sequences, and the always-complete taxonomy overview. All writes go
 * through the real upsert service or plain DBAL and are rolled back per test.
 */
final class TtpQueryServiceTest extends KernelTestCase
{
    private const CONV = '00000000-0000-0000-0000-000000000002';
    private const CONV_EMPTY = '00000000-0000-0000-0000-000000000003';

    private const CLUSTER = 'dddddddd-0000-4000-8000-0000000000c1';
    private const CLUSTER_EMPTY = 'dddddddd-0000-4000-8000-0000000000c2';
    private const CLUSTER_MERGED = 'dddddddd-0000-4000-8000-0000000000c3';
    private const CLUSTER_UNKNOWN = 'ffffffff-ffff-ffff-ffff-ffffffffffff';

    // Indicator seeded on the inbound message by seedIocsAndStimulusContexts (iban, index 0).
    private const IBAN_INDICATOR = 'eeeeeeee-0000-4000-8000-000000000001';
    private const UNKNOWN_INDICATOR = 'ffffffff-ffff-ffff-ffff-ffffffffffff';

    // Matrix cap; must stay in sync with TtpQueryService::CLUSTER_MATRIX_LIMIT.
    private const MATRIX_CLUSTER_LIMIT = 50;

    // Extra inbound messages inserted into the fixture conversation so the
    // ordered sequence spans several messages (MSG_B and MSG_C share the same
    // ts_msg to exercise the msg_id tiebreak).
    private const MSG_B = 'cccccccc-0000-4000-8000-000000000001';
    private const MSG_C = 'cccccccc-0000-4000-8000-000000000002';

    private const TAXONOMY_SIZE = 27;

    private Connection $connection;

    private TtpQueryService $service;

    private TtpObservationUpsertService $upsert;

    private string $msgInbound;

    private string $msgOutbound;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->service = new TtpQueryService($this->connection);
        $this->upsert = new TtpObservationUpsertService($this->connection, new NullLogger());

        // Clean slate on the test database (DAMA rolls everything back).
        $this->connection->executeStatement('DELETE FROM ttp_observation');

        // Isolate this conversation's IOC surface: IocContextTestFixtures picks
        // "an inbound message" with a LIMIT 1 and no ORDER BY, so it may
        // (heap-order dependent) attach its observed_ioc rows to THIS
        // conversation's inbound message and inflate the timeline's
        // iocs_revealed count. Wipe the conversation's pre-existing IOC rows so
        // the test only ever sees what it seeds itself (DAMA rolls this back).
        $this->connection->executeStatement(
            'DELETE FROM ioc_context WHERE obs_id IN (
                SELECT oi.obs_id FROM observed_ioc oi
                JOIN message m ON m.msg_id = oi.msg_id
                WHERE m.conv_id = :conv)',
            ['conv' => self::CONV],
        );
        $this->connection->executeStatement(
            'DELETE FROM observed_ioc WHERE msg_id IN (
                SELECT msg_id FROM message WHERE conv_id = :conv)',
            ['conv' => self::CONV],
        );

        $this->msgInbound = $this->fixtureMessageId('in');
        $this->msgOutbound = $this->fixtureMessageId('out');

        $this->insertExtraInboundMessages();
    }

    // ─── conversation observations ─────────────────────────────────────

    public function testConversationTtpsOrderedWithOffsetsAndNoEvidence(): void
    {
        $this->seedObservations();

        $result = $this->service->conversationTtps(self::CONV);

        self::assertSame(self::CONV, $result['conv_id']);

        $observations = $result['observations'];
        self::assertCount(4, $observations);

        // ts_msg ASC, then msg_id, then code: the two inbound-message rows
        // first (code tiebreak within the same message), then MSG_B, MSG_C.
        self::assertSame(
            ['SB-T001', 'SB-T005', 'SB-T003', 'SB-T001'],
            array_column($observations, 'ttp_code')
        );
        self::assertSame(
            [$this->msgInbound, $this->msgInbound, self::MSG_B, self::MSG_C],
            array_column($observations, 'msg_id')
        );

        $first = $observations[0];
        self::assertIsString($first['ttp_label']);
        self::assertNotSame('', $first['ttp_label']);
        self::assertSame('hook', $first['phase']);
        self::assertIsFloat($first['confidence']);
        self::assertSame(0.9, $first['confidence']);
        self::assertSame('confirmed', $first['status']);
        self::assertSame(0, $first['evidence_start']);
        self::assertSame(8, $first['evidence_end']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', (string) $first['ts_msg']);

        $review = $observations[1];
        self::assertSame('review', $review['status']);
        self::assertNull($review['evidence_start']);
        self::assertNull($review['evidence_end']);

        // Offsets only — the stored verbatim never leaves the database.
        foreach ($observations as $observation) {
            self::assertArrayNotHasKey('evidence', $observation);
        }
    }

    // ─── timeline ──────────────────────────────────────────────────────

    public function testConversationTimelineDirectionsIocsAndStimulus(): void
    {
        $this->seedObservations();
        $this->seedIocsAndStimulusContexts();

        $timeline = $this->service->conversationTimeline(self::CONV);

        self::assertCount(4, $timeline);
        self::assertSame(
            [$this->msgInbound, self::MSG_B, self::MSG_C, $this->msgOutbound],
            array_column($timeline, 'msg_id')
        );
        self::assertSame(['in', 'in', 'in', 'out'], array_column($timeline, 'direction'));

        $inbound = $timeline[0];
        self::assertArrayHasKey('subject', $inbound);
        self::assertSame(['SB-T001', 'SB-T005'], array_column($inbound['ttps'], 'ttp_code'));
        self::assertNull($inbound['stimulus_type']);

        foreach ($inbound['ttps'] as $ttp) {
            self::assertArrayNotHasKey('evidence', $ttp);
            self::assertArrayHasKey('evidence_start', $ttp);
            self::assertArrayHasKey('evidence_end', $ttp);
        }

        // All five seeded IOCs sit on the inbound fixture message.
        self::assertCount(5, $inbound['iocs_revealed']);
        self::assertSame('iban', $inbound['iocs_revealed'][0]['type']);
        self::assertSame('FR7699999999990000000000001', $inbound['iocs_revealed'][0]['value_norm']);

        // Outbound: never carries TTPs, gets the modal stimulus of the
        // ENRICHED contexts only (2x DIRECT_REQUEST vs 1x URGENCY_PRESSURE;
        // the two pending URGENCY_PRESSURE rows must not flip the mode).
        $outbound = $timeline[3];
        self::assertSame([], $outbound['ttps']);
        self::assertSame([], $outbound['iocs_revealed']);
        self::assertSame('DIRECT_REQUEST', $outbound['stimulus_type']);
    }

    public function testConversationTimelineIocsCarryIndicatorAndStimulusRefs(): void
    {
        $this->seedObservations();
        $this->seedIocsAndStimulusContexts();

        // An IOC without any ioc_context row at all (NULL-safe path).
        $orphanIndicator = 'eeeeeeee-0000-4000-8000-000000000077';
        $this->insertIndicatorAndObservation($orphanIndicator, 'iban', 'FR7600000000000000000000077', self::MSG_B);

        $timeline = $this->service->conversationTimeline(self::CONV);

        $inbound = $timeline[0];
        $byType = array_column($inbound['iocs_revealed'], null, 'type');

        // Enriched contexts expose the stimulus attribution; pending ones
        // stay NULL (same enrichment boundary as the modal stimulus).
        self::assertSame($this->msgOutbound, $byType['iban']['stimulus_msg_id']);
        self::assertSame($this->msgOutbound, $byType['url']['stimulus_msg_id']);
        self::assertSame($this->msgOutbound, $byType['phone']['stimulus_msg_id']);
        self::assertNull($byType['email']['stimulus_msg_id']);
        self::assertNull($byType['btc_address']['stimulus_msg_id']);

        // Every revealed IOC carries its canonical indicator reference.
        self::assertSame(self::IBAN_INDICATOR, $byType['iban']['indicator_id']);

        foreach ($inbound['iocs_revealed'] as $ioc) {
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $ioc['indicator_id']);
        }

        // No ioc_context row at all: indicator ref present, stimulus NULL.
        $msgB = $timeline[1];
        self::assertSame(self::MSG_B, $msgB['msg_id']);
        self::assertCount(1, $msgB['iocs_revealed']);
        self::assertSame($orphanIndicator, $msgB['iocs_revealed'][0]['indicator_id']);
        self::assertNull($msgB['iocs_revealed'][0]['stimulus_msg_id']);
    }

    public function testConversationTimelineFiltersNonActionableIocTypes(): void
    {
        $this->seedObservations();
        $this->seedIocsAndStimulusContexts();

        // Transport noise on the same inbound message: header/auth metadata
        // is not actionable intelligence and never reaches the timeline.
        $this->insertIndicatorAndObservation('eeeeeeee-0000-4000-8000-000000000081', 'spf_result', 'pass-timeline-noise', $this->msgInbound);
        $this->insertIndicatorAndObservation('eeeeeeee-0000-4000-8000-000000000082', 'dkim_result', 'fail-timeline-noise', $this->msgInbound);
        $this->insertIndicatorAndObservation('eeeeeeee-0000-4000-8000-000000000083', 'message_id', '<timeline-noise@mail.test>', $this->msgInbound);

        $timeline = $this->service->conversationTimeline(self::CONV);

        $types = array_column($timeline[0]['iocs_revealed'], 'type');
        sort($types);
        self::assertSame(['btc_address', 'email', 'iban', 'phone', 'url'], $types);
    }

    // ─── cluster profile ───────────────────────────────────────────────

    public function testClusterTtpProfileConfirmedOnlyAggregatesAndBigrams(): void
    {
        $this->seedObservations();
        $this->linkCluster(self::CLUSTER, self::CONV);
        $this->linkCluster(self::CLUSTER_EMPTY, self::CONV_EMPTY);

        self::assertNull($this->service->clusterTtpProfile(self::CLUSTER_UNKNOWN));

        $profile = $this->service->clusterTtpProfile(self::CLUSTER);
        self::assertNotNull($profile);
        self::assertSame(self::CLUSTER, $profile['cluster_id']);

        $ttps = $profile['ttps'];
        self::assertSame(['SB-T001', 'SB-T003'], array_column($ttps, 'ttp_code'));

        $t001 = $ttps[0];
        self::assertSame(2, $t001['observation_count']);
        self::assertSame(1, $t001['conversation_count']);
        self::assertSame(0.8, $t001['avg_confidence']); // avg(0.9, 0.7)
        self::assertNotNull($t001['first_seen']);
        self::assertNotNull($t001['last_seen']);
        self::assertLessThanOrEqual((string) $t001['last_seen'], (string) $t001['first_seen']);

        $t003 = $ttps[1];
        self::assertSame(1, $t003['observation_count']);
        self::assertSame(0.8, $t003['avg_confidence']);

        // The review-status observation (SB-T005) is excluded everywhere here.
        self::assertNotContains('SB-T005', array_column($ttps, 'ttp_code'));

        // Per-message confirmed sets [{T001}, {T003}, {T001}] fold into the two
        // cross-boundary bigrams (singleton sets — the multi-TTP behaviours are
        // pinned by testTopSequencesEmitCrossBoundaryPairsFromPerMessageSets).
        self::assertSame(
            [
                ['sequence' => ['SB-T001', 'SB-T003'], 'count' => 1],
                ['sequence' => ['SB-T003', 'SB-T001'], 'count' => 1],
            ],
            $profile['top_sequences']
        );

        // A known cluster without observations answers with empty lists.
        $empty = $this->service->clusterTtpProfile(self::CLUSTER_EMPTY);
        self::assertNotNull($empty);
        self::assertSame([], $empty['ttps']);
        self::assertSame([], $empty['top_sequences']);
    }

    // ─── sequence fold (cross-boundary bigrams) ────────────────────────

    public function testTopSequencesEmitCrossBoundaryPairsFromPerMessageSets(): void
    {
        // Per-message confirmed sets: M1 {T001, T011} (+ a review row that
        // must not leak in), M2 {T003, T009}, M3 {T003}. M2 and M3 share one
        // ts_msg, so the msg_id tiebreak decides the boundary order.
        foreach ([
            [$this->msgInbound, 'SB-T001', 'confirmed'],
            [$this->msgInbound, 'SB-T011', 'confirmed'],
            [$this->msgInbound, 'SB-T005', 'review'],
            [self::MSG_B, 'SB-T003', 'confirmed'],
            [self::MSG_B, 'SB-T009', 'confirmed'],
            [self::MSG_C, 'SB-T003', 'confirmed'],
        ] as [$msgId, $code, $status]) {
            $this->seedObservationOn(self::CONV, $msgId, $code, $status);
        }

        $this->linkCluster(self::CLUSTER, self::CONV);

        $profile = $this->service->clusterTtpProfile(self::CLUSTER);
        self::assertNotNull($profile);

        // The full cross-product of the two multi-TTP adjacent sets (minus
        // the self-pair) plus the M2 → M3 pairs; all counts 1 → key order.
        // Exactly five pairs survive, so the top-5 cap drops nothing and the
        // absence assertions below are meaningful.
        self::assertSame(
            [
                ['sequence' => ['SB-T001', 'SB-T003'], 'count' => 1],
                ['sequence' => ['SB-T001', 'SB-T009'], 'count' => 1],
                ['sequence' => ['SB-T009', 'SB-T003'], 'count' => 1],
                ['sequence' => ['SB-T011', 'SB-T003'], 'count' => 1],
                ['sequence' => ['SB-T011', 'SB-T009'], 'count' => 1],
            ],
            $profile['top_sequences']
        );

        $keys = array_map(
            static fn (array $entry): string => implode('>', $entry['sequence']),
            $profile['top_sequences']
        );

        // Self-pair: T003 sits in both adjacent sets and never pairs with itself.
        self::assertNotContains('SB-T003>SB-T003', $keys);
        // Intra-message co-occurrence (M1's set) never becomes a pair.
        self::assertNotContains('SB-T001>SB-T011', $keys);
        self::assertNotContains('SB-T011>SB-T001', $keys);
        // The legacy chain fold's intra-message artifact (M2's alphabetical order).
        self::assertNotContains('SB-T003>SB-T009', $keys);

        // The review-status row never contributes to any pair.
        foreach ($keys as $key) {
            self::assertStringNotContainsString('SB-T005', $key);
        }
    }

    public function testSequencesGroupByClusterWithMinSupportAndConversationCounts(): void
    {
        // CONV: sets {T001}, {T003}, {T001} → T001>T003 x1, T003>T001 x1.
        $this->seedObservations();

        // A second cluster conversation repeating T001 → T003, so that pair
        // reaches the support threshold while T003 → T001 stays below it.
        $convB = '0000aaaa-0000-4000-8000-000000000001';
        $this->cloneConversation($convB);
        $msgB1 = '0000aaaa-1000-4000-8000-000000000001';
        $msgB2 = '0000aaaa-1000-4000-8000-000000000002';
        $this->insertInboundMessage($msgB1, $convB, '+1 minute');
        $this->insertInboundMessage($msgB2, $convB, '+2 minutes');
        $this->seedObservationOn($convB, $msgB1, 'SB-T001');
        $this->seedObservationOn($convB, $msgB2, 'SB-T003');

        $this->insertCluster(self::CLUSTER, 'Sequence Actor', 'active');
        $this->linkConv(self::CLUSTER, self::CONV);
        $this->linkConv(self::CLUSTER, $convB);

        // A merged cluster with enough support of its own must not surface.
        $convM = '0000aaaa-0000-4000-8000-000000000002';
        $this->cloneConversation($convM);
        $codes = ['SB-T001', 'SB-T003', 'SB-T001', 'SB-T003'];
        foreach ($codes as $i => $code) {
            $msgId = sprintf('0000aaaa-2000-4000-8000-%012d', $i + 1);
            $this->insertInboundMessage($msgId, $convM, sprintf('+%d minutes', $i + 1));
            $this->seedObservationOn($convM, $msgId, $code);
        }
        $this->insertCluster(self::CLUSTER_MERGED, 'Merged Actor', 'merged');
        $this->linkConv(self::CLUSTER_MERGED, $convM);

        // A cluster without observations yields no group at all.
        $this->insertCluster(self::CLUSTER_EMPTY, 'Empty Actor', 'active');
        $this->linkConv(self::CLUSTER_EMPTY, self::CONV_EMPTY);

        $result = $this->service->sequences('cluster');

        self::assertSame(TtpQueryService::MIN_SEQUENCE_SUPPORT, $result['min_support']);
        self::assertSame(2, $result['min_support']);
        self::assertFalse($result['truncated']);

        // Only the live productive cluster forms a group (merged and empty
        // clusters are out; sub-threshold-only groups would be omitted too).
        self::assertSame([self::CLUSTER], array_column($result['groups'], 'key'));

        $group = $result['groups'][0];
        self::assertSame('Sequence Actor', $group['label']);

        // Support is conversation-based: T001>T003 appears in BOTH conversations
        // (support 2) so it clears the threshold; T003>T001 appears in only one
        // (support 1) and is dropped. count reports raw occurrences alongside.
        self::assertSame(
            [['sequence' => ['SB-T001', 'SB-T003'], 'count' => 2, 'conversation_count' => 2]],
            $group['sequences']
        );
    }

    public function testSequencesDropPairsConfinedToOneConversation(): void
    {
        // Sets {T001}, {T003}, {T001}, {T003} on ONE conversation: T001 → T003
        // recurs across non-adjacent boundaries (occurrences 2, no
        // first-appearance dedup) but is confined to this single conversation,
        // so conversation-based support (1) never clears the threshold and the
        // group is omitted entirely — an occurrence-based filter would have
        // wrongly surfaced it.
        $this->seedObservations();
        $msgD = 'cccccccc-0000-4000-8000-000000000003';
        $this->insertInboundMessage($msgD, self::CONV, '+10 minutes');
        $this->seedObservationOn(self::CONV, $msgD, 'SB-T003');

        // Only CONV carries confirmed observations (setUp wiped the table), so
        // its scam-type group has no pair seen in >=2 conversations.
        $result = $this->service->sequences('scam_type');
        self::assertSame(2, $result['min_support']);
        self::assertSame([], $result['groups']);

        // Same conclusion under cluster grouping.
        $this->insertCluster(self::CLUSTER, 'Solo Actor', 'active');
        $this->linkConv(self::CLUSTER, self::CONV);
        self::assertSame([], $this->service->sequences('cluster')['groups']);
    }

    public function testSequencesCountExceedsConversationCountForRepeatingPair(): void
    {
        // CONV: {T001}, {T003}, {T001}, {T003} → T001 → T003 twice in ONE conv.
        $this->seedObservations();
        $msgD = 'cccccccc-0000-4000-8000-000000000003';
        $this->insertInboundMessage($msgD, self::CONV, '+10 minutes');
        $this->seedObservationOn(self::CONV, $msgD, 'SB-T003');

        // A second conversation of the SAME scam type (clone) contributing
        // T001 → T003 once, lifting the pair to 2 conversations so it clears the
        // threshold while its occurrence count (3) exceeds its support (2).
        $convB = '0000aaaa-0000-4000-8000-000000000003';
        $this->cloneConversation($convB);
        $msgB1 = '0000aaaa-3000-4000-8000-000000000001';
        $msgB2 = '0000aaaa-3000-4000-8000-000000000002';
        $this->insertInboundMessage($msgB1, $convB, '+1 minute');
        $this->insertInboundMessage($msgB2, $convB, '+2 minutes');
        $this->seedObservationOn($convB, $msgB1, 'SB-T001');
        $this->seedObservationOn($convB, $msgB2, 'SB-T003');

        $scamType = $this->connection->fetchAssociative(
            'SELECT st.code, st.label
             FROM conversation c
             JOIN lkp_scam_type st ON st.scam_type_id = c.scam_type_id
             WHERE c.conv_id = :conv',
            ['conv' => self::CONV]
        );
        self::assertIsArray($scamType);

        $result = $this->service->sequences('scam_type');

        self::assertSame(2, $result['min_support']);
        self::assertFalse($result['truncated']);
        self::assertSame([$scamType['code']], array_column($result['groups'], 'key'));

        $group = $result['groups'][0];
        self::assertSame($scamType['label'], $group['label']);

        // Divergence: occurrences (2 in CONV + 1 in convB) exceed the
        // conversation support (2); T003>T001 (support 1) is dropped.
        self::assertSame(
            [['sequence' => ['SB-T001', 'SB-T003'], 'count' => 3, 'conversation_count' => 2]],
            $group['sequences']
        );
    }

    public function testSequencesRejectAnUnknownGroup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->sequences('persona');
    }

    public function testPhaseTransitionsAggregateCrossBoundaryBigramsByPhase(): void
    {
        // Sets {T001}, {T003}, {T001} (all hook) plus a later escalation
        // message {T017}: two hook→hook pairs and one hook→escalation pair.
        // The review-status T005 (trust-building) must contribute nothing.
        $this->seedObservations();
        $msgD = 'cccccccc-0000-4000-8000-000000000004';
        $this->insertInboundMessage($msgD, self::CONV, '+15 minutes');
        $this->seedObservationOn(self::CONV, $msgD, 'SB-T017');

        $result = $this->service->phaseTransitions();

        self::assertSame(3, $result['total_pairs']);
        self::assertSame(
            [
                ['from_phase' => 'hook', 'to_phase' => 'hook', 'count' => 2],
                ['from_phase' => 'hook', 'to_phase' => 'escalation', 'count' => 1],
            ],
            $result['transitions']
        );
    }

    public function testSequencesAndTransitionsAreEmptyWithoutConfirmedObservations(): void
    {
        // setUp wiped ttp_observation and nothing is seeded here.
        self::assertSame(
            ['groups' => [], 'min_support' => 2, 'truncated' => false],
            $this->service->sequences('cluster')
        );
        self::assertSame(
            ['groups' => [], 'min_support' => 2, 'truncated' => false],
            $this->service->sequences('scam_type')
        );
        self::assertSame(
            ['transitions' => [], 'total_pairs' => 0],
            $this->service->phaseTransitions()
        );
    }

    // ─── taxonomy overview ─────────────────────────────────────────────

    public function testTaxonomyOverviewSplitsConfirmedAndReviewCounts(): void
    {
        $this->seedObservations();

        $overview = $this->service->taxonomyOverview();
        self::assertCount(self::TAXONOMY_SIZE, $overview);

        $byCode = array_column($overview, null, 'ttp_code');

        $t001 = $byCode['SB-T001'];
        self::assertSame(2, $t001['observation_count']);
        self::assertSame(1, $t001['conversation_count']);
        self::assertSame(0, $t001['review_count']);
        self::assertNotNull($t001['first_seen']);

        // Review rows are tallied separately and do not feed the confirmed
        // counters (nor first/last_seen).
        $t005 = $byCode['SB-T005'];
        self::assertSame(0, $t005['observation_count']);
        self::assertSame(0, $t005['conversation_count']);
        self::assertSame(1, $t005['review_count']);
        self::assertNull($t005['first_seen']);
        self::assertNull($t005['last_seen']);

        $untouched = $byCode['SB-T010'];
        self::assertSame(0, $untouched['observation_count']);
        self::assertSame(0, $untouched['review_count']);
    }

    public function testTaxonomyOverviewReturnsAllEntriesWithoutAnyObservation(): void
    {
        // setUp wiped ttp_observation and nothing was seeded here.
        $overview = $this->service->taxonomyOverview();

        self::assertCount(self::TAXONOMY_SIZE, $overview);

        $codes = array_column($overview, 'ttp_code');
        self::assertCount(self::TAXONOMY_SIZE, array_unique($codes));
        self::assertContains('SB-T001', $codes);
        self::assertContains('SB-T027', $codes);

        foreach ($overview as $entry) {
            self::assertSame(0, $entry['observation_count']);
            self::assertSame(0, $entry['conversation_count']);
            self::assertSame(0, $entry['review_count']);
            self::assertNull($entry['first_seen']);
            self::assertNull($entry['last_seen']);
            self::assertNotSame('', $entry['ttp_label']);
            self::assertNotSame('', $entry['definition']);
            self::assertNotSame('', $entry['phase']);
            // Taxonomy text decoded from the JSONB columns.
            self::assertIsArray($entry['examples']);
            self::assertIsArray($entry['external_refs']);
            self::assertNotEmpty($entry['examples']);
        }
    }

    public function testSoftDeletedMessageObservationsAreExcludedFromAggregates(): void
    {
        // Intelligence must never come from a purged thread: an observation on a
        // soft-deleted message is invisible to both the taxonomy overview and
        // the cluster profile.
        $deletedMsg = 'dddddddd-0000-4000-8000-000000000001';
        $this->insertSoftDeletedInboundMessage($deletedMsg);

        self::assertTrue($this->upsert->upsert([
            'msg_id' => $deletedMsg,
            'conv_id' => self::CONV,
            'ttp_id' => $this->ttpId('SB-T010'),
            'confidence' => 0.95,
            'evidence' => 'evidence on a purged message',
            'evidence_start' => 0,
            'evidence_end' => 4,
            'status' => 'confirmed',
            'taxonomy_version' => '1.0',
            'extraction_model' => 'test-model',
            'prompt_version' => 'v1',
        ]));

        $overview = array_column($this->service->taxonomyOverview(), null, 'ttp_code');
        self::assertSame(0, $overview['SB-T010']['observation_count'], 'soft-deleted message must not be counted');
        self::assertSame(0, $overview['SB-T010']['review_count']);

        $this->linkCluster(self::CLUSTER, self::CONV);
        $profile = $this->service->clusterTtpProfile(self::CLUSTER);
        self::assertIsArray($profile);
        self::assertNotContains('SB-T010', array_column($profile['ttps'], 'ttp_code'), 'soft-deleted message must not feed the cluster profile');
    }

    // ─── cluster matrix ────────────────────────────────────────────────

    public function testClusterTtpMatrixConfirmedOnlySparseCellsOrderedByPhase(): void
    {
        $this->seedObservations();
        // One extra confirmed observation in a later phase so the column order
        // exercises the phase-then-code ranking (hook before escalation).
        self::assertTrue($this->upsert->upsert([
            'msg_id' => $this->msgInbound,
            'conv_id' => self::CONV,
            'ttp_id' => $this->ttpId('SB-T017'),
            'confidence' => 0.88,
            'evidence' => 'seeded escalation evidence',
            'evidence_start' => null,
            'evidence_end' => null,
            'status' => 'confirmed',
            'taxonomy_version' => '1.0',
            'extraction_model' => 'test-model',
            'prompt_version' => 'v1',
        ]));

        $this->insertCluster(self::CLUSTER, 'Playbook Actor', 'active');
        $this->linkConv(self::CLUSTER, self::CONV);
        // A cluster without any confirmed observation must not appear at all.
        $this->insertCluster(self::CLUSTER_EMPTY, 'Empty Actor', 'active');
        $this->linkConv(self::CLUSTER_EMPTY, self::CONV_EMPTY);
        // A merged cluster is excluded even though it links to its own productive
        // conversation (one conversation maps to exactly one cluster).
        $mergedConv = '0000dddd-0000-4000-8000-000000000001';
        $this->seedProductiveConversation($mergedConv, '0000dddd-0000-4000-8000-000000000002');
        $this->insertCluster(self::CLUSTER_MERGED, 'Merged Actor', 'merged');
        $this->linkConv(self::CLUSTER_MERGED, $mergedConv);

        $matrix = $this->service->clusterTtpMatrix();

        // Only the live, productive cluster is present.
        self::assertSame([self::CLUSTER], array_column($matrix['clusters'], 'cluster_id'));
        self::assertFalse($matrix['truncated']);
        self::assertSame(1, $matrix['total_clusters']);

        $cluster = $matrix['clusters'][0];
        self::assertSame('Playbook Actor', $cluster['label']);
        // confirmed on CONV: SB-T001 x2 (inbound + MSG_C) + SB-T003 x1 + SB-T017 x1 = 4.
        self::assertSame(4, $cluster['observation_total']);
        // Every confirmed observation sits in the single conversation CONV, so the
        // cluster's per-conversation denominator is 1 (not 4 — the fair total).
        self::assertSame(1, $cluster['conversation_total']);

        // Columns limited to observed codes, ordered by phase then code.
        self::assertSame(['SB-T001', 'SB-T003', 'SB-T017'], array_column($matrix['ttps'], 'ttp_code'));
        self::assertSame(['hook', 'hook', 'escalation'], array_column($matrix['ttps'], 'phase'));
        foreach ($matrix['ttps'] as $column) {
            self::assertNotSame('', $column['ttp_label']);
        }

        // Sparse cells: every cell is non-zero and there is one per observed pair.
        $cellIndex = [];
        $convCellIndex = [];
        foreach ($matrix['cells'] as $cell) {
            self::assertGreaterThan(0, $cell['count']);
            $cellIndex[$cell['cluster_id'] . '|' . $cell['ttp_code']] = $cell['count'];
            $convCellIndex[$cell['cluster_id'] . '|' . $cell['ttp_code']] = $cell['conversation_count'];
        }
        self::assertSame(
            [
                self::CLUSTER . '|SB-T001' => 2,
                self::CLUSTER . '|SB-T003' => 1,
                self::CLUSTER . '|SB-T017' => 1,
            ],
            $cellIndex
        );
        // Per-cell distinct-conversation counts: SB-T001 spans two messages but a
        // single conversation, so the fair count is 1 (never the raw 2).
        self::assertSame(
            [
                self::CLUSTER . '|SB-T001' => 1,
                self::CLUSTER . '|SB-T003' => 1,
                self::CLUSTER . '|SB-T017' => 1,
            ],
            $convCellIndex
        );

        // The review-status SB-T005 never becomes a column or a cell.
        self::assertNotContains('SB-T005', array_column($matrix['ttps'], 'ttp_code'));
    }

    public function testClusterTtpMatrixIsEmptyWithoutConfirmedObservations(): void
    {
        // setUp wiped ttp_observation and nothing is seeded here.
        $this->insertCluster(self::CLUSTER, 'Idle Actor', 'active');
        $this->linkConv(self::CLUSTER, self::CONV);

        $matrix = $this->service->clusterTtpMatrix();

        self::assertSame([], $matrix['clusters']);
        self::assertSame([], $matrix['ttps']);
        self::assertSame([], $matrix['cells']);
        self::assertFalse($matrix['truncated']);
        self::assertSame(0, $matrix['total_clusters']);
    }

    public function testClusterTtpMatrixReportsTruncationAboveTheCap(): void
    {
        // One more cluster than the cap, each linked to its own productive
        // conversation (a conversation maps to exactly one cluster).
        for ($n = 1; $n <= self::MATRIX_CLUSTER_LIMIT + 1; ++$n) {
            $convId = sprintf('0000eeee-0000-4000-8000-%012d', $n);
            $this->seedProductiveConversation($convId, sprintf('0000ffff-0000-4000-8000-%012d', $n));
            $clusterId = sprintf('dddddddd-1000-4000-8000-%012d', $n);
            $this->insertCluster($clusterId, sprintf('Actor %02d', $n), 'active');
            $this->linkConv($clusterId, $convId);
        }

        $matrix = $this->service->clusterTtpMatrix();

        self::assertTrue($matrix['truncated']);
        self::assertSame(self::MATRIX_CLUSTER_LIMIT + 1, $matrix['total_clusters']);
        self::assertCount(self::MATRIX_CLUSTER_LIMIT, $matrix['clusters']);

        // The dropped cluster (lexicographically last id) is absent, and every
        // returned cell belongs to a retained cluster.
        $returnedIds = array_fill_keys(array_column($matrix['clusters'], 'cluster_id'), true);
        self::assertArrayNotHasKey(sprintf('dddddddd-1000-4000-8000-%012d', self::MATRIX_CLUSTER_LIMIT + 1), $returnedIds);
        foreach ($matrix['cells'] as $cell) {
            self::assertArrayHasKey($cell['cluster_id'], $returnedIds);
        }
    }

    // ─── IOC <-> TTP co-occurrence pivots ──────────────────────────────

    public function testIocsForTtpReturnsConfirmedCoOccurringIocs(): void
    {
        $this->seedObservations();
        $this->seedIocsAndStimulusContexts();

        // SB-T001 is confirmed on the inbound message (and MSG_C); the five IOCs
        // sit on the inbound message only.
        $result = $this->service->iocsForTtp('SB-T001');
        self::assertSame('SB-T001', $result['ttp_code']);
        self::assertCount(5, $result['iocs']);

        foreach ($result['iocs'] as $ioc) {
            self::assertSame(1, $ioc['co_occurrence_count']);
            self::assertSame(1, $ioc['conversation_count']);
            self::assertNotSame('', $ioc['type']);
            self::assertNotSame('', $ioc['value_norm']);
            // indicator_id lets the frontend deep-link a co-occurring IOC to its detail page.
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $ioc['indicator_id']);
        }

        // Ordered by co-occurrence DESC then type then value (all counts tie -> type ASC).
        self::assertSame(
            ['btc_address', 'email', 'iban', 'phone', 'url'],
            array_column($result['iocs'], 'type')
        );

        // The review-status SB-T005 sits on the same message but yields nothing.
        self::assertSame([], $this->service->iocsForTtp('SB-T005')['iocs']);

        // SB-T003 is confirmed on MSG_B, which carries no IOC.
        self::assertSame([], $this->service->iocsForTtp('SB-T003')['iocs']);

        // A code with no observations at all yields an empty list (controller 404s first).
        self::assertSame([], $this->service->iocsForTtp('SB-T099')['iocs']);
    }

    public function testTtpsForIocReturnsConfirmedCoOccurringTtps(): void
    {
        $this->seedObservations();
        $this->seedIocsAndStimulusContexts();

        $result = $this->service->ttpsForIoc(self::IBAN_INDICATOR);
        self::assertSame(self::IBAN_INDICATOR, $result['ioc']);

        // The IBAN sits on the inbound message; only the confirmed SB-T001 counts
        // (the review-status SB-T005 on the same message is excluded).
        self::assertSame(['SB-T001'], array_column($result['ttps'], 'ttp_code'));
        $ttp = $result['ttps'][0];
        self::assertSame(1, $ttp['co_occurrence_count']);
        self::assertSame(1, $ttp['conversation_count']);
        self::assertSame('hook', $ttp['phase']);
        self::assertNotSame('', $ttp['ttp_label']);

        // An unknown indicator yields an empty list (controller 404s first).
        self::assertSame([], $this->service->ttpsForIoc(self::UNKNOWN_INDICATOR)['ttps']);
    }

    public function testCoOccurrencePivotsExcludeSoftDeletedMessages(): void
    {
        $this->seedObservations();
        $this->seedIocsAndStimulusContexts();

        // A confirmed TTP + IOC on a soft-deleted message must be invisible to
        // both pivots: intelligence never comes from a purged thread.
        $deletedMsg = 'dddddddd-0000-4000-8000-000000000009';
        $softIndicator = 'eeeeeeee-0000-4000-8000-000000000099';
        $this->insertSoftDeletedInboundMessage($deletedMsg);
        $this->insertIndicatorAndObservation($softIndicator, 'iban', 'FR7600000000000000000000099', $deletedMsg);
        self::assertTrue($this->upsert->upsert([
            'msg_id' => $deletedMsg,
            'conv_id' => self::CONV,
            'ttp_id' => $this->ttpId('SB-T001'),
            'confidence' => 0.9,
            'evidence' => 'evidence on a purged message',
            'evidence_start' => null,
            'evidence_end' => null,
            'status' => 'confirmed',
            'taxonomy_version' => '1.0',
            'extraction_model' => 'test-model',
            'prompt_version' => 'v1',
        ]));

        // iocsForTtp still returns only the five live IOCs (soft-deleted excluded).
        $iocs = $this->service->iocsForTtp('SB-T001')['iocs'];
        self::assertCount(5, $iocs);
        self::assertNotContains('FR7600000000000000000000099', array_column($iocs, 'value_norm'));

        // The soft-deleted indicator's only message is purged -> no TTPs.
        self::assertSame([], $this->service->ttpsForIoc($softIndicator)['ttps']);
    }

    // ─── seeding helpers ───────────────────────────────────────────────

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

    /**
     * Clone the fixture conversation into a fresh conversation with one inbound
     * message carrying a single confirmed SB-T001 observation, so the matrix has
     * a productive conversation to attribute to a distinct cluster.
     */
    private function seedProductiveConversation(string $convId, string $msgId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO conversation (conv_id, primary_channel_id, scam_type_id, account_id, status, score_risk, ts_first, ts_last, stix_id, created_at, updated_at, deleted_at, persona_id, engagement_duration_sec, turns_count, reward_value, delivery, tlp, secondary_scam_types)
             SELECT :newId, primary_channel_id, scam_type_id, account_id, status, score_risk, ts_first, ts_last, :stix, created_at, updated_at, NULL, persona_id, engagement_duration_sec, turns_count, reward_value, delivery, tlp, secondary_scam_types
             FROM conversation WHERE conv_id = :template',
            ['newId' => $convId, 'stix' => 'stix-' . $convId, 'template' => self::CONV]
        );

        $template = $this->connection->fetchAssociative(
            'SELECT channel_id, direction, ts_msg FROM message WHERE msg_id = :msgId',
            ['msgId' => $this->msgInbound]
        );
        self::assertIsArray($template);

        $this->connection->executeStatement(
            'INSERT INTO message (msg_id, conv_id, channel_id, direction, lang_detect, subject, body_text, headers, composite_hash, ts_msg, ts_ingest)
             VALUES (:msgId, :convId, :channelId, :direction, :lang, :subject, :body, :headers, :hash, :ts, :ts)',
            [
                'msgId' => $msgId,
                'convId' => $convId,
                'channelId' => $template['channel_id'],
                'direction' => $template['direction'],
                'lang' => 'en',
                'subject' => 'Seeded productive inbound',
                'body' => 'Seeded productive body',
                'headers' => '{}',
                'hash' => bin2hex(random_bytes(32)),
                'ts' => (string) $template['ts_msg'],
            ]
        );

        self::assertTrue($this->upsert->upsert([
            'msg_id' => $msgId,
            'conv_id' => $convId,
            'ttp_id' => $this->ttpId('SB-T001'),
            'confidence' => 0.9,
            'evidence' => 'seeded productive evidence',
            'evidence_start' => null,
            'evidence_end' => null,
            'status' => 'confirmed',
            'taxonomy_version' => '1.0',
            'extraction_model' => 'test-model',
            'prompt_version' => 'v1',
        ]));
    }

    private function insertIndicatorAndObservation(string $indicatorId, string $type, string $valueNorm, string $msgId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->connection->executeStatement(
            'INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)
             VALUES (:id, :type, :value, :valueNorm, :now, :now, 1, \'AMBER\', :now, :now)
             ON CONFLICT (indicator_id) DO NOTHING',
            ['id' => $indicatorId, 'type' => $type, 'value' => $valueNorm, 'valueNorm' => $valueNorm, 'now' => $now]
        );
        $this->connection->executeStatement(
            'INSERT INTO observed_ioc (obs_id, msg_id, indicator_id, context_observation, ts_observed)
             VALUES (:obsId, :msgId, :indicatorId, :context, :ts)',
            [
                'obsId' => sprintf('bbbbbbbb-9000-4000-8000-%012d', crc32($indicatorId) % 1000000),
                'msgId' => $msgId,
                'indicatorId' => $indicatorId,
                'context' => json_encode(['type' => $type, 'value' => $valueNorm, 'value_norm' => $valueNorm]),
                'ts' => $now,
            ]
        );
    }

    private function insertSoftDeletedInboundMessage(string $msgId): void
    {
        $template = $this->connection->fetchAssociative(
            'SELECT channel_id, direction, ts_msg FROM message WHERE msg_id = :msgId',
            ['msgId' => $this->msgInbound]
        );
        self::assertIsArray($template);

        $this->connection->executeStatement(
            'INSERT INTO message (msg_id, conv_id, channel_id, direction, lang_detect, subject, body_text, headers, composite_hash, ts_msg, ts_ingest, deleted_at)
             VALUES (:msgId, :convId, :channelId, :direction, :lang, :subject, :body, :headers, :hash, :ts, :ts, NOW())',
            [
                'msgId' => $msgId,
                'convId' => self::CONV,
                'channelId' => $template['channel_id'],
                'direction' => $template['direction'],
                'lang' => 'en',
                'subject' => 'Purged',
                'body' => 'Soft-deleted message body',
                'headers' => '{}',
                'hash' => bin2hex(random_bytes(32)),
                'ts' => (string) $template['ts_msg'],
            ]
        );
    }

    private function fixtureMessageId(string $directionCode): string
    {
        $msgId = $this->connection->fetchOne(
            'SELECT m.msg_id
             FROM message m
             JOIN lkp_direction d ON d.dir_id = m.direction
             WHERE m.conv_id = :convId AND d.code = :code AND m.deleted_at IS NULL
             ORDER BY m.ts_msg ASC, m.msg_id ASC
             LIMIT 1',
            ['convId' => self::CONV, 'code' => $directionCode]
        );
        self::assertIsString($msgId, sprintf('Fixture %s message must exist for the conversation', $directionCode));

        return $msgId;
    }

    /**
     * Two additional inbound messages between the fixture inbound (-1h) and
     * outbound (-30min) messages, sharing one ts_msg for the tiebreak check.
     */
    private function insertExtraInboundMessages(): void
    {
        $template = $this->connection->fetchAssociative(
            'SELECT channel_id, direction, ts_msg FROM message WHERE msg_id = :msgId',
            ['msgId' => $this->msgInbound]
        );
        self::assertIsArray($template);

        $ts = new \DateTimeImmutable((string) $template['ts_msg'], new \DateTimeZone('UTC'));
        $tsShared = $ts->modify('+5 minutes')->format('Y-m-d H:i:s');

        foreach ([self::MSG_B, self::MSG_C] as $msgId) {
            $this->connection->executeStatement(
                'INSERT INTO message (msg_id, conv_id, channel_id, direction, lang_detect, subject, body_text, headers, composite_hash, ts_msg, ts_ingest)
                 VALUES (:msgId, :convId, :channelId, :direction, :lang, :subject, :body, :headers, :hash, :ts, :ts)',
                [
                    'msgId' => $msgId,
                    'convId' => self::CONV,
                    'channelId' => $template['channel_id'],
                    'direction' => $template['direction'],
                    'lang' => 'en',
                    'subject' => 'Follow-up pressure',
                    'body' => 'Extra inbound body for read-model tests',
                    'headers' => '{}',
                    'hash' => bin2hex(random_bytes(32)),
                    'ts' => $tsShared,
                ]
            );
        }
    }

    /**
     * Three confirmed observations forming the ordered sequence
     * [SB-T001, SB-T003, SB-T001] plus one review-status observation.
     */
    private function seedObservations(): void
    {
        $rows = [
            [$this->msgInbound, 'SB-T001', 0.9, 'confirmed', 0, 8],
            [$this->msgInbound, 'SB-T005', 0.4, 'review', null, null],
            [self::MSG_B, 'SB-T003', 0.8, 'confirmed', 5, 15],
            [self::MSG_C, 'SB-T001', 0.7, 'confirmed', null, null],
        ];

        foreach ($rows as [$msgId, $code, $confidence, $status, $start, $end]) {
            self::assertTrue($this->upsert->upsert([
                'msg_id' => $msgId,
                'conv_id' => self::CONV,
                'ttp_id' => $this->ttpId($code),
                'confidence' => $confidence,
                'evidence' => sprintf('seeded evidence for %s', $code),
                'evidence_start' => $start,
                'evidence_end' => $end,
                'status' => $status,
                'taxonomy_version' => '1.0',
                'extraction_model' => 'test-model',
                'prompt_version' => 'v1',
            ]));
        }
    }

    /**
     * Five IOCs on the inbound message, all with an ioc_context row pointing
     * at the outbound message as stimulus: three enriched (2x DIRECT_REQUEST,
     * 1x URGENCY_PRESSURE) and two pending URGENCY_PRESSURE rows that must be
     * ignored by the modal-stimulus attribution.
     */
    private function seedIocsAndStimulusContexts(): void
    {
        $contexts = [
            ['iban', 'FR7699999999990000000000001', 'enriched', 'DIRECT_REQUEST'],
            ['url', 'hxxps://evil-read-model[.]test/pay', 'enriched', 'DIRECT_REQUEST'],
            ['phone', '+33699999999', 'enriched', 'URGENCY_PRESSURE'],
            ['email', 'mule@read-model.test', 'pending', 'URGENCY_PRESSURE'],
            ['btc_address', 'bc1qreadmodeltestaddress', 'pending', 'URGENCY_PRESSURE'],
        ];

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($contexts as $i => [$type, $valueNorm, $enrichmentStatus, $stimulusType]) {
            $indicatorId = sprintf('eeeeeeee-0000-4000-8000-%012d', $i + 1);
            $obsId = sprintf('bbbbbbbb-0000-4000-8000-%012d', $i + 1);

            $this->connection->executeStatement(
                'INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)
                 VALUES (:id, :type, :value, :valueNorm, :now, :now, 1, \'AMBER\', :now, :now)
                 ON CONFLICT (indicator_id) DO NOTHING',
                ['id' => $indicatorId, 'type' => $type, 'value' => $valueNorm, 'valueNorm' => $valueNorm, 'now' => $now]
            );

            $this->connection->executeStatement(
                'INSERT INTO observed_ioc (obs_id, msg_id, indicator_id, context_observation, ts_observed)
                 VALUES (:obsId, :msgId, :indicatorId, :context, :ts)',
                [
                    'obsId' => $obsId,
                    'msgId' => $this->msgInbound,
                    'indicatorId' => $indicatorId,
                    'context' => json_encode(['type' => $type, 'value' => $valueNorm, 'value_norm' => $valueNorm]),
                    // Deterministic revelation order for iocs_revealed.
                    'ts' => (new \DateTimeImmutable("+{$i} seconds"))->format('Y-m-d H:i:s'),
                ]
            );

            $this->connection->executeStatement(
                'INSERT INTO ioc_context (indicator_id, obs_id, stimulus_msg_id, stimulus_type, enrichment_status)
                 VALUES (:indicatorId, :obsId, :stimulusMsgId, :stimulusType, :status)',
                [
                    'indicatorId' => $indicatorId,
                    'obsId' => $obsId,
                    'stimulusMsgId' => $this->msgOutbound,
                    'stimulusType' => $stimulusType,
                    'status' => $enrichmentStatus,
                ]
            );
        }
    }

    private function linkCluster(string $clusterId, string $convId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO threat_actor_cluster (cluster_id, stix_id, name) VALUES (:id, :stix, :name)',
            ['id' => $clusterId, 'stix' => 'threat-actor--' . $clusterId, 'name' => 'TTP read-model test actor']
        );
        $this->connection->executeStatement(
            'INSERT INTO threat_actor_cluster_conversation (cluster_id, conv_id) VALUES (:id, :conv)',
            ['id' => $clusterId, 'conv' => $convId]
        );
    }

    /**
     * Clone the fixture conversation row (same scam type, persona, account)
     * into a fresh empty conversation for multi-conversation sequence seeds.
     */
    private function cloneConversation(string $convId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO conversation (conv_id, primary_channel_id, scam_type_id, account_id, status, score_risk, ts_first, ts_last, stix_id, created_at, updated_at, deleted_at, persona_id, engagement_duration_sec, turns_count, reward_value, delivery, tlp, secondary_scam_types)
             SELECT :newId, primary_channel_id, scam_type_id, account_id, status, score_risk, ts_first, ts_last, :stix, created_at, updated_at, NULL, persona_id, engagement_duration_sec, turns_count, reward_value, delivery, tlp, secondary_scam_types
             FROM conversation WHERE conv_id = :template',
            ['newId' => $convId, 'stix' => 'stix-' . $convId, 'template' => self::CONV]
        );
    }

    /**
     * Insert one inbound message into a conversation, cloning channel and
     * direction from the fixture inbound message and shifting its timestamp
     * by the given modifier (e.g. '+10 minutes').
     */
    private function insertInboundMessage(string $msgId, string $convId, string $tsModifier): void
    {
        $template = $this->connection->fetchAssociative(
            'SELECT channel_id, direction, ts_msg FROM message WHERE msg_id = :msgId',
            ['msgId' => $this->msgInbound]
        );
        self::assertIsArray($template);

        $ts = (new \DateTimeImmutable((string) $template['ts_msg'], new \DateTimeZone('UTC')))
            ->modify($tsModifier)
            ->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            'INSERT INTO message (msg_id, conv_id, channel_id, direction, lang_detect, subject, body_text, headers, composite_hash, ts_msg, ts_ingest)
             VALUES (:msgId, :convId, :channelId, :direction, :lang, :subject, :body, :headers, :hash, :ts, :ts)',
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
                'ts' => $ts,
            ]
        );
    }

    /**
     * One observation through the real upsert service (offsets stay NULL —
     * the sequence surfaces never read them).
     */
    private function seedObservationOn(string $convId, string $msgId, string $code, string $status = 'confirmed', float $confidence = 0.9): void
    {
        self::assertTrue($this->upsert->upsert([
            'msg_id' => $msgId,
            'conv_id' => $convId,
            'ttp_id' => $this->ttpId($code),
            'confidence' => $confidence,
            'evidence' => sprintf('seeded evidence for %s', $code),
            'evidence_start' => null,
            'evidence_end' => null,
            'status' => $status,
            'taxonomy_version' => '1.0',
            'extraction_model' => 'test-model',
            'prompt_version' => 'v1',
        ]));
    }

    private function ttpId(string $code): int
    {
        $ttpId = $this->connection->fetchOne('SELECT ttp_id FROM lkp_ttp WHERE code = :code', ['code' => $code]);
        self::assertNotFalse($ttpId, sprintf('lkp_ttp must be seeded with %s', $code));

        return (int) $ttpId;
    }
}

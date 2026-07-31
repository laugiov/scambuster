<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ttp;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP coverage of the two analytical matrices: the persona x TTP grid
 * (confirmed-only, validated join conversation.persona_id -> persona,
 * null-persona conversations excluded from the grid but counted) and the
 * stimulus x TTP grid (the revelation-message population: confirmed TTP
 * observations on messages that also carry an enriched, non-null
 * ioc_context.stimulus_type). Response shapes, the 401 unauthenticated path
 * (the harness has no ioc:read-less principal, so a 403 is not exercised) and
 * the offsets-only guarantee are asserted end-to-end. Every row is seeded
 * through DBAL inside the test and rolled back.
 */
final class TtpMatrixEndpointsTest extends WebTestCase
{
    private const AUTH = ['HTTP_AUTHORIZATION' => 'Bearer fake-jwt', 'CONTENT_TYPE' => 'application/json'];

    // Live fixture conversations and the inbound message used as a template.
    private const CONV_A = '00000000-0000-0000-0000-000000000002';
    private const CONV_B = '00000000-0000-0000-0000-000000000003';
    private const CONV_C = '00000000-0000-0000-0000-000000000005';

    private const MSG_TEMPLATE = '00000000-0000-0000-0000-000000000002';

    private KernelBrowser $client;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = static::getContainer()->get(Connection::class);

        // Clean slate on the test database (DAMA rolls everything back).
        $this->connection->executeStatement('DELETE FROM ttp_observation');
    }

    // ─── RBAC ──────────────────────────────────────────────────────────

    public function testEndpointsRequireAuthentication(): void
    {
        $unauthenticated = ['CONTENT_TYPE' => 'application/json'];

        $this->get('/api/v1/ttps/persona-matrix', $unauthenticated);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->get('/api/v1/ttps/stimulus-matrix', $unauthenticated);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // ─── persona x TTP matrix ──────────────────────────────────────────

    public function testPersonaMatrixConfirmedOnlyExcludesNullPersonaAndCountsIt(): void
    {
        [$p1, $p2] = $this->twoPersonas();

        // CONV_A → persona 1, CONV_B → persona 2, CONV_C → no persona.
        $this->setPersona(self::CONV_A, (int) $p1['persona_id']);
        $this->setPersona(self::CONV_B, (int) $p2['persona_id']);
        $this->setPersona(self::CONV_C, null);

        // CONV_A (persona 1): T001 on two messages + T003 once + a review row
        // (excluded). T001 → obs 2 / conv 1; T003 → obs 1 / conv 1.
        $a1 = 'abababab-0001-4000-8000-000000000001';
        $a2 = 'abababab-0001-4000-8000-000000000002';
        $this->seedMessage($a1, self::CONV_A, '2098-07-01 10:00:00');
        $this->seedMessage($a2, self::CONV_A, '2098-07-01 11:00:00');
        $this->seedObservation($a1, self::CONV_A, 'SB-T001', 'confirmed');
        $this->seedObservation($a1, self::CONV_A, 'SB-T003', 'confirmed');
        $this->seedObservation($a1, self::CONV_A, 'SB-T005', 'review');
        $this->seedObservation($a2, self::CONV_A, 'SB-T001', 'confirmed');

        // CONV_B (persona 2): T001 once.
        $b1 = 'abababab-0002-4000-8000-000000000001';
        $this->seedMessage($b1, self::CONV_B, '2098-07-02 10:00:00');
        $this->seedObservation($b1, self::CONV_B, 'SB-T001', 'confirmed');

        // CONV_C (no persona): T017 once — excluded from the grid, counted once.
        $c1 = 'abababab-0003-4000-8000-000000000001';
        $this->seedMessage($c1, self::CONV_C, '2098-07-03 10:00:00');
        $this->seedObservation($c1, self::CONV_C, 'SB-T017', 'confirmed');

        $this->get('/api/v1/ttps/persona-matrix');
        $this->assertResponseIsSuccessful();

        $data = $this->json();
        self::assertFalse($data['truncated']);
        self::assertSame(2, $data['total_personas']);
        self::assertSame(1, $data['null_persona_conversations']);

        // Only the two persona'd conversations form rows.
        $codes = array_column($data['personas'], 'code');
        sort($codes);
        $expected = [$p1['persona_code'], $p2['persona_code']];
        sort($expected);
        self::assertSame($expected, $codes);

        $byPersona = array_column($data['personas'], null, 'code');
        self::assertSame(1, $byPersona[$p1['persona_code']]['conversation_total']);
        self::assertSame(1, $byPersona[$p2['persona_code']]['conversation_total']);
        self::assertSame($p1['persona_label'], $byPersona[$p1['persona_code']]['label']);

        // Columns: the null-persona T017 never becomes a column; hook codes ordered.
        self::assertSame(['SB-T001', 'SB-T003'], array_column($data['ttps'], 'code'));

        // Cells: T001 confirmed twice in one conversation for persona 1.
        $cell = $this->cell($data['cells'], $p1['persona_code'], 'SB-T001', 'persona_code');
        self::assertSame(2, $cell['observation_count']);
        self::assertSame(1, $cell['conversation_count']);

        $cell = $this->cell($data['cells'], $p1['persona_code'], 'SB-T003', 'persona_code');
        self::assertSame(1, $cell['observation_count']);
        self::assertSame(1, $cell['conversation_count']);

        $cell = $this->cell($data['cells'], $p2['persona_code'], 'SB-T001', 'persona_code');
        self::assertSame(1, $cell['observation_count']);
        self::assertSame(1, $cell['conversation_count']);

        // The review row and the null-persona observation never surface.
        self::assertNull($this->maybeCell($data['cells'], $p1['persona_code'], 'SB-T005', 'persona_code'));
        $ttpCodes = array_column($data['cells'], 'ttp_code');
        self::assertNotContains('SB-T017', $ttpCodes);

        // Offsets-only guarantee.
        $raw = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('"evidence":', $raw);
        self::assertStringNotContainsString('seeded matrix evidence', $raw);
    }

    public function testPersonaMatrixIsEmptyWithoutConfirmedObservations(): void
    {
        // setUp wiped ttp_observation and nothing is seeded here.
        $this->get('/api/v1/ttps/persona-matrix');
        $this->assertResponseIsSuccessful();
        self::assertSame(
            [
                'personas' => [],
                'ttps' => [],
                'cells' => [],
                'truncated' => false,
                'total_personas' => 0,
                'null_persona_conversations' => 0,
            ],
            $this->json()
        );
    }

    // ─── stimulus x TTP matrix ─────────────────────────────────────────

    public function testStimulusMatrixScopesToRevelationMessagesWithEnrichedStimulus(): void
    {
        // M1: T001 confirmed + a review row, with two enriched stimulus
        // contexts (URGENCY_PRESSURE, DIRECT_REQUEST) → both cells on T001.
        $m1 = 'cdcdcdcd-0001-4000-8000-000000000001';
        $this->seedMessage($m1, self::CONV_A, '2098-07-04 10:00:00');
        $this->seedObservation($m1, self::CONV_A, 'SB-T001', 'confirmed');
        $this->seedObservation($m1, self::CONV_A, 'SB-T005', 'review');
        $this->seedStimulusContext($m1, '0001', 'URGENCY_PRESSURE', 'enriched');
        $this->seedStimulusContext($m1, '0002', 'DIRECT_REQUEST', 'enriched');

        // M2: T003 confirmed but its stimulus context is only structural (not
        // enriched) → the message is outside the population entirely.
        $m2 = 'cdcdcdcd-0002-4000-8000-000000000001';
        $this->seedMessage($m2, self::CONV_A, '2098-07-04 11:00:00');
        $this->seedObservation($m2, self::CONV_A, 'SB-T003', 'confirmed');
        $this->seedStimulusContext($m2, '0003', 'URGENCY_PRESSURE', 'structural');

        // M3: T009 confirmed with an enriched context but a NULL stimulus_type
        // → also outside the population.
        $m3 = 'cdcdcdcd-0003-4000-8000-000000000001';
        $this->seedMessage($m3, self::CONV_A, '2098-07-04 12:00:00');
        $this->seedObservation($m3, self::CONV_A, 'SB-T009', 'confirmed');
        $this->seedStimulusContext($m3, '0004', null, 'enriched');

        // M4: T001 confirmed with an enriched UNKNOWN stimulus (kept — the FE
        // decides whether to collapse UNKNOWN).
        $m4 = 'cdcdcdcd-0004-4000-8000-000000000001';
        $this->seedMessage($m4, self::CONV_A, '2098-07-04 13:00:00');
        $this->seedObservation($m4, self::CONV_A, 'SB-T001', 'confirmed');
        $this->seedStimulusContext($m4, '0005', 'UNKNOWN', 'enriched');

        $this->get('/api/v1/ttps/stimulus-matrix');
        $this->assertResponseIsSuccessful();

        $data = $this->json();

        // Population = distinct in-scope messages: M1 and M4 only.
        self::assertSame(2, $data['population_messages']);

        // UNKNOWN sinks to the last row regardless of volume; the rest keep the
        // widest-first order (equal volume here → name tiebreak).
        self::assertSame(['DIRECT_REQUEST', 'URGENCY_PRESSURE', 'UNKNOWN'], $data['stimuli']);

        // Only T001 clears the population; T003 (structural) and T009 (null
        // stimulus) never become columns, and the review row contributes nothing.
        self::assertSame(['SB-T001'], array_column($data['ttps'], 'code'));

        $cell = $this->cell($data['cells'], 'URGENCY_PRESSURE', 'SB-T001', 'stimulus_type');
        self::assertSame(1, $cell['message_count']);
        self::assertSame(1, $cell['conversation_count']);

        $cell = $this->cell($data['cells'], 'DIRECT_REQUEST', 'SB-T001', 'stimulus_type');
        self::assertSame(1, $cell['message_count']);

        $cell = $this->cell($data['cells'], 'UNKNOWN', 'SB-T001', 'stimulus_type');
        self::assertSame(1, $cell['message_count']);

        $columnCodes = array_column($data['cells'], 'ttp_code');
        self::assertNotContains('SB-T003', $columnCodes);
        self::assertNotContains('SB-T009', $columnCodes);

        $raw = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('"evidence":', $raw);
        self::assertStringNotContainsString('seeded matrix evidence', $raw);
    }

    public function testStimulusMatrixIsEmptyWithoutRevelationPopulation(): void
    {
        // A confirmed observation with NO enriched stimulus context at all: it
        // is absent from the matrix and the population is zero.
        $m1 = 'cdcdcdcd-00ff-4000-8000-000000000001';
        $this->seedMessage($m1, self::CONV_A, '2098-07-05 10:00:00');
        $this->seedObservation($m1, self::CONV_A, 'SB-T001', 'confirmed');

        $this->get('/api/v1/ttps/stimulus-matrix');
        $this->assertResponseIsSuccessful();
        self::assertSame(
            ['stimuli' => [], 'ttps' => [], 'cells' => [], 'population_messages' => 0],
            $this->json()
        );
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
     * Two distinct active personas from the reference fixtures.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function twoPersonas(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT persona_id, persona_code, persona_label FROM persona WHERE is_active = true ORDER BY persona_id ASC LIMIT 2'
        );
        self::assertCount(2, $rows, 'The reference fixtures must provide at least two personas');

        return [$rows[0], $rows[1]];
    }

    private function setPersona(string $convId, ?int $personaId): void
    {
        $this->connection->executeStatement(
            'UPDATE conversation SET persona_id = :personaId WHERE conv_id = :convId',
            ['personaId' => $personaId, 'convId' => $convId]
        );
    }

    /**
     * @param list<array<string, mixed>> $cells
     *
     * @return array<string, mixed>
     */
    private function cell(array $cells, string $rowKey, string $ttpCode, string $rowField): array
    {
        $found = $this->maybeCell($cells, $rowKey, $ttpCode, $rowField);
        self::assertNotNull($found, sprintf('Missing cell %s / %s', $rowKey, $ttpCode));

        return $found;
    }

    /**
     * @param list<array<string, mixed>> $cells
     *
     * @return array<string, mixed>|null
     */
    private function maybeCell(array $cells, string $rowKey, string $ttpCode, string $rowField): ?array
    {
        foreach ($cells as $cell) {
            if ($cell[$rowField] === $rowKey && $cell['ttp_code'] === $ttpCode) {
                return $cell;
            }
        }

        return null;
    }

    /**
     * Insert a message into a fixture conversation, cloning channel and
     * direction from the fixture template message.
     */
    private function seedMessage(string $msgId, string $convId, string $tsMsg): void
    {
        $template = $this->connection->fetchAssociative(
            'SELECT channel_id, direction FROM message WHERE msg_id = :msgId',
            ['msgId' => self::MSG_TEMPLATE]
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
                'subject' => 'Seeded matrix inbound',
                'body' => 'Seeded matrix body',
                'headers' => '{}',
                'hash' => bin2hex(random_bytes(32)),
                'ts' => $tsMsg,
            ]
        );
    }

    private function seedObservation(string $msgId, string $convId, string $code, string $status): void
    {
        $this->connection->executeStatement(
            "INSERT INTO ttp_observation (msg_id, conv_id, ttp_id, confidence, evidence, evidence_start, evidence_end, status, taxonomy_version, extraction_model, prompt_version)
             VALUES (:msgId, :convId, :ttpId, 0.9, 'seeded matrix evidence', NULL, NULL, :status, '1.0', 'seed-model', 'v1')",
            [
                'msgId' => $msgId,
                'convId' => $convId,
                'ttpId' => $this->ttpId($code),
                'status' => $status,
            ]
        );
    }

    /**
     * Seed one indicator + observed_ioc + ioc_context on a message. A null
     * stimulus type keeps stimulus_type NULL (outside the matrix population).
     */
    private function seedStimulusContext(string $msgId, string $suffix, ?string $stimulusType, string $enrichmentStatus): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $indicatorId = sprintf('efefefef-%s-4000-8000-000000000001', $suffix);
        $obsId = sprintf('bcbcbcbc-%s-4000-8000-000000000001', $suffix);
        $valueNorm = 'MATRIXVALUE' . $suffix;

        $this->connection->executeStatement(
            'INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)
             VALUES (:id, :type, :value, :valueNorm, :now, :now, 1, \'AMBER\', :now, :now)
             ON CONFLICT (indicator_id) DO NOTHING',
            ['id' => $indicatorId, 'type' => 'iban', 'value' => $valueNorm, 'valueNorm' => $valueNorm, 'now' => $now]
        );
        $this->connection->executeStatement(
            'INSERT INTO observed_ioc (obs_id, msg_id, indicator_id, context_observation, ts_observed)
             VALUES (:obsId, :msgId, :indicatorId, :context, :ts)',
            [
                'obsId' => $obsId,
                'msgId' => $msgId,
                'indicatorId' => $indicatorId,
                'context' => json_encode(['type' => 'iban', 'value' => $valueNorm, 'value_norm' => $valueNorm]),
                'ts' => $now,
            ]
        );
        $this->connection->executeStatement(
            'INSERT INTO ioc_context (indicator_id, obs_id, stimulus_type, enrichment_status)
             VALUES (:indicatorId, :obsId, :stimulusType, :status)',
            [
                'indicatorId' => $indicatorId,
                'obsId' => $obsId,
                'stimulusType' => $stimulusType,
                'status' => $enrichmentStatus,
            ]
        );
    }

    private function ttpId(string $code): int
    {
        $ttpId = $this->connection->fetchOne('SELECT ttp_id FROM lkp_ttp WHERE code = :code', ['code' => $code]);
        self::assertNotFalse($ttpId, sprintf('lkp_ttp must be seeded with %s', $code));

        return (int) $ttpId;
    }
}

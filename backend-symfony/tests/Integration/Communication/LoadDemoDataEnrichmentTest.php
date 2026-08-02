<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\UI\Console\LoadDemoDataCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Integration coverage of the demo IOC-context enrichment step.
 *
 * The demo has no LLM key, so ioc_context enrichment is seeded. This pins two
 * honesty/behaviour guarantees at the database level:
 *   1. every demo-enriched row is stamped enrichment_model='demo-seed', so it is
 *      distinguishable from real LLM enrichment (which carries the model name or
 *      NULL) — the STIX/TAXII provenance trail then reflects that;
 *   2. stimulus_type is derived per message (PASSIVE on first contact, otherwise
 *      the scam type's turn-keyed arc), matching deriveDemoStimulus().
 *
 * Two controlled structural rows are inserted over a minimal conversation ->
 * message -> indicator -> observed_ioc -> ioc_context chain. All writes are
 * rolled back per test by DAMA.
 */
final class LoadDemoDataEnrichmentTest extends KernelTestCase
{
    private const CONV_TEMPLATE = '00000000-0000-0000-0000-000000000002';
    private const CONV = 'aaaaaaaa-0000-4000-8000-0000000000e5';
    private const MSG_IN = 'bbbbbbbb-0000-4000-8000-0000000000e5';
    // Any UUID: stimulus_msg_id has no FK, it only signals "had a preceding outbound".
    private const STIMULUS_MSG = 'bbbbbbbb-0000-4000-8000-0000000000e6';

    private const IND_A = 'dddddddd-0000-4000-8000-0000000000e1';
    private const IND_B = 'dddddddd-0000-4000-8000-0000000000e2';
    private const OBS_A = 'eeeeeeee-0000-4000-8000-0000000000e1';
    private const OBS_B = 'eeeeeeee-0000-4000-8000-0000000000e2';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);

        $channelId = (int) $this->connection->fetchOne(
            'SELECT channel_id FROM message WHERE conv_id = :conv AND channel_id IS NOT NULL LIMIT 1',
            ['conv' => self::CONV_TEMPLATE]
        );
        self::assertGreaterThan(0, $channelId, 'The template conversation must have a message to borrow a channel from');
        $dirIn = (int) $this->connection->fetchOne("SELECT dir_id FROM lkp_direction WHERE code = 'in'");

        $this->connection->executeStatement(
            'INSERT INTO conversation (conv_id, primary_channel_id, scam_type_id, account_id, status, score_risk, ts_first, ts_last, stix_id, created_at, updated_at, deleted_at, persona_id, engagement_duration_sec, turns_count, reward_value, delivery, tlp, secondary_scam_types)
             SELECT :newId, primary_channel_id, scam_type_id, account_id, status, score_risk, ts_first, ts_last, :stix, created_at, updated_at, NULL, persona_id, engagement_duration_sec, turns_count, reward_value, delivery, tlp, secondary_scam_types
             FROM conversation WHERE conv_id = :template',
            ['newId' => self::CONV, 'stix' => 'stix-' . self::CONV, 'template' => self::CONV_TEMPLATE]
        );

        $this->connection->executeStatement(
            'INSERT INTO message (msg_id, conv_id, channel_id, direction, lang_detect, subject, body_text, headers, composite_hash, ts_msg, ts_ingest)
             VALUES (:msgId, :convId, :channelId, :direction, :lang, :subject, :body, :headers, :hash, NOW(), NOW())',
            [
                'msgId' => self::MSG_IN, 'convId' => self::CONV, 'channelId' => $channelId,
                'direction' => $dirIn, 'lang' => 'en', 'subject' => 'Demo', 'body' => 'Body with an iban.',
                'headers' => '{}', 'hash' => bin2hex(random_bytes(32)),
            ]
        );

        // Row A: has a preceding outbound, turn 3 -> INVOICE_FRAUD early arc slot.
        $this->seedStructuralRow(self::IND_A, self::OBS_A, 3, self::STIMULUS_MSG);
        // Row B: first contact (no preceding outbound), turn 1 -> PASSIVE.
        $this->seedStructuralRow(self::IND_B, self::OBS_B, 1, null);
    }

    public function testDemoEnrichedRowsAreStampedDemoSeed(): void
    {
        $this->runEnrichment();

        foreach ([self::OBS_A, self::OBS_B] as $obsId) {
            $row = $this->contextFor($obsId);
            self::assertNotFalse($row);
            self::assertSame('enriched', $row['enrichment_status'], 'The demo step must promote structural rows to enriched');
            self::assertSame('demo-seed', $row['enrichment_model'], 'Every demo-enriched row must carry the demo-seed provenance stamp');
        }
    }

    public function testStimulusTypeIsDerivedPerMessage(): void
    {
        $this->runEnrichment();

        $rowA = $this->contextFor(self::OBS_A);
        $rowB = $this->contextFor(self::OBS_B);
        self::assertNotFalse($rowA);
        self::assertNotFalse($rowB);

        // First contact is PASSIVE; a later elicited turn follows the scam arc.
        self::assertSame('PASSIVE', $rowB['stimulus_type'], 'A first-contact revelation must be PASSIVE');
        self::assertSame('DIRECT_REQUEST', $rowA['stimulus_type'], 'INVOICE_FRAUD turn 3 must follow the arc');

        // And both match the public derivation contract exactly.
        self::assertSame(
            LoadDemoDataCommand::deriveDemoStimulus('INVOICE_FRAUD', 1, false),
            $rowB['stimulus_type'],
        );
        self::assertSame(
            LoadDemoDataCommand::deriveDemoStimulus('INVOICE_FRAUD', 3, true),
            $rowA['stimulus_type'],
        );
    }

    private function seedStructuralRow(string $indicatorId, string $obsId, int $turn, ?string $stimulusMsgId): void
    {
        $this->connection->insert('indicator', [
            'indicator_id' => $indicatorId, 'type' => 'iban', 'value' => 'DE' . substr($obsId, -6),
            'value_norm' => 'de' . substr($obsId, -6), 'first_seen' => '2026-01-01 00:00:00',
            'last_seen' => '2026-01-01 00:00:00', 'occurrences' => 1, 'tlp' => 'AMBER',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->connection->insert('observed_ioc', [
            'obs_id' => $obsId, 'msg_id' => self::MSG_IN, 'indicator_id' => $indicatorId,
            'context_observation' => '{"source":"extraction"}', 'confidence_score' => 0.9,
            'ts_observed' => '2026-01-01 00:00:00',
        ]);
        $this->connection->executeStatement(
            "INSERT INTO ioc_context (indicator_id, obs_id, scam_type_code, revelation_turn, total_turns, stimulus_msg_id, enrichment_status)
             VALUES (:ind, :obs, 'INVOICE_FRAUD', :turn, 4, :stim, 'structural')",
            ['ind' => $indicatorId, 'obs' => $obsId, 'turn' => $turn, 'stim' => $stimulusMsgId]
        );
    }

    private function runEnrichment(): void
    {
        $command = new LoadDemoDataCommand($this->connection, '', null);
        $method = (new \ReflectionClass($command))->getMethod('applyDemoSemanticEnrichment');
        $method->setAccessible(true);
        $method->invoke($command, new SymfonyStyle(new ArrayInput([]), new NullOutput()));
    }

    /**
     * @return array<string, mixed>|false
     */
    private function contextFor(string $obsId): array|false
    {
        return $this->connection->fetchAssociative(
            'SELECT enrichment_status, enrichment_model, stimulus_type FROM ioc_context WHERE obs_id = :obs',
            ['obs' => $obsId]
        );
    }
}

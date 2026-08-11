<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ttp;

use App\Application\Communication\TtpMispTagProvider;
use App\Application\Ttp\TtpObservationUpsertService;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The MISP TTP tag provider emits one scambuster:ttp="SB-Txxx" tag per confirmed
 * TTP in a conversation, plus the MITRE ATT&CK galaxy tag ONLY when the TTP's
 * mitre-attack reference is in the verified allowlist (unmapped -> no tag, never
 * fabricated). Review-status observations never surface. No evidence is emitted.
 */
final class TtpMispTagProviderTest extends KernelTestCase
{
    private const CONV = '00000000-0000-0000-0000-000000000002';

    private Connection $connection;

    private TtpMispTagProvider $provider;

    private TtpObservationUpsertService $upsert;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->provider = self::getContainer()->get(TtpMispTagProvider::class);
        $this->upsert = new TtpObservationUpsertService($this->connection, new NullLogger());

        $this->connection->executeStatement('DELETE FROM ttp_observation');
    }

    public function testTagsForConfirmedTtpsWithGalaxyAllowlistAndFailSafe(): void
    {
        $msgId = $this->inboundMessageId();

        // Confirmed: SB-T001 (T1566 -> galaxy), SB-T002 (T1656 -> galaxy),
        // SB-T014 (T1598 -> NOT allowlisted -> no galaxy), SB-T006 (no ATT&CK ref).
        // Review: SB-T003 -> must never surface.
        $this->seed($msgId, 'SB-T001', 'confirmed');
        $this->seed($msgId, 'SB-T002', 'confirmed');
        $this->seed($msgId, 'SB-T014', 'confirmed');
        $this->seed($msgId, 'SB-T006', 'confirmed');
        $this->seed($msgId, 'SB-T003', 'review');

        $tags = $this->provider->tagsForConversation(self::CONV);

        // First-party TTP tags for every confirmed code.
        self::assertContains('scambuster:ttp="SB-T001"', $tags);
        self::assertContains('scambuster:ttp="SB-T002"', $tags);
        self::assertContains('scambuster:ttp="SB-T014"', $tags);
        self::assertContains('scambuster:ttp="SB-T006"', $tags);

        // MITRE galaxy tags only where the id is in the verified allowlist.
        self::assertContains('misp-galaxy:mitre-attack-pattern="Phishing - T1566"', $tags);
        self::assertContains('misp-galaxy:mitre-attack-pattern="Impersonation - T1656"', $tags);

        // T1598 is not allowlisted -> fail-safe: no galaxy tag mentioning it.
        foreach ($tags as $tag) {
            self::assertStringNotContainsString('T1598', $tag, 'Unmapped ATT&CK id must never produce a galaxy tag.');
        }

        // Review-status observations are excluded.
        self::assertNotContains('scambuster:ttp="SB-T003"', $tags);

        // Tags are deduplicated.
        self::assertSame(array_values(array_unique($tags)), $tags);
    }

    public function testNoConfirmedTtpsYieldsNoTags(): void
    {
        $this->seed($this->inboundMessageId(), 'SB-T001', 'review');

        self::assertSame([], $this->provider->tagsForConversation(self::CONV));
    }

    /**
     * Spec 002 FR-005. MITRE F3 references may reach `external_refs` and the STIX
     * export, but they must NOT become MISP tags: a galaxy tag has to resolve in a
     * consumer's instance, and no public F3 MISP galaxy exists. Any string this
     * project invented would resolve nowhere.
     *
     * The provider filters on `mitre-attack` and this test pins that, so a later
     * change to the source-name handling cannot start fabricating F3 tags by
     * accident — the same fail-safe the unmapped-ATT&CK-id case already has.
     */
    public function testF3ReferencesNeverBecomeMispTags(): void
    {
        $this->connection->executeStatement(
            "UPDATE lkp_ttp
             SET external_refs = :refs
             WHERE code = 'SB-T001'",
            ['refs' => json_encode([
                ['source_name' => 'mitre-attack', 'external_id' => 'T1566'],
                ['source_name' => 'mitre-f3', 'external_id' => 'F1020.001'],
            ], JSON_THROW_ON_ERROR)],
        );

        $this->seed($this->inboundMessageId(), 'SB-T001', 'confirmed');

        $tags = $this->provider->tagsForConversation(self::CONV);

        // The first-party tag and the verified ATT&CK galaxy tag still ship.
        self::assertContains('scambuster:ttp="SB-T001"', $tags);
        self::assertContains('misp-galaxy:mitre-attack-pattern="Phishing - T1566"', $tags);

        foreach ($tags as $tag) {
            self::assertStringNotContainsString('F1020', $tag, 'No MISP tag may carry an F3 technique id.');
            self::assertStringNotContainsString('f3', strtolower($tag), 'No MISP tag may name the F3 knowledge base.');
        }
    }

    private function inboundMessageId(): string
    {
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

        return $msgId;
    }

    private function seed(string $msgId, string $code, string $status): void
    {
        $ttpId = $this->connection->fetchOne('SELECT ttp_id FROM lkp_ttp WHERE code = :code', ['code' => $code]);
        self::assertNotFalse($ttpId, "lkp_ttp must be seeded with {$code}");

        self::assertTrue($this->upsert->upsert([
            'msg_id' => $msgId,
            'conv_id' => self::CONV,
            'ttp_id' => (int) $ttpId,
            'confidence' => 0.9,
            'evidence' => 'seeded evidence for ' . $code,
            'evidence_start' => 0,
            'evidence_end' => 4,
            'status' => $status,
            'taxonomy_version' => '1.0',
            'extraction_model' => 'test-model',
            'prompt_version' => 'v1',
        ]));
    }
}

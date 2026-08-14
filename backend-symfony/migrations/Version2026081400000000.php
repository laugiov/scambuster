<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill MITRE F3 references onto the TTP taxonomy rows (taxonomy v1.1).
 *
 * Version2026073000000000 seeds lkp_ttp with ON CONFLICT (code) DO NOTHING, so
 * editing its SEEDS constant updates nothing on a database that already ran it.
 * The STIX export reads external_refs from the table, not from the seed, so
 * without this data migration production would keep exporting attack-patterns
 * with no F3 reference while the whole test suite stays green.
 * TtpTaxonomyConsistencyTest::testExternalRefsHaveABackfillMigration keeps this
 * file in step with the seed, so the next mapping cannot repeat that mistake.
 *
 * All 27 codes are written, not just the 15 that carry a reference. Writing only
 * the non-empty ones would leave a reference the mapping has since dropped in
 * place forever on an already-migrated database — the same silent-drift bug this
 * migration exists to fix, one level down.
 *
 * Values are generated from the committed artifact
 * (config/standards/taxonomy-v1.1.json) and match the seed exactly. An empty array
 * records that the mapping found no F3 match, not that no F3 equivalent exists.
 * See docs/standards-track.md.
 *
 * lkp_ttp is a reference table owned by migrations: this overwrites external_refs
 * unconditionally, so a row hand-edited by an operator is reset. That is intended.
 */
final class Version2026081400000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill MITRE F3 external_refs on lkp_ttp (seed migration used ON CONFLICT DO NOTHING)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-attack","external_id":"T1566"},{"source_name":"mitre-f3","external_id":"T1598"}]\'::jsonb WHERE code = \'SB-T001\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-attack","external_id":"T1656"},{"source_name":"mitre-f3","external_id":"F1032"}]\'::jsonb WHERE code = \'SB-T002\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-attack","external_id":"T1656"},{"source_name":"mitre-f3","external_id":"T1672"},{"source_name":"mitre-f3","external_id":"F1036"}]\'::jsonb WHERE code = \'SB-T003\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-attack","external_id":"T1566.001"},{"source_name":"mitre-attack","external_id":"T1566.002"},{"source_name":"mitre-f3","external_id":"T1660"},{"source_name":"mitre-f3","external_id":"T1598"}]\'::jsonb WHERE code = \'SB-T004\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-f3","external_id":"F1020.001"},{"source_name":"mitre-f3","external_id":"F1027"}]\'::jsonb WHERE code = \'SB-T005\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T006\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T007\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T008\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T009\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-f3","external_id":"T1585"}]\'::jsonb WHERE code = \'SB-T010\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T011\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-f3","external_id":"F1047"},{"source_name":"mitre-f3","external_id":"F1036"}]\'::jsonb WHERE code = \'SB-T012\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-f3","external_id":"F1036"},{"source_name":"mitre-f3","external_id":"F1025"}]\'::jsonb WHERE code = \'SB-T013\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-attack","external_id":"T1598"},{"source_name":"mitre-f3","external_id":"T1598"},{"source_name":"mitre-f3","external_id":"F1029"}]\'::jsonb WHERE code = \'SB-T014\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-f3","external_id":"F1014"},{"source_name":"mitre-f3","external_id":"F1020.001"}]\'::jsonb WHERE code = \'SB-T015\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T016\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-f3","external_id":"T1598"},{"source_name":"mitre-f3","external_id":"F1036"}]\'::jsonb WHERE code = \'SB-T017\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T018\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-f3","external_id":"F1020.001"}]\'::jsonb WHERE code = \'SB-T019\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T020\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T021\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-f3","external_id":"F1036"}]\'::jsonb WHERE code = \'SB-T022\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-f3","external_id":"T1598"}]\'::jsonb WHERE code = \'SB-T023\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T024\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T025\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T026\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-f3","external_id":"F1020"}]\'::jsonb WHERE code = \'SB-T027\'');
    }

    public function down(Schema $schema): void
    {
        // Restore the mitre-attack-only references; the F3 mapping is dropped.
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-attack","external_id":"T1566"}]\'::jsonb WHERE code = \'SB-T001\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-attack","external_id":"T1656"}]\'::jsonb WHERE code = \'SB-T002\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-attack","external_id":"T1656"}]\'::jsonb WHERE code = \'SB-T003\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-attack","external_id":"T1566.001"},{"source_name":"mitre-attack","external_id":"T1566.002"}]\'::jsonb WHERE code = \'SB-T004\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T005\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T006\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T007\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T008\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T009\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T010\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T011\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T012\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T013\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[{"source_name":"mitre-attack","external_id":"T1598"}]\'::jsonb WHERE code = \'SB-T014\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T015\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T016\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T017\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T018\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T019\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T020\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T021\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T022\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T023\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T024\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T025\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T026\'');
        $this->addSql('UPDATE lkp_ttp SET external_refs = \'[]\'::jsonb WHERE code = \'SB-T027\'');
    }
}

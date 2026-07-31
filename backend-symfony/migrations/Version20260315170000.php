<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove duplicate ROMANCE_SCAM scam type.
 * Migrates all FK references to ROMANCE before deleting ROMANCE_SCAM.
 */
final class Version20260315170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove duplicate ROMANCE_SCAM scam type, migrate references to ROMANCE';
    }

    public function up(Schema $schema): void
    {
        // Step 1: Update all FK references from ROMANCE_SCAM to ROMANCE
        $this->addSql(<<<'SQL'
            UPDATE conversation SET scam_type_id = (
                SELECT scam_type_id FROM lkp_scam_type WHERE code = 'ROMANCE'
            ) WHERE scam_type_id = (
                SELECT scam_type_id FROM lkp_scam_type WHERE code = 'ROMANCE_SCAM'
            )
        SQL);

        $this->addSql(<<<'SQL'
            DELETE FROM persona_performance_stats WHERE scam_type_id = (
                SELECT scam_type_id FROM lkp_scam_type WHERE code = 'ROMANCE_SCAM'
            )
        SQL);

        $this->addSql(<<<'SQL'
            DELETE FROM scam_type_persona WHERE scam_type_id = (
                SELECT scam_type_id FROM lkp_scam_type WHERE code = 'ROMANCE_SCAM'
            ) AND persona_id IN (
                SELECT persona_id FROM scam_type_persona WHERE scam_type_id = (
                    SELECT scam_type_id FROM lkp_scam_type WHERE code = 'ROMANCE'
                )
            )
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE scam_type_persona SET scam_type_id = (
                SELECT scam_type_id FROM lkp_scam_type WHERE code = 'ROMANCE'
            ) WHERE scam_type_id = (
                SELECT scam_type_id FROM lkp_scam_type WHERE code = 'ROMANCE_SCAM'
            )
        SQL);

        // Step 2: Delete the duplicate
        $this->addSql("DELETE FROM lkp_scam_type WHERE code = 'ROMANCE_SCAM'");
    }

    public function down(Schema $schema): void
    {
        // Re-create the duplicate row
        $this->addSql(<<<'SQL'
            INSERT INTO lkp_scam_type (scam_type_id, code, label_en, label_fr, attack_id)
            VALUES (DEFAULT, 'ROMANCE_SCAM', 'Romance Scam', 'Arnaque sentimentale', NULL)
        SQL);
    }
}

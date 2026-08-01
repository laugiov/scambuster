<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add missing scam types to lkp_scam_type (CT-4: complete MISP/ATT&CK mapping for 13 types).
 *
 * Adds: PHISH_MALWARE, CEO_FRAUD, INVESTMENT, LOTTERY, JOB_OFFER, CHARITY, ADVANCE_FEE_419
 * Also updates existing types with missing MISP taxonomy and ATT&CK technique.
 */
final class Version20260325151254 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add 7 missing scam types + update MISP/ATT&CK mappings for all 13 types';
    }

    public function up(Schema $schema): void
    {
        $now = date('Y-m-d H:i:s');

        // Insert 7 new scam types with explicit IDs (starting at 9)
        $this->addSql("INSERT INTO lkp_scam_type (scam_type_id, code, label, description, misp_taxonomy, attck_technique, active, created_at, updated_at) VALUES (9, 'PHISH_MALWARE', 'Phishing avec malware', 'Email avec piece jointe ou lien malveillant', 'rsit:fraud=\"phishing\"', 'T1566.001', true, '$now', '$now') ON CONFLICT (code) DO NOTHING");
        $this->addSql("INSERT INTO lkp_scam_type (scam_type_id, code, label, description, misp_taxonomy, attck_technique, active, created_at, updated_at) VALUES (10, 'CEO_FRAUD', 'Fraude au president', 'Usurpation identite dirigeant pour obtenir un virement', 'rsit:fraud=\"fraud\"', 'T1534', true, '$now', '$now') ON CONFLICT (code) DO NOTHING");
        $this->addSql("INSERT INTO lkp_scam_type (scam_type_id, code, label, description, misp_taxonomy, attck_technique, active, created_at, updated_at) VALUES (11, 'INVESTMENT', 'Arnaque investissement', 'Faux placement financier, crypto, forex', 'rsit:fraud=\"scam\"', NULL, true, '$now', '$now') ON CONFLICT (code) DO NOTHING");
        $this->addSql("INSERT INTO lkp_scam_type (scam_type_id, code, label, description, misp_taxonomy, attck_technique, active, created_at, updated_at) VALUES (12, 'LOTTERY', 'Fausse loterie', 'Gain fictif necessitant des frais de deblocage', 'rsit:fraud=\"scam\"', NULL, true, '$now', '$now') ON CONFLICT (code) DO NOTHING");
        $this->addSql("INSERT INTO lkp_scam_type (scam_type_id, code, label, description, misp_taxonomy, attck_technique, active, created_at, updated_at) VALUES (13, 'JOB_OFFER', 'Fausse offre emploi', 'Offre emploi frauduleuse visant a collecter des informations', 'rsit:fraud=\"scam\"', 'T1566.003', true, '$now', '$now') ON CONFLICT (code) DO NOTHING");
        $this->addSql("INSERT INTO lkp_scam_type (scam_type_id, code, label, description, misp_taxonomy, attck_technique, active, created_at, updated_at) VALUES (14, 'CHARITY', 'Faux appel aux dons', 'Collecte de dons pour une cause fictive ou detournee', 'rsit:fraud=\"scam\"', NULL, true, '$now', '$now') ON CONFLICT (code) DO NOTHING");
        $this->addSql("INSERT INTO lkp_scam_type (scam_type_id, code, label, description, misp_taxonomy, attck_technique, active, created_at, updated_at) VALUES (15, 'ADVANCE_FEE_419', 'Fraude 419 avance de frais', 'Heritage fictif, frais de deblocage', 'rsit:fraud=\"419_scam\"', NULL, true, '$now', '$now') ON CONFLICT (code) DO NOTHING");

        // Update existing types with missing mappings
        $this->addSql("UPDATE lkp_scam_type SET attck_technique = 'T1534' WHERE code = 'INVOICE_FRAUD' AND (attck_technique IS NULL OR attck_technique = '')");
        $this->addSql("UPDATE lkp_scam_type SET attck_technique = 'T1566' WHERE code = 'ROMANCE' AND (attck_technique IS NULL OR attck_technique = '')");
        $this->addSql("UPDATE lkp_scam_type SET misp_taxonomy = 'rsit:fraud=\"other\"' WHERE code = 'UNKNOWN' AND (misp_taxonomy IS NULL OR misp_taxonomy = '')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM lkp_scam_type WHERE code IN ('PHISH_MALWARE', 'CEO_FRAUD', 'INVESTMENT', 'LOTTERY', 'JOB_OFFER', 'CHARITY', 'ADVANCE_FEE_419')");
        $this->addSql("UPDATE lkp_scam_type SET attck_technique = NULL WHERE code = 'INVOICE_FRAUD'");
        $this->addSql("UPDATE lkp_scam_type SET attck_technique = NULL WHERE code = 'ROMANCE'");
    }
}
